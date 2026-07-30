<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\BoardTerm;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;

/**
 * Aggregates for the Reports screens (Overview / Tasks / Activity).
 *
 * Every payload here matches the prop shapes the report widgets were written
 * against, so the screens hand a response straight to their widgets.
 *
 * Date range semantics — a task belongs to a range when it was *touched* in it:
 * created, completed or updated inside the window. `overdue` is the one
 * deliberate exception: it is always a reading of "overdue right now", because a
 * historical overdue count is not actionable.
 *
 * See docs/plan/reports-real-data.md.
 */
class ReportService
{
    /**
     * Priority buckets, in render order. The empty key covers tasks with no
     * priority set (stored as NULL or '').
     */
    const PRIORITY_BUCKETS = [
        'urgent' => 'Urgent',
        'high'   => 'High',
        'medium' => 'Medium',
        'low'    => 'Low',
        'none'   => 'None',
    ];

    /**
     * Report key => [column, [actions]] against `fbs_activities`.
     *
     * Columns/actions are the literals ActivityHandler writes; `null` actions
     * means "any action on this column".
     */
    const ACTIVITY_TYPES = [
        'task_stage_updated'    => ['label' => 'Stage Change', 'icon' => 'task-stage',   'column' => 'stage',      'actions' => null],
        'comment_created'       => ['label' => 'Comments',     'icon' => 'comment-line', 'column' => 'comment',    'actions' => ['added']],
        'task_created'          => ['label' => 'Task Created', 'icon' => 'plus',         'column' => 'task',       'actions' => ['created']],
        'subtask_added'         => ['label' => 'Subtasks',     'icon' => 'subtask',      'column' => 'subtask',    'actions' => ['added', 'cloned']],
        'task_attachment_added' => ['label' => 'Attachments',  'icon' => 'paper-clip',   'column' => 'attachment', 'actions' => ['added']],
        'task_label'            => ['label' => 'Labels',       'icon' => 'label',        'column' => 'label',      'actions' => null],
        'task_due_date_changed' => ['label' => 'Due Dates',    'icon' => 'date',         'column' => 'Due Date',   'actions' => null],
    ];

    /**
     * How many rows the "top N" panels return.
     */
    const TOP_ROWS = 8;

    /**
     * Daily roadmap charts must remain bounded to avoid oversized responses.
     */
    const MAX_ROADMAP_REPORT_DAYS = 366;

    /**
     * Resolves the shared `board_id` / `start_date` / `end_date` parameters into
     * a scope every report method runs against.
     *
     * A board the user cannot access simply yields an empty board list, so the
     * screens render their empty states instead of an error.
     *
     * @param array $filters ['board_id' => int|null, 'start_date' => string|null, 'end_date' => string|null]
     * @return array{boardIds: array, start: string, end: string}
     */
    public function resolveScope($filters = [])
    {
        return $this->resolveBoardScope($filters, 'to-do');
    }

    /**
     * Resolves the shared filters against accessible roadmap boards.
     *
     * @param array $filters
     * @return array{boardIds: array, start: string, end: string}
     */
    public function resolveRoadmapScope($filters = [])
    {
        $scope = $this->resolveBoardScope($filters, 'roadmap');

        $this->validateRoadmapDateRange($scope);

        return $scope;
    }

    /**
     * Resolves an accessible board scope without allowing an invalid requested
     * board to widen back to every board of the same type.
     */
    private function resolveBoardScope(array $filters, $boardType)
    {
        $boardId = !empty($filters['board_id']) ? (int) $filters['board_id'] : null;
        $allowedIds = PermissionManager::getBoardIdsForUser(get_current_user_id(), $boardId);

        // getBoardIdsForUser() only honours $boardId for admins; for everyone
        // else it returns every board they belong to. Narrowing here is what
        // keeps a board_id the user cannot see from widening the report to all
        // of their boards.
        if ($boardId) {
            $allowedIds = in_array($boardId, array_map('intval', $allowedIds), true)
                ? [$boardId]
                : [];
        }

        $boardIds = [];
        if ($allowedIds) {
            $boardIds = Board::whereIn('id', $allowedIds)
                ->where('type', $boardType)
                ->whereNull('archived_at')
                ->excludeTemplates()
                ->pluck('id')
                ->toArray();
        }

        return [
            'boardIds' => array_map('intval', $boardIds),
            'start'    => $this->startOfDay($this->sanitizeDate($filters['start_date'] ?? null), -6),
            'end'      => $this->endOfDay($this->sanitizeDate($filters['end_date'] ?? null)),
        ];
    }

