<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\Framework\Support\Arr;

/**
 * Global search (the "/" and Cmd/Ctrl+K dialog).
 *
 * Every typed word must match the title, description, a label, an assignee,
 * the stage, or a (Pro) custom field value. Labels, people and stages live in
 * small tables, so each word is first resolved to their ids; the task query
 * then joins on indexed relation columns instead of running a LIKE per task row.
 *
 * One SQL score ranks the whole result set, so pages are stable. Filters
 * (assignee, board, label, status, archived) work with or without words.
 * See docs/plan/global-search-v2.md.
 */
class GlobalSearchService
{
    const MAX_WORDS = 6;

    /** Shorter words match titles only, so "a" never scans every field of every task. */
    const MIN_WORD_LENGTH = 2;

    const PER_PAGE = 20;

    /** Boards and people shown in the mixed "All" view; the rest sit behind "See all". */
    const PREVIEW_LIMIT = 3;

    const SUGGEST_LIMIT = 5;

    /** How many remembered ids are checked, so missing or hidden tasks don't empty the recent list. */
    const RECENT_HISTORY = 20;

    /** Upper bound on label/user/stage ids one word can resolve to. */
    const ID_SET_LIMIT = 500;

    const OPTIONS_LIMIT = 30;

    const SNIPPET_LENGTH = 120;

    const SNIPPET_LEAD = 40;

    const STATUSES = ['open', 'done', 'overdue'];

    /** Task columns the result rows need. The longtext description is never loaded here. */
    const TASK_COLUMNS = ['id', 'parent_id', 'board_id', 'stage_id', 'title', 'status', 'priority', 'due_at', 'last_completed_at', 'archived_at', 'updated_at'];

    private $userId;

    private $isAdmin;

    /** @var array Parsed request, see parseParams(). */
    private $args = [];

    /** @var array word => ['label' => int[], 'user' => int[], 'stage' => int[]] */
    private $idSets = [];

    private $tables = [];

    public function __construct($userId)
    {
        global $wpdb;

        $this->userId = (int) $userId;
        $this->isAdmin = PermissionManager::isAdmin($this->userId);

        // Raw SQL is not table-prefixed by the query builder, so keep full names here.
        $this->tables = [
            'tasks'     => $wpdb->prefix . 'fbs_tasks',
            'relations' => $wpdb->prefix . 'fbs_relations',
        ];
    }

    /**
     * Run a search.
     *
     * @param array $params q, type (all|tasks|boards|people), page, assignees, board_ids,
     *                      labels, statuses, archived, current_board
     * @return array tasks, boards and people groups: {data, page, has_more}
     */
    public function search(array $params)
    {
        $this->args = $this->parseParams($params);
        $this->resolveIdSets();

        $type = $this->args['type'];
        $page = $this->args['page'];
        $empty = ['data' => [], 'page' => 1, 'has_more' => false];

        $result = ['tasks' => $empty, 'boards' => $empty, 'people' => $empty];

        if ($type === 'all' || $type === 'tasks') {
            $result['tasks'] = $this->searchTasks($type === 'all' ? 1 : $page, self::PER_PAGE);
        }

        // "#12" names a task; boards and people make no sense next to it.
        $taskFiltersOnly = $this->hasTaskFilter() || $this->args['exact_id'] !== null;

        if ($type === 'boards' || ($type === 'all' && !$taskFiltersOnly)) {
            $result['boards'] = $this->searchBoards($type === 'all' ? 1 : $page, $type === 'all' ? self::PREVIEW_LIMIT : self::PER_PAGE);
        }

        if ($type === 'people' || ($type === 'all' && !$taskFiltersOnly && $this->args['words'])) {
            $result['people'] = $this->searchPeople($type === 'all' ? 1 : $page, $type === 'all' ? self::PREVIEW_LIMIT : self::PER_PAGE);
        }

        return $result;
    }

