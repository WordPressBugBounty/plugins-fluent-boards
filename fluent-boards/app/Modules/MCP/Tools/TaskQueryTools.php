<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\UserService;

/**
 * Cross-board task queries: the caller's own work list and global search.
 */
class TaskQueryTools
{
    const TASK_TYPES = ['assigned', 'mentioned', 'upcoming', 'due_today', 'overdue', 'completed', 'others'];
    const ORDER_BY = ['priority', 'due_at', 'position', 'created_at', 'title'];
    const MIN_TERM_LENGTH = 3;

    private static function shortTermError()
    {
        return MCPHelper::invalid('query', 'query needs at least three characters of title text. Use "id:123" to find one task by its id.');
    }

    public static function listMyTasks($params = [])
    {
        $currentUserId = get_current_user_id();
        $userId = !empty($params['user_id']) ? absint($params['user_id']) : $currentUserId;
        if (!$userId) {
            return MCPHelper::error('forbidden', 'The request has no signed-in user.');
        }

        // Only someone who may list users is told that an id has no user. For anyone else
        // an unknown id gives an empty list, like a user with no tasks, so the tool cannot
        // be used to find out which user ids exist.
        if ($userId !== $currentUserId && !get_user_by('ID', $userId)
            && (current_user_can('list_users') || PermissionManager::isAdmin($currentUserId))
        ) {
            return MCPHelper::error('not_found', sprintf('No WordPress user has id %d. get-board lists the members of a board with their ids.', $userId), [
                'fields' => ['user_id'],
            ]);
        }

        $taskType = !empty($params['task_type']) ? sanitize_text_field($params['task_type']) : 'assigned';
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            return MCPHelper::notOneOf('task_type', self::TASK_TYPES);
        }

        $orderBy = !empty($params['order_by']) ? sanitize_text_field($params['order_by']) : 'due_at';
        if (!in_array($orderBy, self::ORDER_BY, true)) {
            return MCPHelper::notOneOf('order_by', self::ORDER_BY);
        }

