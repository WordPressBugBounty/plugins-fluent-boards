<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\TaskService;

/**
 * Task read/write MCP tools.
 */
class TaskTools
{
    public static function canMoveTask($params = [])
    {
        $sourceBoardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        $targetBoardId = !empty($params['target_board_id']) ? absint($params['target_board_id']) : $sourceBoardId;

        if (!$sourceBoardId || !$targetBoardId) {
            return false;
        }

        return MCPHelper::canWriteBoard($sourceBoardId) && MCPHelper::canWriteBoard($targetBoardId);
    }

    public static function listTasks($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canReadBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have access to this board', 'fluent-boards'));
        }

        $pagination = MCPHelper::normalizePagination($params);
        $args = [
            'page'             => $pagination['page'],
            'per_page'         => $pagination['per_page'],
            'sort_by'          => !empty($params['sort_by']) ? sanitize_text_field($params['sort_by']) : 'position',
            'sort_direction'   => !empty($params['sort_direction']) ? sanitize_text_field($params['sort_direction']) : 'asc',
            'search'           => !empty($params['search']) ? sanitize_text_field($params['search']) : '',
            'include_archived' => !empty($params['include_archived']),
            'stage'            => isset($params['stage']) ? (array) $params['stage'] : [],
            'task_status'      => isset($params['task_status']) ? (array) $params['task_status'] : [],
            'priority'         => isset($params['priority']) ? (array) $params['priority'] : [],
            'assignee'         => isset($params['assignee']) ? (array) $params['assignee'] : [],
            'labels'           => isset($params['labels']) ? (array) $params['labels'] : [],
            'due_date'         => isset($params['due_date']) ? (array) $params['due_date'] : [],
        ];

        $paginated = (new TaskService())->getTableTasks($board->id, $args);