    /**
     * Data for the dialog before anything is typed.
     *
     * @param int[] $recentIds task ids the browser remembers opening, newest first
     * @return array recent + assigned task lists
     */
    public function suggestions(array $recentIds)
    {
        $this->args = $this->parseParams([]);

        $historyIds = array_slice(array_values(array_unique(array_filter(array_map('absint', $recentIds)))), 0, self::RECENT_HISTORY);

        // Look up the whole bounded history, then keep the first viewable ones in order.
        $recent = [];
        $shownIds = [];
        if ($historyIds) {
            $found = $this->baseTaskQuery()->whereIn('id', $historyIds)->get()->keyBy('id');
            foreach ($historyIds as $id) {
                if (!isset($found[$id])) {
                    continue;
                }
                $recent[] = $found[$id];
                $shownIds[] = $id;
                if (count($recent) >= self::SUGGEST_LIMIT) {
                    break;
                }
            }
        }

        $assigned = $this->baseTaskQuery()
            ->where('status', '!=', 'closed')
            ->whereIn('id', $this->assigneeTaskIdsQuery([$this->userId]))
            ->when($shownIds, function ($query) use ($shownIds) {
                $query->whereNotIn('id', $shownIds);
            })
            ->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at', 'ASC')
            ->orderBy('updated_at', 'DESC')
            ->limit(self::SUGGEST_LIMIT)
            ->get()
            ->all();

        return [
            'recent'   => $this->formatTasks($recent),
            'assigned' => $this->formatTasks($assigned),
        ];
    }

    /**
     * Values for the filter popover: boards, labels or people the user can see.
     *
     * @param string $field boards|labels|people
     * @param string $query optional text to narrow the list
     * @return array[] {value, label, color?, bg_color?, photo?}
     */
    public function filterOptions($field, $query)
    {
        global $wpdb;

        $this->args = $this->parseParams([]);
        $query = trim(sanitize_text_field((string) $query));
        $like = '%' . $wpdb->esc_like($query) . '%';

        if ($field === 'boards') {
            $boards = Board::query()
                ->select(['id', 'title'])
                ->whereIn('id', $this->accessibleBoardsQuery())
                ->when($query !== '', function ($q) use ($like) {
                    $q->where('title', 'LIKE', $like);
                })
                ->orderBy('title', 'ASC')
                ->limit(self::OPTIONS_LIMIT)
                ->get();

            $options = [];
            foreach ($boards as $board) {
                $options[] = ['value' => (int) $board->id, 'label' => $board->title];
            }

            return $options;
        }

        if ($field === 'labels') {
            // The same label name exists once per board; the filter matches it by name everywhere.
            $labels = Label::query()
                ->select(['title', 'color', 'bg_color'])
                ->whereIn('board_id', $this->accessibleBoardsQuery())
                ->whereNotNull('title')
                ->where('title', '!=', '')
                ->when($query !== '', function ($q) use ($like) {
                    $q->where('title', 'LIKE', $like);
                })
                ->orderBy('title', 'ASC')
                ->limit(self::OPTIONS_LIMIT * 4)
                ->get();

            $options = [];
            foreach ($labels as $label) {
                $key = mb_strtolower($label->title);
                if (isset($options[$key])) {
                    continue;
                }
                $options[$key] = [
                    'value'    => $label->title,
                    'label'    => $label->title,
                    'color'    => $label->color,
                    'bg_color' => $label->bg_color,
                ];
            }

            return array_slice(array_values($options), 0, self::OPTIONS_LIMIT);
        }

        if ($field === 'people') {
            return $this->peopleQuery([$query])
                ->limit(self::OPTIONS_LIMIT)
                ->get()
                ->map(function ($user) {
                    return [
                        'value' => (int) $user->ID,
                        'label' => $user->display_name,
                        'photo' => $user->photo,
                    ];
                })
                ->all();
        }

        return [];
    }