        $order = !empty($params['order']) ? strtoupper((string) $params['order']) : 'ASC';
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            return MCPHelper::notOneOf('order', ['ASC', 'DESC']);
        }

        $range = MCPHelper::readDateRange($params, 'due_from', 'due_to');
        if (is_wp_error($range)) {
            return $range;
        }

        $boardIds = self::readBoardIds($params);
        if (is_wp_error($boardIds)) {
            return $boardIds;
        }

        $pagination = MCPHelper::normalizePagination($params, 20, 50);

        // 'others' is the service's default branch (tasks with no due date).
        $serviceTaskType = $taskType === 'others' ? '' : $taskType;

        $result = (new UserService())->getMemberAssociatedTasks($userId, [
            'page'     => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'taskType' => $serviceTaskType,
            'boardIds' => $boardIds,
            'orderBy'  => $orderBy,
            'order'    => $order,
            'dueFrom'  => $range['from'],
            'dueTo'    => $range['to'],
        ]);

        // The service does not load assignees. One query adds them, so a row here has the
        // same fields as a search-tasks row.
        $tasks = $result['tasks'];
        $taskIds = array_map('intval', array_column($tasks, 'id'));
        if ($taskIds) {
            $assigneesByTask = [];
            foreach (Task::with('assignees')->whereIn('id', $taskIds)->get(['id']) as $withAssignees) {
                $assigneesByTask[(int) $withAssignees->id] = $withAssignees->assignees;
            }
            foreach ($tasks as $index => $task) {
                $tasks[$index]['assignees'] = $assigneesByTask[(int) $task['id']] ?? [];
            }
        }

        return [
            'user_id'    => $userId,
            'task_type'  => $taskType,
            'items'      => MCPHelper::formatTaskList($tasks, true),
            'pagination' => MCPHelper::paginationMeta(
                $result['paginationInfo']['total'] ?? 0,
                $result['paginationInfo']['current_page'] ?? 1,
                $result['paginationInfo']['per_page'] ?? $pagination['per_page']
            ),
        ];
    }

    public static function searchTasks($params = [])
    {
        global $wpdb;

        $query = isset($params['query']) ? strtolower(sanitize_text_field($params['query'])) : '';
        if ($query === '') {
            return MCPHelper::invalid('query', 'query is required: text from the task title, or "id:123".');
        }

        $boardIds = self::readBoardIds($params);
        if (is_wp_error($boardIds)) {
            return $boardIds;
        }

        $pagination = MCPHelper::normalizePagination($params, 20, 50);
        $includeArchived = !empty($params['include_archived']);
        $inDescriptions = !empty($params['search_descriptions']);

        $matchText = function ($tasksQuery, $term) use ($wpdb, $inDescriptions) {
            // The term is literal text: % and _ in it are not wildcards.
            $like = '%' . $wpdb->esc_like($term) . '%';
            $tasksQuery->where(function ($textQuery) use ($like, $inDescriptions) {
                $textQuery->where('title', 'LIKE', $like);
                if ($inDescriptions) {
                    $textQuery->orWhere('description', 'LIKE', $like);
                }
            });
        };

        // Same prefixes the product's global search box accepts. Title matching relies on the
        // column's case-insensitive collation rather than LOWER(), which would rule out any
        // index, and short terms are rejected so a one-character query cannot scan everything.
        if (strpos($query, 'id:') === 0) {
            $taskId = absint(preg_replace('/[^0-9]/', '', substr($query, 3)));
            if (!$taskId) {
                return MCPHelper::invalid('query', 'After "id:" send a task id as a number, for example "id:123".');
            }
            // A subtask is found too: its row carries parent_id.
            $tasksQuery = Task::query()->where('id', $taskId);
        } elseif (strpos($query, 'archived:') === 0) {
            $term = trim(substr($query, 9));
            if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
                return self::shortTermError();
            }
            $includeArchived = true;
            $tasksQuery = Task::query()->whereNull('parent_id')->whereNotNull('archived_at');
            $matchText($tasksQuery, $term);
        } else {
            if (mb_strlen($query) < self::MIN_TERM_LENGTH) {
                return self::shortTermError();
            }
            $tasksQuery = Task::query()->whereNull('parent_id');
            $matchText($tasksQuery, $query);
        }

        $currentUserId = get_current_user_id();
        $allowedBoardIds = PermissionManager::getBoardIdsForUser($currentUserId);

        if (!PermissionManager::isAdmin($currentUserId)) {
            if (!$allowedBoardIds) {
                return [
                    'query'      => $query,
                    'items'      => [],
                    'pagination' => MCPHelper::paginationMeta(0, 1, $pagination['per_page']),
                ];
            }
            $tasksQuery->whereIn('board_id', $allowedBoardIds);
        }

        if ($boardIds) {
            $tasksQuery->whereIn('board_id', $boardIds);
        }

        if (!$includeArchived) {
            $tasksQuery->whereNull('archived_at');
        }

        $paginated = $tasksQuery->with(['board', 'stage', 'labels', 'assignees'])
            ->orderBy('updated_at', 'DESC')
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return [
            'query'      => $query,
            'items'      => MCPHelper::formatTaskList($paginated->items(), true),
            'pagination' => MCPHelper::paginationMeta($paginated->total(), $paginated->currentPage(), $paginated->perPage()),
        ];
    }

    /**
     * Read the board_ids filter. A board the caller cannot read would give an empty list
     * that reads as "no tasks", so it is refused.
     *
     * @return int[]|\WP_Error
     */
    private static function readBoardIds($params)
    {
        if (!isset($params['board_ids'])) {
            return [];
        }

        if (!is_array($params['board_ids'])) {
            return MCPHelper::invalid('board_ids', 'board_ids must be a list of board ids, for example [3, 8].');
        }

        $boardIds = array_values(array_unique(MCPHelper::sanitizeIdArray($params['board_ids'])));
        $unreadable = [];
        foreach ($boardIds as $boardId) {
            if (!MCPHelper::canReadBoard($boardId) || !\FluentBoards\App\Models\Board::where('id', $boardId)->exists()) {
                $unreadable[] = $boardId;
            }
        }

        if ($unreadable) {
            return MCPHelper::error('not_found', sprintf(
                'You cannot read board %s, or it does not exist. list-boards shows the boards you can use.',
                implode(', ', $unreadable)
            ), ['fields' => ['board_ids']]);
        }

        return $boardIds;
    }
}