    /**
     * Reports → Overview.
     */
    public function getOverviewReport(array $scope)
    {
        if (!$scope['boardIds']) {
            return $this->emptyOverview();
        }

        $completed = (int) $this->rangeTasks($scope)
            ->where('status', 'closed')
            ->whereBetween('last_completed_at', [$scope['start'], $scope['end']])
            ->count();

        $total = (int) $this->rangeTasks($scope)->count();
        $open = (int) $this->rangeTasks($scope)->where('status', 'open')->count();
        $overdue = (int) $this->boardTasks($scope)->overdue()->count();

        // A task lands in the range because it was touched, so a range can hold
        // tasks that were already finished before it started. Those are their
        // own slice rather than being dropped, which is what makes the three
        // slices sum to the Total tile.
        $closedEarlier = max(0, $total - $completed - $open);

        return [
            'stats'            => [
                'total'         => $total,
                'completed'     => $completed,
                'overdue'       => $overdue,
                'activeMembers' => $this->countActiveMembers($scope),
                'estimatedTime' => $this->formatMinutes($this->sumEstimatedMinutes($scope)),
            ],
            'tasksByBoard'     => $this->getTasksByBoard($scope),
            'priority'         => $this->getPriorityDistribution($scope),
            'completion'       => [
                ['key' => 'completed', 'label' => 'Completed', 'value' => $completed],
                ['key' => 'incomplete', 'label' => 'Still Open', 'value' => $open],
                ['key' => 'completed_earlier', 'label' => 'Completed Earlier', 'value' => $closedEarlier],
            ],
            'dueDate'          => $this->getDueDateDistribution($scope),
            'assigneeWorkload' => $this->getAssigneeWorkload($scope),
        ];
    }

    /**
     * Reports → Tasks.
     */
    public function getTasksReport(array $scope)
    {
        if (!$scope['boardIds']) {
            return $this->emptyTasks();
        }

        return [
            'byStage'           => $this->getTasksByStage($scope),
            'byAssignee'        => $this->getTasksByAssignee($scope),
            'byLabel'           => $this->getTasksByLabel($scope),
            'priority'          => $this->getPriorityDistribution($scope),
            'recentlyCompleted' => $this->getRecentlyCompleted($scope),
        ];
    }

    /**
     * Reports → Activity.
     *
     * The tiles and the by-type rows are two readings of one grouped query, so
     * they can never disagree.
     */
    public function getActivityReport(array $scope)
    {
        if (!$scope['boardIds']) {
            return $this->emptyActivity();
        }

        $counts = $this->countActivitiesByType($scope);

        $byType = [];
        foreach (self::ACTIVITY_TYPES as $key => $type) {
            $byType[] = [
                'key'   => $key,
                'label' => $type['label'],
                'icon'  => $type['icon'],
                'value' => $counts[$key] ?? 0,
            ];
        }

        return [
            'stats'  => [
                'tasksCreated'     => $counts['task_created'] ?? 0,
                'stageChanged'     => $counts['task_stage_updated'] ?? 0,
                'commentsAdded'    => $counts['comment_created'] ?? 0,
                'subtasksCreated'  => $counts['subtask_added'] ?? 0,
                'attachmentsAdded' => $counts['task_attachment_added'] ?? 0,
            ],
            'byUser' => $this->getActivityByUser($scope),
            'byType' => $byType,
            'recent' => $this->getRecentActivities($scope),
        ];
    }

    /**
     * Reports → Roadmap.
     */
    public function getRoadmapReport(array $scope)
    {
        if (!$scope['boardIds']) {
            return $this->emptyRoadmap();
        }

        return [
            'stats'        => $this->getRoadmapStats($scope),
            'submissions'  => $this->getRoadmapSubmissions($scope),
            'byStage'      => $this->getRoadmapIdeasByStage($scope),
            'popularIdeas' => $this->getPopularRoadmapIdeas($scope),
            'bySource'     => $this->getRoadmapIdeasBySource($scope),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Roadmap pieces
    |--------------------------------------------------------------------------
    */

    private function getRoadmapStats(array $scope)
    {
        return [
            'totalIdeas'     => (int) $this->roadmapIdeas($scope)->count(),
            'submittedIdeas' => (int) $this->rangeRoadmapIdeas($scope)->count(),
            'publicIdeas'    => (int) $this->rangeRoadmapIdeas($scope)
                ->where('source', 'page')
                ->count(),
            'completedIdeas' => (int) $this->roadmapIdeas($scope)
                ->whereBetween('last_completed_at', [$scope['start'], $scope['end']])
                ->count(),
        ];
    }

    /**
     * Daily submissions include zero-value days so the line never skips dates.
     */
    private function getRoadmapSubmissions(array $scope)
    {
        $rows = $this->rangeRoadmapIdeas($scope)
            ->selectRaw('DATE(created_at) as report_date, COUNT(*) as total')
            ->groupBy('report_date')
            ->orderBy('report_date', 'ASC')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->report_date] = (int) $row->total;
        }

        $start = new \DateTime(substr($scope['start'], 0, 10));
        $end = new \DateTime(substr($scope['end'], 0, 10));
        $end->modify('+1 day');

        $submissions = [];
        for ($date = $start; $date < $end; $date->modify('+1 day')) {
            $dateKey = $date->format('Y-m-d');
            $submissions[] = [
                'date'  => $dateKey,
                'value' => $counts[$dateKey] ?? 0,
            ];
        }

        return $submissions;
    }

    /**
     * Same-named stages are one row in the all-roadmaps view.
     */
    private function getRoadmapIdeasByStage(array $scope)
    {
        $rows = $this->roadmapIdeas($scope)
            ->selectRaw('stage_id, COUNT(*) as total')
            ->groupBy('stage_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->stage_id] = (int) $row->total;
        }

        if (!$counts) {
            return [];
        }

        $stages = BoardTerm::whereIn('id', array_keys($counts))
            ->where('type', 'stage')
            ->whereNull('archived_at')
            ->orderBy('position', 'ASC')
            ->get();

        $merged = [];
        foreach ($stages as $stage) {
            $key = strtolower($stage->title);

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'label'    => $stage->title,
                    'value'    => 0,
                    'position' => (float) $stage->position,
                ];
            }