    private function parseParams(array $params)
    {
        global $wpdb;

        $raw = trim(sanitize_text_field((string) Arr::get($params, 'q', '')));

        // "#12" and "id:12" both name task 12. null = no id asked for; "#0" stays 0, an id no task has.
        $exactId = null;
        if (preg_match('/^(?:#|id:\s*)(\d+)$/i', $raw, $m)) {
            $exactId = absint($m[1]);
            $raw = '';
        }

        $words = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);

        $type = sanitize_key((string) Arr::get($params, 'type', 'all'));
        if (!in_array($type, ['all', 'tasks', 'boards', 'people'], true)) {
            $type = 'all';
        }

        $statuses = array_values(array_intersect(self::STATUSES, $this->csv(Arr::get($params, 'statuses', ''), 'sanitize_key')));

        $assignees = $this->csv(Arr::get($params, 'assignees', ''), 'sanitize_key');
        $assigneeIds = [];
        foreach ($assignees as $assignee) {
            $assigneeIds[] = $assignee === 'me' ? $this->userId : absint($assignee);
        }

        return [
            'phrase'        => $raw,
            'phrase_like'   => $wpdb->esc_like($raw),
            'words'         => $words,
            'exact_id'      => $exactId,
            'type'          => $type,
            'page'          => max(1, (int) Arr::get($params, 'page', 1)),
            'assignee_ids'  => array_values(array_unique(array_filter($assigneeIds))),
            'board_ids'     => array_values(array_unique(array_filter(array_map('absint', $this->csv(Arr::get($params, 'board_ids', '')))))),
            'labels'        => array_slice($this->labelNames(Arr::get($params, 'labels', [])), 0, 20),
            'statuses'      => $statuses,
            'archived'      => rest_sanitize_boolean(Arr::get($params, 'archived', false)),
            'current_board' => absint(Arr::get($params, 'current_board', 0)),
        ];
    }

    /** A comma-separated (or array) parameter as a list of sanitized, non-empty strings. */
    private function csv($value, $sanitizer = 'sanitize_text_field')
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $items = array_map(function ($item) use ($sanitizer) {
            return trim(call_user_func($sanitizer, (string) $item));
        }, $items);

        return array_values(array_filter($items, 'strlen'));
    }

    /**
     * Label names arrive as an array (labels[]=...), never split on commas:
     * a label may be called "Design, UX". A single string is one name.
     */
    private function labelNames($value)
    {
        $items = is_array($value) ? $value : [$value];
        $names = [];
        foreach ($items as $item) {
            if (!is_scalar($item)) {
                continue;
            }
            $name = trim(sanitize_text_field((string) $item));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /** Filters that only apply to tasks; while one is on, boards and people are not listed. */
    private function hasTaskFilter()
    {
        $a = $this->args;

        return $a['assignee_ids'] || $a['board_ids'] || $a['labels'] || $a['statuses'];
    }

    /**
     * Ids of boards the user may search: no templates, no archived boards
     * (unless asked), and only the user's own boards for non-admins.
     *
     * @return \FluentBoards\Framework\Database\Orm\Builder sub-query selecting board ids
     */
    private function accessibleBoardsQuery()
    {
        $query = Board::query()
            ->select('id')
            ->excludeTemplates()
            ->availableInCurrentInstall();

        if (!$this->args['archived']) {
            $query->whereNull('archived_at');
        }

        if (!$this->isAdmin) {
            $query->whereIn('id', PermissionManager::getBoardIdsForUser($this->userId) ?: [0]);
        }

        return $query;
    }

    /** Tasks (and subtasks) on boards the user may see, archived ones only when asked for. */
    private function baseTaskQuery()
    {
        $query = Task::query()
            ->select(self::TASK_COLUMNS)
            ->whereIn('board_id', $this->accessibleBoardsQuery())
            ->with([
                'board:id,title,archived_at',
                'stage:id,title',
                'labels',
                'assignees',
            ]);

        if (!$this->args['archived']) {
            $query->whereNull('archived_at');
        }

        return $query;
    }

    /**
     * Resolve each word to the label, user and stage ids whose names contain it.
     * These tables are small, so this is a few cheap queries per word, and the
     * task query can then match on indexed id columns.
     */
    private function resolveIdSets()
    {
        global $wpdb;

        $this->idSets = [];

        foreach ($this->args['words'] as $word) {
            if (mb_strlen($word) < self::MIN_WORD_LENGTH) {
                continue;
            }
            $like = '%' . $wpdb->esc_like($word) . '%';

            $labelIds = Label::query()
                ->whereIn('board_id', $this->accessibleBoardsQuery())
                ->where('title', 'LIKE', $like)
                ->limit(self::ID_SET_LIMIT)
                ->pluck('id')
                ->all();

            $stageIds = Stage::query()
                ->whereIn('board_id', $this->accessibleBoardsQuery())
                ->where('title', 'LIKE', $like)
                ->limit(self::ID_SET_LIMIT)
                ->pluck('id')
                ->all();

            $userIds = $this->peopleQuery([$word])
                ->limit(self::ID_SET_LIMIT)
                ->pluck('ID')
                ->all();

            $this->idSets[$word] = [
                'label' => array_map('intval', $labelIds),
                'stage' => array_map('intval', $stageIds),
                'user'  => array_map('intval', $userIds),
            ];
        }
    }

    private function searchTasks($page, $perPage)
    {
        $args = $this->args;
        $query = $this->baseTaskQuery();

        if ($args['exact_id'] !== null) {
            $query->where('id', $args['exact_id']);
        } else {
            foreach ($args['words'] as $word) {
                $query->where(function ($q) use ($word) {
                    $this->whereWordMatches($q, $word);
                });
            }
        }

        $this->applyTaskFilters($query);
        $this->orderTasks($query);

        // One extra row tells whether another page exists, without a COUNT(*) scan.
        $rows = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1)
            ->get()
            ->all();

        $hasMore = count($rows) > $perPage;
        $tasks = array_slice($rows, 0, $perPage);

        return [
            'data'     => $this->formatTasks($tasks, $this->matchHints($tasks)),
            'page'     => $page,
            'has_more' => $hasMore,
        ];
    }

    /** A word matches if any searchable field of the task contains it. */
    private function whereWordMatches($query, $word)
    {
        $like = $this->like($word);
        $query->where('title', 'LIKE', $like);

        if (mb_strlen($word) < self::MIN_WORD_LENGTH) {
            return;
        }

        $query->orWhere('description', 'LIKE', $like);

        $sets = $this->idSets[$word];
        if ($sets['label']) {
            $query->orWhereIn('id', $this->relationTaskIdsQuery(Constant::OBJECT_TYPE_TASK_LABEL, $sets['label']));
        }
        if ($sets['user']) {
            $query->orWhereIn('id', $this->assigneeTaskIdsQuery($sets['user']));
        }
        if ($sets['stage']) {
            $query->orWhereIn('stage_id', $sets['stage']);
        }

        if (defined('FLUENT_BOARDS_PRO')) {
            $query->orWhereIn('id', $this->customFieldTaskIdsQuery($word));
        }
    }

    private function applyTaskFilters($query)
    {
        $args = $this->args;

        if ($args['assignee_ids']) {
            $query->whereIn('id', $this->assigneeTaskIdsQuery($args['assignee_ids']));
        }

        if ($args['board_ids']) {
            $query->whereIn('board_id', $args['board_ids']);
        }

        if ($args['labels']) {
            $labelIds = Label::query()
                ->whereIn('board_id', $this->accessibleBoardsQuery())
                ->whereIn('title', $args['labels'])
                ->pluck('id')
                ->all();
            $query->whereIn('id', $this->relationTaskIdsQuery(Constant::OBJECT_TYPE_TASK_LABEL, $labelIds ?: [0]));
        }

        // Several statuses match any of them.
        if ($args['statuses']) {
            $statuses = $args['statuses'];
            $query->where(function ($q) use ($statuses) {
                if (in_array('open', $statuses, true)) {
                    $q->orWhere('status', '!=', 'closed');
                }
                if (in_array('done', $statuses, true)) {
                    $q->orWhere('status', 'closed');
                }
                // Same rule as Task::scopeOverdue(); scopes are not available on a nested where.
                if (in_array('overdue', $statuses, true)) {
                    $q->orWhere(function ($overdue) {
                        $overdue->whereNull('last_completed_at')
                            ->where('status', 'open')
                            ->where('due_at', '<=', current_time('mysql'));
                    });
                }
            });
        }
    }

    /**
     * Best match first, across the whole result set:
     * title equals the query 100 · title starts with it 80 · every word starts a
     * title word 60 · every word in the title 50 · every word in the title or a
     * label, assignee or stage name 30 · otherwise (description, custom field) 10.
     * Boosts: open +15, assigned to me +8, current board +5, updated in 14 days +3,
     * subtask -5. With no words, open tasks come first, then by due date.
     */
    private function orderTasks($query)
    {
        $t = $this->tables['tasks'];
        $words = $this->args['words'];

        if (!$words) {
            $query->orderByRaw("{$t}.status = 'closed' ASC")
                ->orderByRaw("{$t}.due_at IS NULL ASC")
                ->orderBy('due_at', 'ASC')
                ->orderBy('updated_at', 'DESC')
                ->orderBy('id', 'DESC');

            return;
        }

        $bindings = [$this->args['phrase'], $this->args['phrase_like'] . '%'];
        $wordStart = [];
        $inTitle = [];
        $inTitleOrMeta = [];
        foreach ($words as $word) {
            $wordStart[] = "({$t}.title LIKE ? OR {$t}.title LIKE ?)";
            $inTitle[] = "{$t}.title LIKE ?";
            $inTitleOrMeta[] = $this->titleOrMetaSql($word);
        }
        foreach ($words as $word) {
            $escaped = $this->escLike($word);
            array_push($bindings, $escaped . '%', '% ' . $escaped . '%');
        }
        foreach ($words as $word) {
            $bindings[] = $this->like($word);
        }
        $metaBindings = [];
        foreach ($words as $word) {
            $metaBindings = array_merge($metaBindings, $this->titleOrMetaBindings($word));
        }
        $bindings = array_merge($bindings, $metaBindings);

        $r = $this->tables['relations'];
        $currentBoard = (int) $this->args['current_board'];
        $recent = gmdate('Y-m-d H:i:s', current_time('timestamp') - 14 * DAY_IN_SECONDS);

        $score = "(CASE"
            . " WHEN {$t}.title = ? THEN 100"
            . " WHEN {$t}.title LIKE ? THEN 80"
            . ' WHEN ' . implode(' AND ', $wordStart) . ' THEN 60'
            . ' WHEN ' . implode(' AND ', $inTitle) . ' THEN 50'
            . ' WHEN ' . implode(' AND ', $inTitleOrMeta) . ' THEN 30'
            . ' ELSE 10 END)'
            . " + ({$t}.status != 'closed') * 15"
            . " + ({$t}.id IN (SELECT mr.object_id FROM {$r} mr WHERE mr.object_type = ? AND mr.foreign_id = ?)) * 8"
            . " + ({$t}.board_id = ?) * 5"
            . " + ({$t}.updated_at >= ?) * 3"
            . " - ({$t}.parent_id IS NOT NULL) * 5";

        $bindings = array_merge($bindings, [Constant::OBJECT_TYPE_TASK_ASSIGNEE, $this->userId, $currentBoard, $recent]);

        $query->orderByRaw($score . ' DESC', $bindings)
            ->orderBy('updated_at', 'DESC')
            ->orderBy('id', 'DESC');
    }

    /** SQL true when the word is in the title or names one of the task's labels, assignees or its stage. */
    private function titleOrMetaSql($word)
    {
        $t = $this->tables['tasks'];
        $r = $this->tables['relations'];
        $parts = ["{$t}.title LIKE ?"];
        $sets = Arr::get($this->idSets, $word, ['label' => [], 'user' => [], 'stage' => []]);

        // Ids are integers from our own queries, so they are inlined.
        if ($sets['label']) {
            $parts[] = "{$t}.id IN (SELECT lr.object_id FROM {$r} lr WHERE lr.object_type = ? AND lr.foreign_id IN (" . implode(',', $sets['label']) . '))';
        }
        if ($sets['user']) {
            $parts[] = "{$t}.id IN (SELECT ar.object_id FROM {$r} ar WHERE ar.object_type = ? AND ar.foreign_id IN (" . implode(',', $sets['user']) . '))';
        }
        if ($sets['stage']) {
            $parts[] = "{$t}.stage_id IN (" . implode(',', $sets['stage']) . ')';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /** Bindings for titleOrMetaSql(), in the same order. */
    private function titleOrMetaBindings($word)
    {
        $bindings = [$this->like($word)];
        $sets = Arr::get($this->idSets, $word, ['label' => [], 'user' => [], 'stage' => []]);

        if ($sets['label']) {
            $bindings[] = Constant::OBJECT_TYPE_TASK_LABEL;
        }
        if ($sets['user']) {
            $bindings[] = Constant::OBJECT_TYPE_TASK_ASSIGNEE;
        }

        return $bindings;
    }

    /** Sub-query: ids of tasks linked to any of $foreignIds through a relation of $objectType. */
    private function relationTaskIdsQuery($objectType, array $foreignIds)
    {
        return Relation::query()
            ->select('object_id')
            ->where('object_type', $objectType)
            ->whereIn('foreign_id', $foreignIds);
    }

    private function assigneeTaskIdsQuery(array $userIds)
    {
        return $this->relationTaskIdsQuery(Constant::OBJECT_TYPE_TASK_ASSIGNEE, $userIds);
    }

    /**
     * Sub-query: ids of tasks with a Pro custom field value containing the word.
     * Values are stored serialized as ['value' => ...], "value" first; matching
     * after 'a:N:{s:5:"value";' (17 characters) keeps the key itself from matching.
     * ponytail: matches serialize syntax too ("s:12:" for "12"); give custom
     * values their own column or index if that becomes a problem.
     */
    private function customFieldTaskIdsQuery($word)
    {
        return Relation::query()
            ->select('object_id')
            ->where('object_type', Constant::TASK_CUSTOM_FIELD)
            ->where('settings', 'LIKE', 'a:_:{s:5:%')
            ->whereRaw('SUBSTRING(settings, 18) LIKE ?', [$this->like($word)]);
    }

    /**
     * Members of boards the user can see. Every word must be in the name, or in
     * the email for people allowed to list users. People results, the assignee
     * picker and word resolution all match the same way.
     *
     * @param string[] $words
     */
    private function peopleQuery(array $words)
    {
        $memberIds = Relation::query()
            ->select('foreign_id')
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->whereIn('object_id', $this->accessibleBoardsQuery());

        $query = User::query()
            ->select(['ID', 'display_name', 'user_email'])
            ->whereIn('ID', $memberIds);

        $canSeeEmail = user_can($this->userId, 'list_users');
        foreach ($words as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }
            $like = $this->like($word);
            $query->where(function ($q) use ($like, $canSeeEmail) {
                $q->where('display_name', 'LIKE', $like);
                if ($canSeeEmail) {
                    $q->orWhere('user_email', 'LIKE', $like);
                }
            });
        }

        return $query->orderBy('display_name', 'ASC');
    }

    private function searchPeople($page, $perPage)
    {
        $query = $this->peopleQuery($this->args['words']);

        $rows = $query->skip(($page - 1) * $perPage)->take($perPage + 1)->get()->all();

        $data = [];
        foreach (array_slice($rows, 0, $perPage) as $user) {
            $data[] = [
                'type'         => 'person',
                'id'           => (int) $user->ID,
                'display_name' => $user->display_name,
                'photo'        => $user->photo,
            ];
        }

        return ['data' => $data, 'page' => $page, 'has_more' => count($rows) > $perPage];
    }

    private function searchBoards($page, $perPage)
    {
        $query = Board::query()->whereIn('id', $this->accessibleBoardsQuery());

        if ($this->args['exact_id'] !== null) {
            $query->where('id', $this->args['exact_id']);
        } else {
            foreach ($this->args['words'] as $word) {
                $query->where('title', 'LIKE', $this->like($word));
            }
        }

        if ($this->args['phrase'] !== '') {
            $query->orderByRaw('title LIKE ? DESC', [$this->args['phrase_like'] . '%']);
        }

        // id breaks title ties, so equal titles never repeat or vanish across pages.
        $rows = $query->select(['id', 'title', 'archived_at'])
            ->orderBy('title', 'ASC')
            ->orderBy('id', 'ASC')
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1)
            ->get()
            ->all();

        $boards = array_slice($rows, 0, $perPage);

        $openCounts = [];
        if ($boards) {
            $counts = Task::query()
                ->selectRaw('board_id, COUNT(*) AS open_count')
                ->whereIn('board_id', array_map(function ($board) {
                    return $board->id;
                }, $boards))
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->where('status', '!=', 'closed')
                ->groupBy('board_id')
                ->get();
            foreach ($counts as $count) {
                $openCounts[$count->board_id] = (int) $count->open_count;
            }
        }

        $data = [];
        foreach ($boards as $board) {
            $data[] = [
                'type'             => 'board',
                'id'               => (int) $board->id,
                'title'            => $board->title,
                'archived_at'      => $board->archived_at,
                'open_tasks_count' => Arr::get($openCounts, $board->id, 0),
            ];
        }

        return ['data' => $data, 'page' => $page, 'has_more' => count($rows) > $perPage];
    }

    /**
     * Why each task on the page matched, for words not in its title:
     * a label, assignee or stage name, a description snippet, or a custom field.
     * Works from the already loaded relations; only descriptions need one query.
     *
     * @param Task[] $tasks
     * @return array task id => {field, value}
     */
    private function matchHints(array $tasks)
    {
        $words = array_filter($this->args['words'], function ($word) {
            return mb_strlen($word) >= self::MIN_WORD_LENGTH;
        });
        if (!$tasks || !$words) {
            return [];
        }

        $hints = [];
        $needDescription = [];

        foreach ($tasks as $task) {
            $unmatched = [];
            foreach ($words as $word) {
                if ($this->contains($task->title, $word)) {
                    continue;
                }
                $hint = $this->metaHint($task, $word);
                if ($hint) {
                    $hints[$task->id] = $hint;
                    $unmatched = [];
                    break;
                }
                $unmatched[] = $word;
            }
            if ($unmatched) {
                $needDescription[$task->id] = $unmatched;
            }
        }

        if ($needDescription) {
            $descriptions = Task::query()
                ->whereIn('id', array_keys($needDescription))
                ->pluck('description', 'id')
                ->all();

            foreach ($needDescription as $taskId => $taskWords) {
                $text = $this->readableText(Arr::get($descriptions, $taskId, ''));
                $snippet = null;
                foreach ($taskWords as $word) {
                    $snippet = $this->snippet($text, $word);
                    if ($snippet !== null) {
                        break;
                    }
                }
                $hints[$taskId] = $snippet !== null
                    ? ['field' => 'description', 'value' => $snippet]
                    : ['field' => 'custom_field', 'value' => ''];
            }
        }

        return $hints;
    }

    private function metaHint($task, $word)
    {
        foreach ($task->labels as $label) {
            if ($this->contains($label->title, $word)) {
                return ['field' => 'label', 'value' => $label->title];
            }
        }
        foreach ($task->assignees as $user) {
            if ($this->contains($user->display_name, $word)) {
                return ['field' => 'assignee', 'value' => $user->display_name];
            }
        }
        if ($task->stage && $this->contains($task->stage->title, $word)) {
            return ['field' => 'stage', 'value' => $task->stage->title];
        }

        return null;
    }

    /** Case- and accent-insensitive, close to how the database compares. */
    private function contains($text, $word)
    {
        return mb_stripos(remove_accents((string) $text), remove_accents($word)) !== false;
    }

    /**
     * @param Task[] $tasks
     * @param array $hints task id => matchHints() entry
     */
    private function formatTasks(array $tasks, array $hints = [])
    {
        $parentTitles = [];
        $parentIds = array_filter(array_map(function ($task) {
            return (int) $task->parent_id;
        }, $tasks));
        if ($parentIds) {
            $parentTitles = Task::query()->whereIn('id', array_unique($parentIds))->pluck('title', 'id')->all();
        }

        $formatted = [];
        foreach ($tasks as $task) {
            $assignees = [];
            foreach ($task->assignees as $user) {
                $assignees[] = [
                    'ID'           => $user->ID,
                    'display_name' => $user->display_name,
                    'photo'        => $user->photo,
                ];
            }

            $labels = [];
            foreach ($task->labels as $label) {
                $labels[] = [
                    'id'       => $label->id,
                    'title'    => $label->title,
                    'color'    => $label->color,
                    'bg_color' => $label->bg_color,
                ];
            }

            $parent = null;
            if ($task->parent_id) {
                $parent = [
                    'id'    => (int) $task->parent_id,
                    'title' => Arr::get($parentTitles, $task->parent_id, ''),
                ];
            }

            $formatted[] = [
                'type'        => 'task',
                'id'          => (int) $task->id,
                'title'       => $task->title,
                'board_id'    => (int) $task->board_id,
                'board'       => [
                    'id'          => (int) $task->board_id,
                    'title'       => $task->board ? $task->board->title : '',
                    'archived_at' => $task->board ? $task->board->archived_at : null,
                ],
                'stage'       => [
                    'id'    => $task->stage_id,
                    'title' => $task->stage ? $task->stage->title : '',
                ],
                'parent'      => $parent,
                'status'      => $task->status,
                'priority'    => $task->priority,
                'due_at'      => $task->due_at,
                'is_overdue'  => $task->status !== 'closed' && $task->isOverdue(),
                'archived_at' => $task->archived_at,
                'labels'      => $labels,
                'assignees'   => $assignees,
                'match'       => Arr::get($hints, $task->id),
            ];
        }

        return $formatted;
    }

    /** The text a person sees in a description (HTML or Markdown). */
    private function readableText($text)
    {
        $text = wp_strip_all_tags((string) $text);
        // Drop images, link targets and long encoded blobs (pasted base64 images).
        $text = preg_replace(['/!\[[^\]]*\]\([^)]*\)/u', '/\]\([^)]*\)/u', '/\bdata:\S+/u', '/\S{40,}/u'], ['', ']', '', ''], $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** A short piece of text around the word, or null when the word is not in it. */
    private function snippet($text, $word)
    {
        $pos = mb_stripos(remove_accents($text), remove_accents($word));
        if ($pos === false) {
            return null;
        }

        $start = max(0, $pos - self::SNIPPET_LEAD);
        $piece = trim(mb_substr($text, $start, self::SNIPPET_LENGTH));

        return ($start > 0 ? '…' : '') . $piece . ($start + self::SNIPPET_LENGTH < mb_strlen($text) ? '…' : '');
    }

    private function escLike($word)
    {
        global $wpdb;

        return $wpdb->esc_like($word);
    }

    private function like($word)
    {
        return '%' . $this->escLike($word) . '%';
    }
}
