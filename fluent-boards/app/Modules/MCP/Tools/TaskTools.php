<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\TaskService;

/**
 * Task read/write MCP tools.
 */
class TaskTools
{
    const MAX_ASSIGNEES_PER_CALL = 50;

    public static function listTasks($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canReadBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id);
        }

        $filters = self::readListFilters($params, $board);
        if (is_wp_error($filters)) {
            return $filters;
        }

        $sortBy = !empty($params['sort_by']) ? sanitize_text_field($params['sort_by']) : 'position';
        if (!in_array($sortBy, \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::TASK_SORT, true)) {
            return MCPHelper::notOneOf('sort_by', \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::TASK_SORT);
        }

        $pagination = MCPHelper::normalizePagination($params);
        $args = $filters + [
            'page'             => $pagination['page'],
            'per_page'         => $pagination['per_page'],
            'sort_by'          => $sortBy,
            'sort_direction'   => !empty($params['sort_direction']) && strtolower((string) $params['sort_direction']) === 'desc' ? 'desc' : 'asc',
            'search'           => !empty($params['search']) ? sanitize_text_field($params['search']) : '',
            'include_archived' => !empty($params['include_archived']),
        ];

        $paginated = (new TaskService())->getTableTasks($board->id, $args);
        // Every row names its stage; load once for the page instead of per task.
        $paginated->getCollection()->load('stage');

        return [
            'items'      => MCPHelper::formatTaskList($paginated->items()),
            'pagination' => MCPHelper::paginationMeta($paginated->total(), $paginated->currentPage(), $paginated->perPage()),
        ];
    }

    /**
     * Check every list-tasks filter value before the query runs. The table query drops a
     * value it does not know, and a dropped filter returns rows the agent did not ask for.
     *
     * @param array                          $params
     * @param \FluentBoards\App\Models\Board $board
     * @return array|\WP_Error Filter arguments for TaskService::getTableTasks().
     */
    private static function readListFilters($params, $board)
    {
        // WordPress accepts a comma-separated string where the schema asks for a list.
        foreach (['stage', 'task_status', 'priority', 'assignee', 'labels', 'due_date'] as $filter) {
            if (isset($params[$filter]) && !is_array($params[$filter])) {
                $params[$filter] = wp_parse_list($params[$filter]);
            }
        }

        $stages = [];
        if (!empty($params['stage'])) {
            $boardStageIds = array_map('intval', Stage::where('board_id', $board->id)->pluck('id')->toArray());
            foreach ($params['stage'] as $value) {
                if ($value === 'archived') {
                    $stages[] = 'archived';
                    continue;
                }

                if (!is_numeric($value) || !in_array((int) $value, $boardStageIds, true)) {
                    return MCPHelper::stageNotFound(is_numeric($value) ? $value : 0, $board->id, 'stage');
                }

                $stages[] = (int) $value;
            }
        }

        $statuses = isset($params['task_status']) ? array_values(array_map('strval', $params['task_status'])) : [];
        foreach ($statuses as $status) {
            if (!in_array($status, ['open', 'closed', 'archived'], true)) {
                return MCPHelper::notOneOf('task_status', ['open', 'closed', 'archived']);
            }
        }

        $priorities = isset($params['priority']) ? array_values(array_map('strval', $params['priority'])) : [];
        foreach ($priorities as $priority) {
            if (!in_array($priority, self::getAllowedTaskPriorities(), true)) {
                return MCPHelper::notOneOf('priority', self::getAllowedTaskPriorities());
            }
        }

        $assignees = [];
        foreach (($params['assignee'] ?? []) as $value) {
            if ($value === 'me') {
                $assignees[] = get_current_user_id();
            } elseif ($value === 'no-assignee') {
                $assignees[] = 'no-assignee';
            } elseif (is_numeric($value) && (int) $value > 0) {
                $assignees[] = (int) $value;
            } else {
                return MCPHelper::invalid('assignee', 'assignee takes user ids, "me" or "no-assignee". get-board lists the members with their ids.');
            }
        }

        $labels = [];
        if (!empty($params['labels'])) {
            $boardLabelIds = [];
            foreach ($board->labels as $label) {
                $boardLabelIds[] = (int) $label->id;
            }

            foreach ($params['labels'] as $value) {
                if (!is_numeric($value) || !in_array((int) $value, $boardLabelIds, true)) {
                    return MCPHelper::error('not_found', sprintf(
                        'Board %d has no label with id %s. labels takes label ids; get-board and list-labels return them.',
                        (int) $board->id,
                        is_scalar($value) ? (string) $value : '?'
                    ), ['fields' => ['labels']]);
                }

                $labels[] = (int) $value;
            }
        }

        $dueDates = isset($params['due_date']) ? array_values(array_map('strval', $params['due_date'])) : [];
        foreach ($dueDates as $dueDate) {
            if (!in_array($dueDate, \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::DUE_DATE_FILTERS, true)) {
                return MCPHelper::notOneOf('due_date', \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::DUE_DATE_FILTERS);
            }
        }

        $range = MCPHelper::readDateRange($params, 'due_from', 'due_to');
        if (is_wp_error($range)) {
            return $range;
        }

        return [
            'stage'       => $stages,
            'task_status' => $statuses,
            'priority'    => $priorities,
            'assignee'    => $assignees,
            'labels'      => $labels,
            'due_date'    => $dueDates,
            'due_from'    => $range['from'],
            'due_to'      => $range['to'],
        ];
    }

    public static function getTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canReadBoard($task->board_id)) {
            return MCPHelper::boardForbidden($task->board_id);
        }

        return [
            'task' => MCPHelper::formatTask($task, !empty($params['include_activities'])),
        ];
    }

    public static function createTask($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id, true);
        }

        // Everything is checked before the task is created, so a refused call leaves nothing behind.
        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        if ($title === '') {
            return MCPHelper::invalid('title', 'title is required and cannot be empty.');
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

        if (isset($params['priority']) && $params['priority'] !== '') {
            if (!in_array($params['priority'], self::getAllowedTaskPriorities(), true)) {
                return MCPHelper::notOneOf('priority', self::getAllowedTaskPriorities());
            }
            $taskData['priority'] = sanitize_text_field($params['priority']);
        }

        $dates = MCPHelper::readTaskDates($params);
        if (is_wp_error($dates)) {
            return $dates;
        }

        $order = MCPHelper::assertDateOrder($dates['started_at'] ?? null, $dates['due_at'] ?? null);
        if (is_wp_error($order)) {
            return $order;
        }

        $taskData = array_merge($taskData, $dates);

        $assigneeIds = self::readAssigneeIds($params['assignees'] ?? [], 'assignees', $board->id);
        if (is_wp_error($assigneeIds)) {
            return $assigneeIds;
        }

        $labels = [];
        if (!empty($params['label_ids'])) {
            $labels = LabelTools::resolveLabels(new \FluentBoards\App\Services\LabelService(), ['label_ids' => $params['label_ids']], $board->id);
            if (is_wp_error($labels)) {
                return $labels;
            }
        }

        if (!empty($params['description'])) {
            $taskData['description'] = MCPHelper::sanitizeMarkdown($params['description']);
        }

        if (!empty($params['crm_contact_id'])) {
            $contactCheck = MCPHelper::assertCanLinkContact();
            if (is_wp_error($contactCheck)) {
                return $contactCheck;
            }
            $taskData['crm_contact_id'] = absint($params['crm_contact_id']);
        }

        $service = new TaskService();
        $created = $service->createTask($taskData, $board->id);

        // The service returns the model with display-only attributes (isOverdue, ...) set.
        // Saving that model again fails, so later writes use a clean copy.
        $task = Task::find($created->id);

        if ($assigneeIds) {
            // The stage may already have added its default assignees; updateAssignee() toggles.
            self::syncAssignees($task, $assigneeIds, false);
        }

        if ($labels) {
            LabelTools::changeTaskLabels($task, $labels, 'add');
        }

        return [
            'task' => self::freshRow($task),
        ];
    }

    public static function updateTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::boardForbidden($task->board_id, true);
        }

        // Check every value before the first write: a refused call must change nothing.
        $changes = [];

        if (array_key_exists('title', $params)) {
            $title = sanitize_text_field((string) $params['title']);
            if ($title === '') {
                return MCPHelper::invalid('title', 'title cannot be empty. Leave title out to keep the current one.');
            }
            $changes['title'] = $title;
        }

        if (array_key_exists('description', $params)) {
            $changes['description'] = MCPHelper::sanitizeMarkdown($params['description']);
        }

        if (array_key_exists('status', $params)) {
            if (!in_array($params['status'], ['open', 'closed'], true)) {
                return MCPHelper::notOneOf('status', ['open', 'closed']);
            }
            $changes['status'] = $params['status'];
        }

        if (array_key_exists('priority', $params)) {
            $priority = $params['priority'] === null ? '' : (string) $params['priority'];
            if ($priority !== '' && !in_array($priority, self::getAllowedTaskPriorities(), true)) {
                return MCPHelper::notOneOf('priority', self::getAllowedTaskPriorities());
            }
            $changes['priority'] = sanitize_text_field($priority);
        }

        $dates = MCPHelper::readTaskDates($params, true);
        if (is_wp_error($dates)) {
            return $dates;
        }

        if ($dates) {
            $order = MCPHelper::assertDateOrder(
                array_key_exists('started_at', $dates) ? $dates['started_at'] : $task->started_at,
                array_key_exists('due_at', $dates) ? $dates['due_at'] : $task->due_at
            );
            if (is_wp_error($order)) {
                return $order;
            }
        }

        $assigneeIds = null;
        if (array_key_exists('assignees', $params)) {
            $task->load('assignees');
            $currentAssigneeIds = [];
            foreach ($task->assignees as $assignee) {
                $currentAssigneeIds[] = (int) $assignee->ID;
            }

            $assigneeIds = self::readAssigneeIds($params['assignees'], 'assignees', $task->board_id, $currentAssigneeIds);
            if (is_wp_error($assigneeIds)) {
                return $assigneeIds;
            }
        }

        // The contact link is written last and apart from the other fields: the service
        // leaves a display-only attribute on the model, and a later save of it fails.
        $changesContact = array_key_exists('crm_contact_id', $params);
        $contactId = null;
        if ($changesContact) {
            $contactId = $params['crm_contact_id'] === null || $params['crm_contact_id'] === '' ? null : absint($params['crm_contact_id']);
            if ($contactId) {
                $contactCheck = MCPHelper::assertCanLinkContact();
                if (is_wp_error($contactCheck)) {
                    return $contactCheck;
                }
            }
        }

        $coverColor = null;
        if (array_key_exists('cover_color', $params)) {
            $color = trim((string) $params['cover_color']);
            if ($color !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
                return MCPHelper::invalid('cover_color', 'cover_color is a hex colour such as #FFE4B5, or an empty string to remove the colour.');
            }

            $coverColor = $color;
        }

        if (!$changes && !$dates && $assigneeIds === null && !$changesContact && $coverColor === null) {
            return MCPHelper::invalid([], 'Nothing to change: send at least one of title, description, status, priority, due_at, started_at, assignees, crm_contact_id, cover_color.');
        }

        $service = new TaskService();
        $wasClosed = $task->status === 'closed';

        // Dates first, start before due as the app saves them. The app reopens a completed
        // task when its due date is set, so a status sent in the same call must be applied
        // after the dates, or "status closed" would be undone.
        foreach (['started_at', 'due_at'] as $field) {
            if (array_key_exists($field, $dates)) {
                $task = $service->updateTaskProperty($field, $dates[$field], $task);
            }
        }

        foreach ($changes as $field => $value) {
            $task = $service->updateTaskProperty($field, $value, $task);
        }

        if ($wasClosed && array_key_exists('due_at', $dates)) {
            if (!array_key_exists('status', $changes)) {
                MCPHelper::addWarning('The task was completed. Changing its due date reopened it, as it does in the app. To keep it completed, send status "closed" in the same call.');
            } elseif ($changes['status'] === 'closed') {
                // The app reopens the task for the date change; closing it again is a new completion.
                MCPHelper::addWarning('The task was already completed. The app reopened it for the due date change and completed it again, so its completion time is now, and the activity log shows a second completion.');
            }
        }

        if ($coverColor !== null) {
            // Merged into the settings as they are stored now, not as they were when the
            // call started: they also hold the subtask and attachment counts, which another
            // request may have changed meanwhile. The service removes a cover image when a
            // colour is set.
            $stored = Task::find($task->id);
            $settings = $stored && is_array($stored->settings) ? $stored->settings : [];
            $settings['cover'] = isset($settings['cover']) && is_array($settings['cover']) ? $settings['cover'] : [];
            $settings['cover']['backgroundColor'] = $coverColor;
            $task = $service->updateTaskProperty('settings', $settings, $task);
        }

        if ($changesContact) {
            $service->updateTaskProperty('crm_contact_id', $contactId, $task);
            // A clean copy: the service left a display-only attribute on the model.
            $task = Task::find($task->id);
        }

        if ($assigneeIds !== null) {
            $task = self::syncAssignees($task, $assigneeIds);
        }

        return [
            'task' => self::freshRow($task),
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

        if (!MCPHelper::canWriteBoard($sourceBoardId)) {
            return MCPHelper::boardForbidden($sourceBoardId, true);
        }

        if (!MCPHelper::canWriteBoard($targetBoardId)) {
            return MCPHelper::boardForbidden($targetBoardId, true);
        }

        $stage = MCPHelper::assertStageBelongsToBoard($targetStageId, $targetBoardId, 'target_stage_id');
        if (is_wp_error($stage)) {
            return $stage;
        }

        $previousTaskId = !empty($params['previous_task_id']) ? absint($params['previous_task_id']) : 0;
        $nextTaskId = !empty($params['next_task_id']) ? absint($params['next_task_id']) : 0;

        // A neighbour outside the target stage is ignored by the ordering query, and the
        // task would land at the top without a word.
        foreach (['previous_task_id' => $previousTaskId, 'next_task_id' => $nextTaskId] as $field => $neighbourId) {
            if (!$neighbourId) {
                continue;
            }

            $onStage = $neighbourId !== (int) $task->id && Task::where('id', $neighbourId)
                ->where('board_id', $targetBoardId)
                ->where('stage_id', $targetStageId)
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->exists();

            if (!$onStage) {
                return MCPHelper::error('not_found', sprintf(
                    '%s %d is not an active task in stage %d. Use a task id from list-tasks with stage [%d], or send position.',
                    $field,
                    $neighbourId,
                    $targetStageId,
                    $targetStageId
                ), ['fields' => [$field]]);
            }
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

            $recipients = (new NotificationService())->filterAssigneeToSendEmail($task->id, Constant::BOARD_EMAIL_STAGE_CHANGE);
            $service->sendMailAfterTaskModify('stage_change', $recipients, $task, $oldStageId);
        }

        do_action('fluent_boards/task_updated', $task, 'position');

        return [
            'task' => self::freshRow($task),
        ];
    }

    /**
     * Assignee management: add, remove or sync. The one tool for task assignees.
     */
    public static function assignTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::boardForbidden($task->board_id, true);
        }

        $mode = !empty($params['mode']) ? sanitize_text_field($params['mode']) : 'add';
        if (!in_array($mode, ['add', 'remove', 'sync'], true)) {
            return MCPHelper::notOneOf('mode', ['add', 'remove', 'sync']);
        }

        $hasUserIds = array_key_exists('user_ids', $params);
        $field = $hasUserIds ? 'user_ids' : 'user_id';
        $userIds = array_values(array_unique(MCPHelper::sanitizeIdArray($params['user_ids'] ?? [])));
        if (!$hasUserIds && !empty($params['user_id'])) {
            $userIds = [absint($params['user_id'])];
        }

        if (!$userIds && !($mode === 'sync' && $hasUserIds && is_array($params['user_ids']))) {
            return MCPHelper::invalid(['user_id', 'user_ids'], 'Send user_id or user_ids: the WordPress user ids to ' . ($mode === 'remove' ? 'remove' : 'assign') . '. get-board lists the members with their ids.');
        }

        if (count($userIds) > self::MAX_ASSIGNEES_PER_CALL) {
            return MCPHelper::invalid($field, sprintf('%s takes at most %d user ids in one call.', $field, self::MAX_ASSIGNEES_PER_CALL));
        }

        $task->load('assignees');
        $currentIds = [];
        foreach ($task->assignees as $assignee) {
            $currentIds[] = (int) $assignee->ID;
        }

        // updateAssignee() toggles, so only feed it genuine changes.
        if ($mode === 'add') {
            $toAdd = array_diff($userIds, $currentIds);
            $toRemove = [];
        } elseif ($mode === 'remove') {
            $toAdd = [];
            $toRemove = array_intersect($userIds, $currentIds);
        } else {
            $toAdd = array_diff($userIds, $currentIds);
            $toRemove = array_diff($currentIds, $userIds);
        }

        // fbs_relations has no foreign key, so an unknown id would persist as an assignee
        // and a watcher that no user can ever be loaded for.
        $eligible = self::readAssigneeIds($mode === 'remove' ? [] : $userIds, $field, $task->board_id, $currentIds);
        if (is_wp_error($eligible)) {
            return $eligible;
        }

        $service = new TaskService();

        foreach (array_merge($toRemove, $toAdd) as $userId) {
            $service->updateAssignee($userId, $task);
            $task->load('assignees');
        }

        $notAssigned = $mode === 'remove' ? array_values(array_diff($userIds, $currentIds)) : [];
        if ($notAssigned) {
            MCPHelper::addWarning('Not assigned to this task, so not removed: user ' . implode(', ', $notAssigned) . '.');
        }

        return [
            'task_id'   => (int) $task->id,
            'board_id'  => (int) $task->board_id,
            'added'     => array_values($toAdd),
            'removed'   => array_values($toRemove),
            'assignees' => MCPHelper::formatUserList($task->assignees, $task->board_id),
        ];
    }

    public static function archiveTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::boardForbidden($task->board_id, true);
        }

        $archived = array_key_exists('archived', $params) ? (bool) $params['archived'] : true;

        if ($archived === (bool) $task->archived_at) {
            MCPHelper::addWarning($archived ? 'The task was already archived. Nothing changed.' : 'The task was not archived. Nothing changed.');
        } elseif (!$archived && $task->stage && $task->stage->archived_at) {
            // The service refuses this with an exception; say why, and that nothing changed.
            return MCPHelper::error('conflict', sprintf(
                'Task %d cannot be restored: its stage "%s" (id %d) is archived. Restore the stage first with archive-stage archived=false, or move the task with move-task.',
                (int) $task->id,
                $task->stage->title,
                (int) $task->stage->id
            ), ['fields' => ['task_id'], 'stage_id' => (int) $task->stage->id]);
        } else {
            $task = (new TaskService())->updateTaskProperty('archived_at', $archived ? current_time('mysql') : null, $task);
        }

        return [
            'task' => self::freshRow($task),
        ];
    }

    /**
     * Delete a task in two calls. The first returns what will go and a confirm value.
     * As in the app, only a board manager deletes a task for good.
     */
    public static function deleteTask($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!PermissionManager::isBoardManager($task->board_id)) {
            return MCPHelper::error('forbidden', sprintf(
                'Only a manager of board %d can delete a task for good. archive-task takes the task off the board and can be undone.',
                (int) $task->board_id
            ), ['fields' => ['board_id']]);
        }

        if ($task->parent_id) {
            return MCPHelper::invalid('task_id', sprintf(
                '%d is a subtask of task %d. delete-task takes a task; a subtask is deleted with delete-subtask.',
                (int) $task->id,
                (int) $task->parent_id
            ));
        }

        $settings = is_array($task->settings) ? $task->settings : [];
        $subtasks = (int) Task::where('parent_id', $task->id)->count();
        $comments = (int) $task->comments_count;
        $willDelete = MCPHelper::withoutEmpty([
            'task_id'  => (int) $task->id,
            'title'    => $task->title,
            'board'    => $task->board ? $task->board->title : null,
            'stage'    => $task->stage ? $task->stage->title : null,
            'subtasks' => $subtasks,
            'comments' => $comments,
            'files'    => (int) ($settings['attachment_count'] ?? 0),
        ], ['task_id', 'subtasks', 'comments', 'files']);
        // Everything the preview shows: a file, comment or subtask added after it makes
        // the confirm value stop working.
        $fingerprint = MCPHelper::previewFingerprint($willDelete);

        if (!array_key_exists('confirm', $params)) {
            return MCPHelper::confirmationNeeded('delete-task', $willDelete, MCPHelper::confirmValue('delete-task', $task->id, $fingerprint), 'archive-task takes the task off the board and can be undone.');
        }

        if (!MCPHelper::confirmMatches($params['confirm'], 'delete-task', $task->id, $fingerprint)) {
            return MCPHelper::confirmInvalid('delete-task');
        }

        $deleted = ['task_id' => (int) $task->id, 'title' => $task->title, 'board_id' => (int) $task->board_id];
        $fresh = Task::find($task->id);
        $options = null;
        do_action('fluent_boards/before_task_deleted', $fresh, $options);
        (new TaskService())->deleteTask($fresh);

        return ['deleted' => true] + $deleted + ['subtasks' => $subtasks];
    }

    /**
     * The task row a write tool returns: read again, so it shows what was stored.
     */
    private static function freshRow($task)
    {
        $fresh = Task::with(['stage', 'labels', 'assignees'])->find($task->id);

        return MCPHelper::formatTaskRow($fresh ?: $task);
    }

    /**
     * Read a list of user ids that are to be assigned, and refuse the ones that cannot be.
     *
     * @param mixed    $value       The parameter value.
     * @param string   $field       Parameter name, for the error.
     * @param int      $boardId
     * @param int[]    $alreadyIds  Current assignees; they stay eligible.
     * @return int[]|\WP_Error
     */
    private static function readAssigneeIds($value, $field, $boardId, $alreadyIds = [])
    {
        if (!is_array($value)) {
            return MCPHelper::invalid($field, $field . ' must be a list of user ids, for example [12, 40].');
        }

        $userIds = array_values(array_unique(MCPHelper::sanitizeIdArray($value)));
        if (count($userIds) > self::MAX_ASSIGNEES_PER_CALL) {
            return MCPHelper::invalid($field, sprintf('%s takes at most %d user ids in one call.', $field, self::MAX_ASSIGNEES_PER_CALL));
        }

        $newIds = array_values(array_diff($userIds, $alreadyIds));
        if (!$newIds) {
            return $userIds;
        }

        $unknownIds = self::findUnknownUserIds($newIds);
        if ($unknownIds) {
            return MCPHelper::error('not_found', sprintf(
                'No WordPress user has id %s. get-board lists the members of the board with their ids.',
                implode(', ', $unknownIds)
            ), ['fields' => [$field], 'unknown_user_ids' => $unknownIds]);
        }

        $nonMemberIds = self::findIneligibleAssigneeUserIds($newIds, $boardId);
        if ($nonMemberIds) {
            return MCPHelper::invalid($field, sprintf(
                'User %s is not a member of board %d and is not a Fluent Boards admin, so cannot be assigned. Use an id from the members of get-board, or ask a board manager to add the user.',
                implode(', ', $nonMemberIds),
                (int) $boardId
            ), ['non_board_member_user_ids' => array_values($nonMemberIds)]);
        }

        return $userIds;
    }

    /**
     * @return array Ids with no matching WordPress user, in the order supplied.
     */
    private static function findUnknownUserIds($userIds)
    {
        $found = get_users([
            'include' => $userIds,
            'fields'  => 'ID',
            'number'  => count($userIds),
        ]);

        $found = array_map('intval', (array) $found);

        return array_values(array_diff($userIds, $found));
    }

    /**
     * @return array User ids that are neither board members nor global administrators.
     */
    private static function findIneligibleAssigneeUserIds($userIds, $boardId)
    {
        if (!$userIds) {
            return [];
        }

        $memberIds = Relation::where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('object_id', (int) $boardId)
            ->whereIn('foreign_id', $userIds)
            ->pluck('foreign_id')
            ->toArray();

        $memberIds = array_map('intval', $memberIds);

        $ineligibleIds = [];
        foreach (array_diff($userIds, $memberIds) as $userId) {
            if (!PermissionManager::isAdmin($userId)) {
                $ineligibleIds[] = $userId;
            }
        }

        return $ineligibleIds;
    }

    /**
     * Get priority keys allowed by the task priority filter.
     *
     * @return array
     */
    public static function getAllowedTaskPriorities()
    {
        // Only the keys are used here, so the labels are not translated.
        $keys = array_map('strval', array_keys((array) apply_filters('fluent_boards/task_priorities', [
            ''       => 'No priority',
            'urgent' => 'Urgent',
            'high'   => 'High',
            'medium' => 'Medium',
            'low'    => 'Low',
        ])));

        return array_values(array_filter($keys, function ($key) {
            return $key !== '';
        }));
    }

    /**
     * @param \FluentBoards\App\Models\Task $task
     * @param int[]                         $newAssigneeIds
     * @param bool                          $removeOthers False to only add.
     */
    private static function syncAssignees($task, $newAssigneeIds, $removeOthers = true)
    {
        $task->load('assignees');
        $currentIds = [];
        foreach ($task->assignees as $assignee) {
            $currentIds[] = (int) $assignee->ID;
        }

        $service = new TaskService();

        if ($removeOthers) {
            foreach (array_diff($currentIds, $newAssigneeIds) as $removeId) {
                $service->updateAssignee($removeId, $task);
                $task->load('assignees');
            }
        }

        foreach (array_diff($newAssigneeIds, $currentIds) as $addId) {
            $service->updateAssignee($addId, $task);
            $task->load('assignees');
        }

        return $task;
    }
}