            $merged[$key]['value'] += $counts[(int) $stage->id] ?? 0;
            $merged[$key]['position'] = min(
                $merged[$key]['position'],
                (float) $stage->position
            );
        }

        $items = array_values($merged);
        usort($items, function ($first, $second) {
            if ($first['position'] === $second['position']) {
                return strcasecmp($first['label'], $second['label']);
            }

            return $first['position'] <=> $second['position'];
        });

        return array_map(function ($item) {
            unset($item['position']);
            return $item;
        }, $items);
    }

    private function getPopularRoadmapIdeas(array $scope)
    {
        $voteSelect = (new TaskService())->getIdeaVoteStatisticsSelect();

        $rows = $this->rangeRoadmapIdeas($scope)
            ->select(
                'fbs_tasks.id',
                'fbs_tasks.slug',
                'fbs_tasks.title',
                'fbs_tasks.board_id'
            )
            ->selectRaw(
                "{$voteSelect} as upvotes,"
                . ' COALESCE(comments_count, 0) as comments,'
                . " ({$voteSelect} + COALESCE(comments_count, 0)) as popularity"
            )
            ->orderBy('popularity', 'DESC')
            ->orderBy('upvotes', 'DESC')
            ->orderBy('fbs_tasks.id', 'DESC')
            ->limit(10)
            ->get();

        $boardIds = [];
        foreach ($rows as $row) {
            $boardIds[] = (int) $row->board_id;
        }

        $boardTitles = $boardIds
            ? Board::whereIn('id', array_unique($boardIds))->pluck('title', 'id')->toArray()
            : [];

        $ideas = [];
        foreach ($rows as $row) {
            $boardId = (int) $row->board_id;
            $ideas[] = [
                'id'         => (int) $row->id,
                'slug'       => $row->slug,
                'title'      => $row->title,
                'boardId'    => $boardId,
                'board'      => $boardTitles[$boardId] ?? __('Untitled', 'fluent-boards'),
                'upvotes'    => (int) $row->upvotes,
                'comments'   => (int) $row->comments,
                'popularity' => (int) $row->popularity,
            ];
        }

        return $ideas;
    }

    private function getRoadmapIdeasBySource(array $scope)
    {
        $rows = $this->rangeRoadmapIdeas($scope)
            ->selectRaw(
                "CASE"
                . " WHEN source = 'page' THEN 'page'"
                . " WHEN source IS NULL OR source = '' OR source = 'web' THEN 'web'"
                . " ELSE 'other' END as source_key,"
                . ' COUNT(*) as total'
            )
            ->groupBy('source_key')
            ->get();

        $counts = ['page' => 0, 'web' => 0, 'other' => 0];
        foreach ($rows as $row) {
            $key = isset($counts[$row->source_key]) ? $row->source_key : 'other';
            $counts[$key] += (int) $row->total;
        }

        return [
            ['key' => 'page', 'label' => 'Public Page', 'value' => $counts['page'], 'colorKey' => 'primary'],
            ['key' => 'web', 'label' => 'Admin / Web', 'value' => $counts['web'], 'colorKey' => 'success'],
            ['key' => 'other', 'label' => 'Other', 'value' => $counts['other'], 'colorKey' => 'neutral'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Overview pieces
    |--------------------------------------------------------------------------
    */

    private function getTasksByBoard(array $scope)
    {
        $rows = $this->rangeTasks($scope)
            ->selectRaw('board_id, COUNT(*) as total')
            ->groupBy('board_id')
            ->orderBy('total', 'DESC')
            ->limit(self::TOP_ROWS)
            ->get();

        $boardIds = [];
        foreach ($rows as $row) {
            $boardIds[] = (int) $row->board_id;
        }

        $titles = $boardIds
            ? Board::whereIn('id', $boardIds)->pluck('title', 'id')->toArray()
            : [];

        $boards = [];
        foreach ($rows as $row) {
            $id = (int) $row->board_id;
            $boards[] = [
                'id'    => $id,
                'title' => $titles[$id] ?? __('Untitled', 'fluent-boards'),
                'total' => (int) $row->total,
            ];
        }

        return $boards;
    }

    private function getPriorityDistribution(array $scope)
    {
        $rows = $this->rangeTasks($scope)
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->get();

        return $this->formatPriorityRows($rows);
    }

    /**
     * Normalizes grouped priority rows into the fixed widget bucket order.
     */
    private function formatPriorityRows($rows)
    {
        $counts = array_fill_keys(array_keys(self::PRIORITY_BUCKETS), 0);
        foreach ($rows as $row) {
            $key = $row->priority ? strtolower($row->priority) : 'none';
            if (!isset($counts[$key])) {
                $key = 'none';
            }
            $counts[$key] += (int) $row->total;
        }

        $items = [];
        foreach (self::PRIORITY_BUCKETS as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'value' => $counts[$key]];
        }

        return [
            'total' => array_sum($counts),
            'items' => $items,
        ];
    }

    /**
     * Due-date buckets over the open tasks in range. One grouped pass rather
     * than five counts, so the buckets always sum to the same population.
     */
    private function getDueDateDistribution(array $scope)
    {
        $todayStart = $this->sqlDate(gmdate('Y-m-d 00:00:00', current_time('timestamp')));
        $todayEnd = $this->sqlDate(gmdate('Y-m-d 23:59:59', current_time('timestamp')));
        $weekEnd = $this->sqlDate(gmdate('Y-m-d 23:59:59', current_time('timestamp') + (6 * DAY_IN_SECONDS)));

        $row = $this->rangeTasks($scope)
            ->where('status', 'open')
            ->selectRaw(
                "SUM(CASE WHEN due_at IS NULL THEN 1 ELSE 0 END) as no_due_date,"
                . " SUM(CASE WHEN due_at IS NOT NULL AND due_at < '{$todayStart}' THEN 1 ELSE 0 END) as overdue,"
                . " SUM(CASE WHEN due_at >= '{$todayStart}' AND due_at <= '{$todayEnd}' THEN 1 ELSE 0 END) as due_today,"
                . " SUM(CASE WHEN due_at > '{$todayEnd}' AND due_at <= '{$weekEnd}' THEN 1 ELSE 0 END) as next_seven,"
                . " SUM(CASE WHEN due_at > '{$weekEnd}' THEN 1 ELSE 0 END) as later"
            )
            ->first();

        return [
            ['key' => 'overdue', 'label' => 'Overdue', 'value' => $row ? (int) $row->overdue : 0],
            ['key' => 'today', 'label' => 'Today', 'value' => $row ? (int) $row->due_today : 0],
            ['key' => 'next7days', 'label' => 'Next 7 Days', 'value' => $row ? (int) $row->next_seven : 0],
            ['key' => 'later', 'label' => 'Later', 'value' => $row ? (int) $row->later : 0],
            ['key' => 'no_due_date', 'label' => 'No Due Date', 'value' => $row ? (int) $row->no_due_date : 0],
        ];
    }

    /**
     * Per-assignee open / completed / overdue counts.
     *
     * `workload` is the member's open count as a share of the busiest member's,
     * so the bar reads as "who is carrying the most" rather than an absolute
     * capacity the plugin has no way of knowing.
     */
    private function getAssigneeWorkload(array $scope)
    {
        $now = $this->sqlDate(current_time('mysql'));

        $rows = $this->assigneeRelationQuery($scope)
            ->selectRaw(
                'rel.foreign_id as user_id, COUNT(*) as total,'
                . " SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as completed,"
                . " SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open_count,"
                . " SUM(CASE WHEN status = 'open' AND last_completed_at IS NULL"
                . " AND due_at IS NOT NULL AND due_at <= '{$now}' THEN 1 ELSE 0 END) as overdue"
            )
            ->groupBy('rel.foreign_id')
            ->orderBy('open_count', 'DESC')
            ->limit(self::TOP_ROWS)
            ->get();

        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row->user_id;
        }

        $estimations = $this->sumEstimatedMinutesByAssignee($scope, $userIds);
        $users = $this->getUsers($userIds);

        $busiest = 0;
        foreach ($rows as $row) {
            $busiest = max($busiest, (int) $row->open_count);
        }

        $members = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $user = $users[$userId] ?? null;
            $open = (int) $row->open_count;

            $members[] = [
                'id'        => $userId,
                'name'      => $user ? $user['name'] : __('Unknown', 'fluent-boards'),
                'avatar'    => $user ? $user['avatar'] : '',
                'open'      => $open,
                'completed' => (int) $row->completed,
                'overdue'   => (int) $row->overdue,
                'estimated' => $this->formatMinutes($estimations[$userId] ?? 0),
                'workload'  => $busiest ? (int) round(($open / $busiest) * 100) : 0,
            ];
        }

        return $members;
    }

    private function countActiveMembers(array $scope)
    {
        $rows = $this->rangeActivities($scope)
            ->selectRaw('COUNT(DISTINCT created_by) as total')
            ->first();

        return $rows ? (int) $rows->total : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Tasks pieces
    |--------------------------------------------------------------------------
    */

    private function getTasksByStage(array $scope)
    {
        $rows = $this->rangeTasks($scope)
            ->selectRaw('stage_id, COUNT(*) as total')
            ->groupBy('stage_id')
            ->orderBy('total', 'DESC')
            ->get();

        $stageIds = [];
        foreach ($rows as $row) {
            $stageIds[] = (int) $row->stage_id;
        }

        $titles = $stageIds
            ? BoardTerm::whereIn('id', $stageIds)->pluck('title', 'id')->toArray()
            : [];

        // Stages are per-board, so "Done" would otherwise get one row per board
        // in an all-boards report; same-named stages are counted together.
        $merged = [];
        foreach ($rows as $row) {
            $id = (int) $row->stage_id;
            $title = $titles[$id] ?? __('No Stage', 'fluent-boards');
            $key = strtolower($title);

            if (!isset($merged[$key])) {
                $merged[$key] = ['label' => $title, 'value' => 0];
            }

            $merged[$key]['value'] += (int) $row->total;
        }

        $stages = array_values($merged);

        usort($stages, function ($first, $second) {
            return $second['value'] - $first['value'];
        });

        return array_slice($stages, 0, self::TOP_ROWS);
    }

    private function getTasksByAssignee(array $scope)
    {
        $rows = $this->assigneeRelationQuery($scope)
            ->selectRaw(
                // Single quotes only: the connection rewrites " to a backtick
                // before the query reaches wpdb, which would turn a string
                // literal into an identifier.
                'rel.foreign_id as user_id, COUNT(*) as total,'
                . " SUM(CASE WHEN status = 'closed' THEN 1 ELSE 0 END) as completed"
            )
            ->groupBy('rel.foreign_id')
            ->orderBy('total', 'DESC')
            ->limit(self::TOP_ROWS)
            ->get();

        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row->user_id;
        }

        $users = $this->getUsers($userIds);

        $assignees = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $user = $users[$userId] ?? null;

            $assignees[] = [
                'id'        => $userId,
                'name'      => $user ? $user['name'] : __('Unknown', 'fluent-boards'),
                'avatar'    => $user ? $user['avatar'] : '',
                'completed' => (int) $row->completed,
                'total'     => (int) $row->total,
            ];
        }

        return $assignees;
    }

    /**
     * Labels keep the colour stored on `fbs_board_terms` so the chart can honour
     * it without a lookup table on the client.
     *
     * Labels belong to a board, so an all-boards report would otherwise show
     * "Bug" once per board. Rows are merged by title — the first (largest) row
     * of a title decides the colour, since sibling boards nearly always use the
     * same palette entry for the same name.
     */
    private function getTasksByLabel(array $scope)
    {
        $rows = $this->relationQuery($scope, Constant::OBJECT_TYPE_TASK_LABEL)
            ->selectRaw('rel.foreign_id as label_id, COUNT(*) as total')
            ->groupBy('rel.foreign_id')
            ->orderBy('total', 'DESC')
            ->get();

        $labelIds = [];
        foreach ($rows as $row) {
            $labelIds[] = (int) $row->label_id;
        }

        $labels = $labelIds
            ? BoardTerm::whereIn('id', $labelIds)->get()->keyBy('id')
            : [];

        $merged = [];
        foreach ($rows as $row) {
            $id = (int) $row->label_id;
            $label = $labels[$id] ?? null;

            if (!$label || !$label->title) {
                continue;
            }

            $key = strtolower($label->title);

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'id'    => $id,
                    'title' => $label->title,
                    'total' => 0,
                    // Labels render as their fill colour everywhere else in the UI.
                    'color' => $label->bg_color ?: $label->color,
                ];
            }

            $merged[$key]['total'] += (int) $row->total;
        }

        $items = array_values($merged);

        usort($items, function ($first, $second) {
            return $second['total'] - $first['total'];
        });

        return array_slice($items, 0, self::TOP_ROWS);
    }

    private function getRecentlyCompleted(array $scope)
    {
        $tasks = $this->boardTasks($scope)
            ->where('status', 'closed')
            ->whereBetween('last_completed_at', [$scope['start'], $scope['end']])
            ->with(['board', 'stage', 'assignees'])
            ->orderBy('last_completed_at', 'DESC')
            ->limit(10)
            ->get();

        $taskIds = [];
        foreach ($tasks as $task) {
            $taskIds[] = (int) $task->id;
        }

        $estimations = $this->getEstimatedMinutesByTask($taskIds);

        $items = [];
        foreach ($tasks as $task) {
            $assignee = $task->assignees ? $task->assignees->first() : null;
            $minutes = $estimations[(int) $task->id] ?? 0;

            $items[] = [
                'id'             => (int) $task->id,
                'title'          => $task->title,
                // boardId + slug are what the task route needs to open the task.
                'slug'           => $task->slug,
                'boardId'        => (int) $task->board_id,
                'board'          => $task->board ? $task->board->title : '',
                'boardColor'     => $this->getBoardColor($task->board),
                'stage'          => $task->stage ? $task->stage->title : '',
                'priority'       => $task->priority ?: 'none',
                'assignee'       => $assignee ? $assignee->display_name : '',
                'assigneeAvatar' => $assignee ? fluent_boards_user_avatar($assignee->user_email) : '',
                'estimated'      => $minutes ? $this->formatMinutes($minutes) : '',
            ];
        }

        return $items;
    }

    /*
    |--------------------------------------------------------------------------
    | Activity pieces
    |--------------------------------------------------------------------------
    */

    /**
     * One grouped pass over `fbs_activities`, folded into the report's type keys.
     */
    private function countActivitiesByType(array $scope)
    {
        $rows = $this->rangeActivities($scope)
            ->selectRaw('`column` as activity_column, action, COUNT(*) as total')
            ->groupBy('activity_column', 'action')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $key = $this->matchActivityType($row->activity_column, $row->action);

            if (!$key) {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->total;
        }

        return $counts;
    }

    private function matchActivityType($column, $action)
    {
        foreach (self::ACTIVITY_TYPES as $key => $type) {
            if (strcasecmp((string) $column, $type['column']) !== 0) {
                continue;
            }

            if ($type['actions'] === null || in_array($action, $type['actions'], true)) {
                return $key;
            }
        }

        return null;
    }

    private function getActivityByUser(array $scope)
    {
        $rows = $this->rangeActivities($scope)
            ->selectRaw('created_by, COUNT(*) as total')
            ->groupBy('created_by')
            ->orderBy('total', 'DESC')
            ->limit(self::TOP_ROWS)
            ->get();

        $userIds = [];
        foreach ($rows as $row) {
            $userIds[] = (int) $row->created_by;
        }

        $users = $this->getUsers($userIds);

        $items = [];
        foreach ($rows as $row) {
            $userId = (int) $row->created_by;
            $user = $users[$userId] ?? null;

            $items[] = [
                'id'     => $userId,
                'name'   => $user ? $user['name'] : __('Unknown', 'fluent-boards'),
                'avatar' => $user ? $user['avatar'] : '',
                'count'  => (int) $row->total,
            ];
        }

        return $items;
    }

    private function getRecentActivities(array $scope)
    {
        $activities = Activity::query()
            ->where('fbs_activities.object_type', Constant::ACTIVITY_TASK)
            ->whereBetween('fbs_activities.created_at', [$scope['start'], $scope['end']])
            ->whereIn('fbs_activities.object_id', $this->scopedTaskIdQuery($scope))
            ->with(['user', 'task'])
            ->orderBy('fbs_activities.created_at', 'DESC')
            ->limit(10)
            ->get();

        $items = [];
        foreach ($activities as $activity) {
            $items[] = [
                'id'      => (int) $activity->id,
                'taskId'  => (int) $activity->object_id,
                'taskSlug' => $activity->task ? $activity->task->slug : '',
                'boardId' => $activity->task ? (int) $activity->task->board_id : 0,
                'actor'   => $activity->user ? $activity->user->display_name : __('Someone', 'fluent-boards'),
                'action'  => $this->describeActivity($activity),
                'subject' => $activity->task ? $activity->task->title : '',
                'date'    => $this->formatDate($activity->created_at),
            ];
        }

        return $items;
    }

    /**
     * Turns an activity's action + column into the verb phrase the feed shows.
     */
    private function describeActivity($activity)
    {
        $column = strtolower((string) $activity->column);
        $action = strtolower((string) $activity->action);

        if ($column === 'stage' && $action === 'changed') {
            return __('moved', 'fluent-boards');
        }

        if ($column === 'comment' && $action === 'added') {
            return __('commented on', 'fluent-boards');
        }

        if ($column === 'attachment' && $action === 'added') {
            return __('attached a file to', 'fluent-boards');
        }

        if ($column === 'subtask' && in_array($action, ['added', 'cloned'], true)) {
            return __('added a subtask to', 'fluent-boards');
        }

        if ($column === 'label') {
            return $action === 'removed'
                ? __('removed a label from', 'fluent-boards')
                : __('labelled', 'fluent-boards');
        }

        if ($column === 'due date') {
            return __('set a due date on', 'fluent-boards');
        }

        if ($column === 'task' && $action === 'created') {
            return __('created', 'fluent-boards');
        }

        if ($column === 'task' && $action === 'closed') {
            return __('completed', 'fluent-boards');
        }

        /* translators: 1: action verb, 2: field name */
        return trim(sprintf('%1$s %2$s', $action, $column));
    }

    /*
    |--------------------------------------------------------------------------
    | Query building
    |--------------------------------------------------------------------------
    */

    /**
     * Every top-level, non-archived task on the scoped boards.
     */
    private function boardTasks(array $scope)
    {
        // Columns stay table-qualified so the same builders can be joined
        // against `fbs_relations`, which carries its own created_at/updated_at.
        return Task::query()
            ->whereNull('fbs_tasks.parent_id')
            ->whereNull('fbs_tasks.archived_at')
            ->whereIn('fbs_tasks.board_id', $scope['boardIds']);
    }

    /**
     * Current top-level ideas in the roadmap scope.
     */
    private function roadmapIdeas(array $scope)
    {
        return $this->boardTasks($scope)->where('fbs_tasks.type', 'roadmap');
    }

    /**
     * Ideas submitted inside the reporting window.
     */
    private function rangeRoadmapIdeas(array $scope)
    {
        return $this->roadmapIdeas($scope)
            ->whereBetween('fbs_tasks.created_at', [$scope['start'], $scope['end']]);
    }

    /**
     * The scoped tasks touched inside the reporting window.
     */
    private function rangeTasks(array $scope)
    {
        return $this->boardTasks($scope)->where(function ($query) use ($scope) {
            $query->whereBetween('fbs_tasks.created_at', [$scope['start'], $scope['end']])
                ->orWhereBetween('fbs_tasks.last_completed_at', [$scope['start'], $scope['end']])
                ->orWhereBetween('fbs_tasks.updated_at', [$scope['start'], $scope['end']]);
        });
    }

    /**
     * Task activities in range, scoped to the boards through their task.
     *
     * `fbs_activities` carries no board_id, so the board filter is a subquery on
     * task ids rather than a join — that keeps the grouped aggregates on a single
     * table and their raw column names unambiguous.
     */
    private function rangeActivities(array $scope)
    {
        return Activity::query()
            ->where('object_type', Constant::ACTIVITY_TASK)
            ->whereBetween('created_at', [$scope['start'], $scope['end']])
            ->whereIn('object_id', $this->scopedTaskIdQuery($scope));
    }

    /**
     * Sub-select of every task id on the scoped boards (subtasks included, since
     * activity is logged against them too).
     */
    private function scopedTaskIdQuery(array $scope)
    {
        return Task::query()
            ->select('id')
            ->whereNull('archived_at')
            ->whereIn('board_id', $scope['boardIds']);
    }

    /**
     * In-range tasks joined to a `fbs_relations` row, ready to be grouped by the
     * related id — assignees by default, labels when asked.
     *
     * The join target is a DISTINCT sub-query rather than the table itself:
     * `fbs_relations` has no unique index on (object_id, foreign_id,
     * object_type) — only plain keys — so a task can legitimately carry the same
     * assignee or label twice. Joining the raw table fans those duplicates out
     * and multiplies every aggregate built on top: task counts, completed
     * counts, workload and the estimate sums alike. Deduplicating once here
     * fixes all of them, and keeps the callers free of DISTINCT bookkeeping.
     *
     * Tasks stay the base table and the relation is aliased, because raw SELECT
     * expressions are not table-prefixed by the query builder: only aliases and
     * unqualified column names are safe inside them.
     */
    private function relationQuery(array $scope, $objectType)
    {
        $distinctRelations = Relation::query()
            ->distinct()
            ->select('object_id', 'foreign_id')
            ->where('object_type', $objectType);

        return $this->rangeTasks($scope)
            ->joinSub($distinctRelations, 'rel', function ($join) {
                $join->on('rel.object_id', '=', 'fbs_tasks.id');
            });
    }

    /**
     * Assignee relations joined to their in-range task, ready to be grouped.
     */
    private function assigneeRelationQuery(array $scope)
    {
        return $this->relationQuery($scope, Constant::OBJECT_TYPE_TASK_ASSIGNEE);
    }

    /*
    |--------------------------------------------------------------------------
    | Estimations
    |--------------------------------------------------------------------------
    */

    /**
     * The `fbs_task_metas` rows holding the one estimate that counts per task.
     *
     * Nothing in the schema stops a task from carrying more than one
     * `_estimated_minutes` row — Pro's writer does a check-then-create, so a
     * race or historical data can leave duplicates. Summing them all would both
     * inflate every estimate here and disagree with the task modal, which reads
     * the estimate with `first()`. Every aggregation below therefore counts only
     * the lowest-id row per task, which is the row Pro displays.
     */
    private function firstEstimateRowIds()
    {
        return TaskMeta::query()
            ->selectRaw('MIN(id) as id')
            ->where('key', '_estimated_minutes')
            ->groupBy('task_id');
    }

    /**
     * `_estimated_minutes` is written by Pro's time-tracking module but lives in
     * the free `fbs_task_metas` table, so reports can read it without Pro; sites
     * that never set an estimate simply report zero.
     */
    private function sumEstimatedMinutes(array $scope)
    {
        $row = TaskMeta::query()
            ->selectRaw('SUM(CAST(value AS UNSIGNED)) as total')
            ->whereIn('id', $this->firstEstimateRowIds())
            ->whereIn('task_id', $this->rangeTasks($scope)->select('id'))
            ->first();

        return $row ? (int) $row->total : 0;
    }

    private function sumEstimatedMinutesByAssignee(array $scope, array $userIds)
    {
        if (!$userIds) {
            return [];
        }

        $rows = $this->assigneeRelationQuery($scope)
            ->selectRaw('rel.foreign_id as user_id, SUM(CAST(meta.value AS UNSIGNED)) as total')
            ->join((new TaskMeta())->getTable() . ' as meta', function ($join) {
                $join->on('meta.task_id', '=', 'fbs_tasks.id')
                    ->where('meta.key', '_estimated_minutes');
            })
            // Without this the join fans out over duplicate meta rows and
            // multiplies the member's estimate.
            ->whereIn('meta.id', $this->firstEstimateRowIds())
            ->whereIn('rel.foreign_id', $userIds)
            ->groupBy('rel.foreign_id')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->user_id] = (int) $row->total;
        }

        return $totals;
    }

    private function getEstimatedMinutesByTask(array $taskIds)
    {
        if (!$taskIds) {
            return [];
        }

        $rows = TaskMeta::query()
            ->selectRaw('task_id, CAST(value AS UNSIGNED) as total')
            ->whereIn('id', $this->firstEstimateRowIds())
            ->whereIn('task_id', $taskIds)
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(int) $row->task_id] = (int) $row->total;
        }

        return $totals;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A board's background is a serialized array that may hold a colour or an
     * uploaded image; only the colour is useful as a row swatch.
     */
    private function getBoardColor($board)
    {
        if (!$board) {
            return '';
        }

        $background = $board->background;

        if (is_array($background) && !empty($background['color'])) {
            return $background['color'];
        }

        return '';
    }

    private function getUsers(array $userIds)
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if (!$userIds) {
            return [];
        }

        $users = [];
        foreach (User::whereIn('ID', $userIds)->get() as $user) {
            $users[(int) $user->ID] = [
                'name'   => $user->display_name,
                'avatar' => fluent_boards_user_avatar($user->user_email),
            ];
        }

        return $users;
    }

    /**
     * Minutes as the compact "12h 30m" the report tables show.
     */
    private function formatMinutes($minutes)
    {
        $minutes = (int) $minutes;

        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if (!$hours) {
            return $rest . 'm';
        }

        return $rest ? $hours . 'h ' . $rest . 'm' : $hours . 'h';
    }

    /**
     * Escapes a server-generated timestamp for inlining into a raw expression.
     *
     * Raw SELECTs here need literal dates (the builder does not bind inside
     * them); every value passed in is produced by current_time(), never by a
     * request, and is escaped regardless.
     */
    private function sqlDate($date)
    {
        return esc_sql($date);
    }

    private function formatDate($date)
    {
        if (!$date) {
            return '';
        }

        return date_i18n(get_option('date_format') . ', ' . get_option('time_format'), strtotime($date));
    }

    /**
     * Accepts only `Y-m-d`, and only if it is a real calendar date — same guard
     * the timesheet report uses.
     */
    private function sanitizeDate($date)
    {
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        list($year, $month, $day) = explode('-', $date);

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return $date;
    }

    /**
     * Rejects inverted or oversized ranges before roadmap queries and daily
     * response-point generation begin.
     */
    private function validateRoadmapDateRange(array $scope)
    {
        $start = new \DateTimeImmutable(substr($scope['start'], 0, 10));
        $end = new \DateTimeImmutable(substr($scope['end'], 0, 10));

        if ($start > $end) {
            throw new \InvalidArgumentException(
                esc_html__('Start date cannot be after end date.', 'fluent-boards')
            );
        }

        $days = (int) $start->diff($end)->days + 1;
        if ($days > self::MAX_ROADMAP_REPORT_DAYS) {
            throw new \InvalidArgumentException(
                sprintf(
                    esc_html__('Roadmap reports are limited to %d days.', 'fluent-boards'),
                    self::MAX_ROADMAP_REPORT_DAYS
                )
            );
        }
    }

    /**
     * Ranges are widened to whole days in site-local time, because task and
     * activity timestamps are written with current_time('mysql').
     */
    private function startOfDay($date, $fallbackDayOffset = 0)
    {
        if (!$date) {
            $timestamp = current_time('timestamp') + ($fallbackDayOffset * DAY_IN_SECONDS);
            return gmdate('Y-m-d 00:00:00', $timestamp);
        }

        return $date . ' 00:00:00';
    }

    private function endOfDay($date)
    {
        if (!$date) {
            return gmdate('Y-m-d 23:59:59', current_time('timestamp'));
        }

        return $date . ' 23:59:59';
    }

    private function emptyOverview()
    {
        return [
            'stats'            => [
                'total'         => 0,
                'completed'     => 0,
                'overdue'       => 0,
                'activeMembers' => 0,
                'estimatedTime' => '0m',
            ],
            'tasksByBoard'     => [],
            'priority'         => $this->emptyPriority(),
            'completion'       => [
                ['key' => 'completed', 'label' => 'Completed', 'value' => 0],
                ['key' => 'incomplete', 'label' => 'Still Open', 'value' => 0],
                ['key' => 'completed_earlier', 'label' => 'Completed Earlier', 'value' => 0],
            ],
            'dueDate'          => [
                ['key' => 'overdue', 'label' => 'Overdue', 'value' => 0],
                ['key' => 'today', 'label' => 'Today', 'value' => 0],
                ['key' => 'next7days', 'label' => 'Next 7 Days', 'value' => 0],
                ['key' => 'later', 'label' => 'Later', 'value' => 0],
                ['key' => 'no_due_date', 'label' => 'No Due Date', 'value' => 0],
            ],
            'assigneeWorkload' => [],
        ];
    }

    private function emptyTasks()
    {
        return [
            'byStage'           => [],
            'byAssignee'        => [],
            'byLabel'           => [],
            'priority'          => $this->emptyPriority(),
            'recentlyCompleted' => [],
        ];
    }

    private function emptyActivity()
    {
        $byType = [];
        foreach (self::ACTIVITY_TYPES as $key => $type) {
            $byType[] = [
                'key'   => $key,
                'label' => $type['label'],
                'icon'  => $type['icon'],
                'value' => 0,
            ];
        }

        return [
            'stats'  => [
                'tasksCreated'     => 0,
                'stageChanged'     => 0,
                'commentsAdded'    => 0,
                'subtasksCreated'  => 0,
                'attachmentsAdded' => 0,
            ],
            'byUser' => [],
            'byType' => $byType,
            'recent' => [],
        ];
    }

    private function emptyRoadmap()
    {
        return [
            'stats'        => [
                'totalIdeas'     => 0,
                'submittedIdeas' => 0,
                'publicIdeas'    => 0,
                'completedIdeas' => 0,
            ],
            'submissions'  => [],
            'byStage'      => [],
            'popularIdeas' => [],
            'bySource'     => [
                ['key' => 'page', 'label' => 'Public Page', 'value' => 0, 'colorKey' => 'primary'],
                ['key' => 'web', 'label' => 'Admin / Web', 'value' => 0, 'colorKey' => 'success'],
                ['key' => 'other', 'label' => 'Other', 'value' => 0, 'colorKey' => 'neutral'],
            ],
        ];
    }

    private function emptyPriority()
    {
        $items = [];
        foreach (self::PRIORITY_BUCKETS as $key => $label) {
            $items[] = ['key' => $key, 'label' => $label, 'value' => 0];
        }

        return ['total' => 0, 'items' => $items];
    }
}