        return [
            'items'      => MCPHelper::formatTaskList($paginated->items()),
            'pagination' => [
                'total'        => (int) $paginated->total(),
                'current_page' => (int) $paginated->currentPage(),
                'per_page'     => (int) $paginated->perPage(),
                'last_page'    => (int) $paginated->lastPage(),
            ],
        ];
    }

    public static function getTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canReadBoard($task->board_id)) {
            return MCPHelper::error('forbidden', __('You do not have access to this task', 'fluent-boards'));
        }

        return [
            'task' => MCPHelper::formatTask($task),
        ];
    }

    public static function createTask($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to create tasks on this board', 'fluent-boards'));
        }

        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        if ($title === '') {
            return MCPHelper::error('invalid_param', __('Task title is required', 'fluent-boards'));
        }

        $stage = MCPHelper::assertStageBelongsToBoard($params['stage_id'] ?? 0, $board->id);
        if (is_wp_error($stage)) {
            return $stage;
        }

        $taskData = [
            'title'    => $title,
            'board_id' => (int) $board->id,
            'stage_id' => (int) $stage->id,
        ];

        if (!empty($params['priority'])) {
            if (!in_array($params['priority'], ['low', 'medium', 'high'], true)) {
                return MCPHelper::error('invalid_param', __('Invalid task priority', 'fluent-boards'), [
                    'allowed' => ['low', 'medium', 'high'],
                ]);
            }
            $taskData['priority'] = sanitize_text_field($params['priority']);
        }

        foreach (['due_at', 'started_at'] as $field) {
            if (!empty($params[$field])) {
                $taskData[$field] = sanitize_text_field($params[$field]);
            }
        }

        if (!empty($params['description'])) {
            $taskData['description'] = wp_kses_post($params['description']);
        }

        if (!empty($params['crm_contact_id'])) {
            $taskData['crm_contact_id'] = absint($params['crm_contact_id']);
        }

        $task = (new TaskService())->createTask($taskData, $board->id);

        return [
            'task'    => MCPHelper::formatTaskSummary($task),
            'message' => __('Task has been successfully created', 'fluent-boards'),
        ];
    }

    public static function updateTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to update this task', 'fluent-boards'));
        }

        $service = new TaskService();
        $updatable = [
            'title'          => 'text',
            'description'    => 'html',
            'status'         => 'text',
            'priority'       => 'nullable_text',
            'due_at'         => 'nullable_text',
            'started_at'     => 'nullable_text',
            'crm_contact_id' => 'nullable_int',
            'settings'       => 'array',
        ];

        foreach ($updatable as $field => $type) {
            if (!array_key_exists($field, $params)) {
                continue;
            }

            $validationError = self::validateUpdateField($field, $params[$field]);
            if (is_wp_error($validationError)) {
                return $validationError;
            }

            $value = self::sanitizeUpdateValue($params[$field], $type);
            $task = $service->updateTaskProperty($field, $value, $task);
        }

        if (array_key_exists('assignees', $params)) {
            $task = self::syncAssignees($task, MCPHelper::sanitizeIdArray($params['assignees']));
        }

        MCPHelper::loadTaskDetails($task);

        return [
            'task'    => MCPHelper::formatTask($task),
            'message' => __('Task has been updated', 'fluent-boards'),
        ];
    }

    public static function moveTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        $sourceBoardId = (int) $task->board_id;
        $targetBoardId = !empty($params['target_board_id']) ? absint($params['target_board_id']) : $sourceBoardId;
        $targetStageId = !empty($params['target_stage_id']) ? absint($params['target_stage_id']) : 0;

        if (!MCPHelper::canWriteBoard($sourceBoardId) || !MCPHelper::canWriteBoard($targetBoardId)) {
            return MCPHelper::error('forbidden', __('You do not have permission to move this task', 'fluent-boards'));
        }

        $stage = MCPHelper::assertStageBelongsToBoard($targetStageId, $targetBoardId);
        if (is_wp_error($stage)) {
            return $stage;
        }

        $service = new TaskService();
        $oldStageId = (int) $task->stage_id;

        if ($targetBoardId !== $sourceBoardId) {
            $task = $service->changeBoardByTask($task, $targetBoardId);
        }

        if ($oldStageId !== $targetStageId) {
            \FluentBoards\App\Models\TaskMeta::where('task_id', $task->id)
                ->where('key', Constant::META_KEY_ARCHIVED_BY_STAGE)
                ->delete();
        }

        $task->stage_id = $targetStageId;
        $task->save();

        $previousTaskId = !empty($params['previous_task_id']) ? absint($params['previous_task_id']) : 0;
        $nextTaskId = !empty($params['next_task_id']) ? absint($params['next_task_id']) : 0;
        if ($previousTaskId || $nextTaskId) {
            $task = $task->moveBetweenTasks($previousTaskId, $nextTaskId);
        } else {
            $position = !empty($params['position']) ? absint($params['position']) : 1;
            $task = $task->moveToNewPosition($position);
        }

        if ($oldStageId !== $targetStageId) {
            $service->manageDefaultAssignees($task, $targetStageId);

            if ($stage->defaultTaskStatus() === 'closed' && $task->status !== 'closed') {
                $task = $task->close();
            } elseif ($stage->defaultTaskStatus() === 'open' && $task->status === 'closed') {
                $task = $task->reopen();
            }

            do_action('fluent_boards/task_stage_updated', $task, $oldStageId);
        }

        do_action('fluent_boards/task_updated', $task, 'position');
        MCPHelper::loadTaskDetails($task);

        return [
            'task'    => MCPHelper::formatTask($task),
            'message' => __('Task has been moved', 'fluent-boards'),
        ];
    }

    public static function archiveTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to archive this task', 'fluent-boards'));
        }

        $archived = array_key_exists('archived', $params) ? (bool) $params['archived'] : true;
        $value = $archived ? current_time('mysql') : null;

        $task = (new TaskService())->updateTaskProperty('archived_at', $value, $task);
        MCPHelper::loadTaskDetails($task);

        return [
            'task'    => MCPHelper::formatTask($task),
            'message' => $archived ? __('Task has been archived', 'fluent-boards') : __('Task has been restored', 'fluent-boards'),
        ];
    }

    private static function sanitizeUpdateValue($value, $type)
    {
        if ($type === 'html') {
            return wp_kses_post((string) $value);
        }

        if ($type === 'array') {
            return is_array($value) ? self::sanitizeArray($value) : [];
        }

        if ($type === 'nullable_int') {
            return $value === null || $value === '' ? null : absint($value);
        }

        if ($type === 'nullable_text') {
            return $value === null ? null : sanitize_text_field((string) $value);
        }

        return sanitize_text_field((string) $value);
    }

    private static function validateUpdateField($field, $value)
    {
        if ($field === 'status' && !in_array($value, ['open', 'closed'], true)) {
            return MCPHelper::error('invalid_param', __('Invalid task status', 'fluent-boards'), [
                'allowed' => ['open', 'closed'],
            ]);
        }

        if ($field === 'priority' && $value !== '' && $value !== null && !in_array($value, ['low', 'medium', 'high'], true)) {
            return MCPHelper::error('invalid_param', __('Invalid task priority', 'fluent-boards'), [
                'allowed' => ['low', 'medium', 'high'],
            ]);
        }

        return true;
    }

    private static function sanitizeArray($value)
    {
        $sanitized = [];
        foreach ((array) $value as $key => $item) {
            $safeKey = sanitize_key((string) $key);
            if ($safeKey === '') {
                continue;
            }

            if (is_array($item)) {
                $sanitized[$safeKey] = self::sanitizeArray($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $sanitized[$safeKey] = $item;
            } else {
                $sanitized[$safeKey] = sanitize_text_field((string) $item);
            }
        }

        return $sanitized;
    }

    private static function syncAssignees($task, $newAssigneeIds)
    {
        $task->load('assignees');
        $currentIds = [];
        foreach ($task->assignees as $assignee) {
            $currentIds[] = (int) $assignee->ID;
        }

        $service = new TaskService();
        foreach (array_diff($currentIds, $newAssigneeIds) as $removeId) {
            $service->updateAssignee($removeId, $task);
            $task->load('assignees');
        }

        foreach (array_diff($newAssigneeIds, $currentIds) as $addId) {
            $service->updateAssignee($addId, $task);
            $task->load('assignees');
        }

        return $task;
    }
}
