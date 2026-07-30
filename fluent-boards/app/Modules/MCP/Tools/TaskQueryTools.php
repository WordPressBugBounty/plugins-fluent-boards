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
        return MCPHelper::error('invalid_param', __('Search terms need at least three characters. Use "id:123" to look up one task.', 'fluent-boards'), [
            'min_length' => self::MIN_TERM_LENGTH,
        ]);
    }

    public static function listMyTasks($params = [])
    {
        $userId = !empty($params['user_id']) ? absint($params['user_id']) : get_current_user_id();
        if (!$userId) {
            return MCPHelper::error('forbidden', __('No user context available', 'fluent-boards'));
        }

        $taskType = !empty($params['task_type']) ? sanitize_text_field($params['task_type']) : 'assigned';
        if (!in_array($taskType, self::TASK_TYPES, true)) {
            return MCPHelper::error('invalid_param', __('Invalid task type', 'fluent-boards'), [
                'allowed' => self::TASK_TYPES,
            ]);
        }

        $orderBy = !empty($params['order_by']) ? sanitize_text_field($params['order_by']) : 'due_at';
        if (!in_array($orderBy, self::ORDER_BY, true)) {
            return MCPHelper::error('invalid_param', __('Invalid order_by value', 'fluent-boards'), [
                'allowed' => self::ORDER_BY,
            ]);
        }

        $order = !empty($params['order']) && strtoupper($params['order']) === 'DESC' ? 'DESC' : 'ASC';
        $pagination = MCPHelper::normalizePagination($params, 20, 50);

        // 'others' is the service's default branch (tasks with no due date).
        $serviceTaskType = $taskType === 'others' ? '' : $taskType;

        try {
            $result = (new UserService())->getMemberAssociatedTasks($userId, [
                'page'     => $pagination['page'],
                'per_page' => $pagination['per_page'],
                'taskType' => $serviceTaskType,
                'boardIds' => MCPHelper::sanitizeIdArray($params['board_ids'] ?? []),
                'orderBy'  => $orderBy,
                'order'    => $order,
            ]);
        } catch (\Exception $e) {
            return MCPHelper::error('invalid_param', $e->getMessage());
        }

        $items = [];
        foreach ($result['tasks'] as $task) {
            $items[] = self::formatRow($task);
        }

        return [
            'user_id'    => $userId,
            'task_type'  => $taskType,
            'items'      => $items,
            'pagination' => [
                'total'        => (int) ($result['paginationInfo']['total'] ?? 0),
                'current_page' => (int) ($result['paginationInfo']['current_page'] ?? 1),
                'per_page'     => (int) ($result['paginationInfo']['per_page'] ?? $pagination['per_page']),
                'last_page'    => (int) ($result['paginationInfo']['last_page'] ?? 1),
            ],
        ];
    }

    public static function searchTasks($params = [])
    {
        $query = isset($params['query']) ? strtolower(sanitize_text_field($params['query'])) : '';
        if ($query === '') {
            return MCPHelper::error('invalid_param', __('Provide a search query', 'fluent-boards'));
        }

        $pagination = MCPHelper::normalizePagination($params, 20, 50);
        $includeArchived = !empty($params['include_archived']);

        // Same prefixes the product's global search box accepts. Title matching relies on the
        // column's case-insensitive collation rather than LOWER(), which would rule out any
        // index, and short terms are rejected so a one-character query cannot scan everything.
        if (strpos($query, 'id:') === 0) {
            $taskId = absint(preg_replace('/[^0-9]/', '', substr($query, 3)));
            if (!$taskId) {
                return MCPHelper::error('invalid_param', __('Provide a numeric task id after "id:"', 'fluent-boards'));
            }
            $tasksQuery = Task::query()->whereNull('parent_id')->where('id', $taskId);
        } elseif (strpos($query, 'archived:') === 0) {
            $term = trim(substr($query, 9));
            if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
                return self::shortTermError();
            }
            $includeArchived = true;
            $tasksQuery = Task::query()->whereNull('parent_id')->whereNotNull('archived_at')
                ->where('title', 'LIKE', '%' . $term . '%');
        } else {
            if (mb_strlen($query) < self::MIN_TERM_LENGTH) {
                return self::shortTermError();
            }
            $tasksQuery = Task::query()->whereNull('parent_id')
                ->where('title', 'LIKE', '%' . $query . '%');
        }

        $currentUserId = get_current_user_id();
        $allowedBoardIds = PermissionManager::getBoardIdsForUser($currentUserId);

        if (!PermissionManager::isAdmin($currentUserId)) {
            if (!$allowedBoardIds) {
                return [
                    'query'      => $query,
                    'items'      => [],
                    'pagination' => ['total' => 0, 'current_page' => 1, 'per_page' => $pagination['per_page'], 'last_page' => 0],
                ];
            }
            $tasksQuery->whereIn('board_id', $allowedBoardIds);
        }

        $boardIds = MCPHelper::sanitizeIdArray($params['board_ids'] ?? []);
        if ($boardIds) {
            $tasksQuery->whereIn('board_id', $boardIds);
        }

        if (!$includeArchived) {
            $tasksQuery->whereNull('archived_at');
        }

        $paginated = $tasksQuery->with(['board', 'stage', 'labels'])
            ->orderBy('updated_at', 'DESC')
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        $items = [];
        foreach ($paginated->items() as $task) {
            $items[] = self::formatRow($task);
        }

        return [
            'query'      => $query,
            'items'      => $items,
            'pagination' => [
                'total'        => (int) $paginated->total(),
                'current_page' => (int) $paginated->currentPage(),
                'per_page'     => (int) $paginated->perPage(),
                'last_page'    => (int) $paginated->lastPage(),
            ],
        ];
    }

    /**
     * Compact row shape. UserService returns plain arrays while the search query returns models,
     * so read both through the same accessor. Kept deliberately light: no avatars or emails.
     */
    private static function formatRow($task)
    {
        $get = function ($key) use ($task) {
            if (is_array($task)) {
                return $task[$key] ?? null;
            }
            return $task->{$key} ?? null;
        };

        $nested = function ($value, $key) {
            if (is_array($value)) {
                return $value[$key] ?? null;
            }
            if (is_object($value)) {
                return $value->{$key} ?? null;
            }
            return null;
        };

        $board = $get('board');
        $stage = $get('stage');
        $labels = $get('labels') ?: [];

        $labelTitles = [];
        foreach ($labels as $label) {
            $title = $nested($label, 'title');
            if ($title) {
                $labelTitles[] = $title;
            }
        }

        return [
            'id'          => (int) $get('id'),
            'title'       => $get('title'),
            'board_id'    => (int) $get('board_id'),
            'board_title' => $nested($board, 'title'),
            'stage_id'    => (int) $get('stage_id'),
            'stage_title' => $nested($stage, 'title'),
            'status'      => $get('status'),
            'priority'    => $get('priority'),
            'due_at'      => MCPHelper::toIso8601($get('due_at')),
            'started_at'  => MCPHelper::toIso8601($get('started_at')),
            'archived_at' => MCPHelper::toIso8601($get('archived_at')),
            'labels'      => $labelTitles,
        ];
    }
}
