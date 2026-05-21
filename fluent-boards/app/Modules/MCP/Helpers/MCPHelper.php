<?php

namespace FluentBoards\App\Modules\MCP\Helpers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;

/**
 * Shared utilities for Fluent Boards MCP tools.
 */
class MCPHelper
{
    const TASK_HISTORY_LIMIT = 20;

    public static function error($code, $message, $data = [])
    {
        return new \WP_Error($code, $message, $data);
    }

    public static function normalizePagination($params, $defaultPerPage = 20, $maxPerPage = 100)
    {
        $page = isset($params['page']) ? absint($params['page']) : 1;
        $perPage = isset($params['per_page']) ? absint($params['per_page']) : $defaultPerPage;

        return [
            'page'     => max(1, $page),
            'per_page' => max(1, min($maxPerPage, $perPage)),
        ];
    }

    public static function resolveBoard($params)
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        if (!$boardId) {
            return self::error('invalid_param', __('Provide board_id', 'fluent-boards'));
        }

        $board = Board::with(['stages', 'labels', 'users'])->find($boardId);
        if (!$board) {
            return self::error('not_found', __('Board not found', 'fluent-boards'), ['board_id' => $boardId]);
        }

        return $board;
    }

    public static function resolveTask($params)
    {
        $taskId = isset($params['task_id']) ? absint($params['task_id']) : 0;
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;

        if (!$taskId || !$boardId) {
            return self::error('invalid_param', __('Provide board_id and task_id', 'fluent-boards'));
        }

        $task = Task::with(self::taskDetailRelations())->find($taskId);
        if (!$task || (int) $task->board_id !== $boardId) {
            return self::error('not_found', __('Task not found on this board', 'fluent-boards'), [
                'board_id' => $boardId,
                'task_id'  => $taskId,
            ]);
        }

        return $task;
    }

    public static function loadTaskDetails($task)
    {
        return $task->load(self::taskDetailRelations());
    }

    public static function taskDetailRelations()
    {
        return [
            'board',
            'stage',
            'labels',
            'assignees',
            'watchers',
            'comments'   => function ($query) {
                $query->without(['images', 'replies'])
                    ->orderBy('id', 'DESC')
                    ->limit(self::TASK_HISTORY_LIMIT);
            },
            'activities' => function ($query) {
                $query->limit(self::TASK_HISTORY_LIMIT);
            },
        ];
    }

    public static function canReadBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission(absint($boardId), 'GET');
    }

    public static function canWriteBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission(absint($boardId), 'POST');
    }

    public static function assertStageBelongsToBoard($stageId, $boardId)
    {
        $stage = Stage::where('id', absint($stageId))
            ->where('board_id', absint($boardId))
            ->whereNull('archived_at')
            ->first();

        if (!$stage) {
            return self::error('not_found', __('Stage not found on this board', 'fluent-boards'), [
                'board_id' => absint($boardId),
                'stage_id' => absint($stageId),
            ]);
        }

        return $stage;
    }

    public static function formatBoardSummary($board)
    {
        return [
            'id'              => (int) $board->id,
            'title'           => $board->title,
            'description'     => $board->description,
            'type'            => $board->type,
            'currency'        => $board->currency,
            'created_by'      => (int) $board->created_by,
            'archived_at'     => self::toIso8601($board->archived_at),
            'created_at'      => self::toIso8601($board->created_at),
            'updated_at'      => self::toIso8601($board->updated_at),
            'stages_count'    => isset($board->stages) ? count($board->stages) : 0,
            'labels_count'    => isset($board->labels) ? count($board->labels) : 0,
            'members_count'   => isset($board->users) ? count($board->users) : 0,
        ];
    }

    public static function formatBoard($board, $includeTasks = false)
    {
        $data = self::formatBoardSummary($board);
        $data['stages'] = self::formatStageList($board->stages ?? []);
        $data['labels'] = self::formatLabelList($board->labels ?? []);
        $data['members'] = self::formatUserList($board->users ?? []);

        if ($includeTasks) {
            $tasks = Task::with(['stage', 'labels', 'assignees'])
                ->where('board_id', $board->id)
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->orderBy('stage_id', 'asc')
                ->orderBy('position', 'asc')
                ->limit(100)
                ->get();
            $data['tasks'] = self::formatTaskList($tasks);
            $data['tasks_limited_to'] = 100;
        }

        return $data;
    }

    public static function formatTaskSummary($task)
    {
        return [
            'id'                => (int) $task->id,
            'title'             => $task->title,
            'slug'              => $task->slug,
            'board_id'          => (int) $task->board_id,
            'stage_id'          => (int) $task->stage_id,
            'parent_id'         => $task->parent_id ? (int) $task->parent_id : null,
            'status'            => $task->status,
            'priority'          => $task->priority,
            'position'          => isset($task->position) ? (float) $task->position : null,
            'crm_contact_id'    => $task->crm_contact_id ? (int) $task->crm_contact_id : null,
            'comments_count'    => isset($task->comments_count) ? (int) $task->comments_count : 0,
            'due_at'            => self::toIso8601($task->due_at),
            'started_at'        => self::toIso8601($task->started_at),
            'last_completed_at' => self::toIso8601($task->last_completed_at),
            'archived_at'       => self::toIso8601($task->archived_at),
            'created_at'        => self::toIso8601($task->created_at),
            'updated_at'        => self::toIso8601($task->updated_at),
            'stage'             => $task->stage ? self::formatStage($task->stage) : null,
            'labels'            => self::formatLabelList($task->labels ?? []),
            'assignees'         => self::formatUserList($task->assignees ?? []),
        ];
    }

    public static function formatTask($task)
    {
        $data = self::formatTaskSummary($task);
        $data['description'] = $task->description;
        $data['settings'] = $task->settings;
        $data['board'] = $task->board ? self::formatBoardSummary($task->board) : null;
        $data['watchers'] = self::formatUserList($task->watchers ?? []);
        $data['comments'] = self::formatCommentList(self::limitItems($task->comments ?? [], self::TASK_HISTORY_LIMIT));
        $data['comments_limited_to'] = self::TASK_HISTORY_LIMIT;
        $data['activities'] = self::formatActivityList(self::limitItems($task->activities ?? [], self::TASK_HISTORY_LIMIT));
        $data['activities_limited_to'] = self::TASK_HISTORY_LIMIT;

        return $data;
    }

    public static function formatTaskList($tasks)
    {
        $items = [];
        foreach ($tasks as $task) {
            $items[] = self::formatTaskSummary($task);
        }
        return $items;
    }

    public static function formatStage($stage)
    {
        return [
            'id'                  => (int) $stage->id,
            'board_id'            => (int) $stage->board_id,
            'title'               => $stage->title,
            'slug'                => $stage->slug,
            'position'            => isset($stage->position) ? (float) $stage->position : null,
            'default_task_status' => method_exists($stage, 'defaultTaskStatus') ? $stage->defaultTaskStatus() : 'open',
            'archived_at'         => self::toIso8601($stage->archived_at),
        ];
    }

    public static function formatStageList($stages)
    {
        $items = [];
        foreach ($stages as $stage) {
            $items[] = self::formatStage($stage);
        }
        return $items;
    }

    public static function formatLabelList($labels)
    {
        $items = [];
        foreach ($labels as $label) {
            $items[] = [
                'id'          => (int) $label->id,
                'board_id'    => (int) $label->board_id,
                'title'       => $label->title,
                'slug'        => $label->slug,
                'color'       => $label->color,
                'bg_color'    => $label->bg_color,
                'position'    => isset($label->position) ? (float) $label->position : null,
                'archived_at' => self::toIso8601($label->archived_at),
            ];
        }
        return $items;
    }

    public static function formatUserList($users)
    {
        $items = [];
        foreach ($users as $user) {
            $name = trim((string) ($user->display_name ?? ''));
            if (!$name) {
                $name = trim((string) (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
            }

            $items[] = [
                'id'           => isset($user->ID) ? (int) $user->ID : (int) ($user->id ?? 0),
                'display_name' => $name,
                'email'        => $user->user_email ?? '',
                'avatar'       => !empty($user->user_email) ? fluent_boards_user_avatar($user->user_email, $name) : '',
            ];
        }
        return $items;
    }

    public static function formatCommentList($comments)
    {
        $items = [];
        foreach ($comments as $comment) {
            $items[] = [
                'id'          => (int) $comment->id,
                'task_id'     => (int) $comment->task_id,
                'parent_id'   => $comment->parent_id ? (int) $comment->parent_id : null,
                'type'        => $comment->type,
                'privacy'     => $comment->privacy,
                'status'      => $comment->status,
                'author_name' => $comment->author_name,
                'description' => $comment->description,
                'created_by'  => $comment->created_by ? (int) $comment->created_by : null,
                'created_at'  => self::toIso8601($comment->created_at),
            ];
        }
        return $items;
    }

    public static function formatActivityList($activities)
    {
        $items = [];
        foreach ($activities as $activity) {
            $items[] = [
                'id'          => (int) $activity->id,
                'object_id'   => isset($activity->object_id) ? (int) $activity->object_id : null,
                'object_type' => $activity->object_type ?? '',
                'action'      => $activity->action ?? '',
                'description' => $activity->description ?? '',
                'created_by'  => isset($activity->created_by) ? (int) $activity->created_by : null,
                'created_at'  => self::toIso8601($activity->created_at ?? null),
            ];
        }
        return $items;
    }

    public static function sanitizeIdArray($values)
    {
        return array_values(array_filter(array_map('absint', (array) $values)));
    }

    private static function limitItems($items, $limit)
    {
        if (is_array($items)) {
            return array_slice($items, 0, $limit);
        }

        $limited = [];
        foreach ($items as $item) {
            if (count($limited) >= $limit) {
                break;
            }

            $limited[] = $item;
        }

        return $limited;
    }

    public static function currentUser()
    {
        $userId = get_current_user_id();
        $user = $userId ? get_user_by('ID', $userId) : null;

        return [
            'wp_user_id'             => (int) $userId,
            'name'                   => $user ? $user->display_name : null,
            'email'                  => $user ? $user->user_email : null,
            'is_wp_admin'            => $user ? user_can($user, 'manage_options') : false,
            'is_fluent_boards_admin' => PermissionManager::isAdmin($userId),
            'can_create_boards'      => PermissionManager::userHasBoardCreationPermission($userId),
        ];
    }

    public static function toIso8601($value)
    {
        if (!$value) {
            return null;
        }

        $timestamp = strtotime((string) $value);
        if (!$timestamp) {
            return (string) $value;
        }

        return date('c', $timestamp);
    }
}
