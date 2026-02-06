<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\NotificationUser;
use FluentBoards\App\Models\TaskImage;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\CommentImage;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Modules\TimeTracking\TimeTrackingHelper;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentBoardsPro\App\Services\ProTaskService;
use FluentBoardsPro\App\Services\RemoteUrlParser;
use FluentRoadmap\App\Models\IdeaReaction;

class TaskService
{
    public function createTask($data, $boardId)
    {
        $board = Board::select('id', 'type')->find($boardId);

        if (!$board) {
            throw new \Exception(esc_html__("Board doesn't exists", 'fluent-boards'));
        }

        $stage = Stage::find($data['stage_id']);
        if (!$stage) {
            throw new \Exception(esc_html__("Stage doesn't exists", 'fluent-boards'));
        }

        $data['status'] = $stage->defaultTaskStatus();

        if ($board->type == 'roadmap') {
            $current_user = wp_get_current_user();
            $settingData = array(
                'integration_type' => 'feature',
                'logo'             => '',
                'author'           => [
                    'email' => $current_user->user_email // email of who posted this feature
                ],
            );
            $data['settings'] = $settingData;
            $data['type'] = 'roadmap';
        }

        $providerPosition = Arr::get($data, 'position');

        $data['position'] = $this->getLastPositionOfTasks($stage->id);

        $data['board_id'] = $boardId;

        $data = array_filter($data);
        $task = (new Task())->createTask($data);

        $this->manageDefaultAssignees($task, $stage->id);

        if (isset($data['is_template']) && $data['is_template'] == 'yes') {
            $task->updateMeta(Constant::IS_TASK_TEMPLATE, $data['is_template']);
        }

        if ($providerPosition) {
            $task->moveToNewPosition($providerPosition);
        }

//        $this->taskCreatedAction($task);
        $this->loadWithRelations($task, ['assignees', 'labels', 'board']);

        return $task;
    }

    public function loadWithRelations($task, $relations)
    {
        if (!is_array($relations)) {
            return $task;
        }
        $task->load($relations); // $relations = ['assignees', 'board'] in this case
        $task->isOverdue = $task->isOverdue();

        return $task;
    }

    public function getTasksForBoards($filters = ['overdue', 'upcoming'], $limit = 5, $task_ids = [])
    {
        $assigned = $this->getTasksForBoardsByCategory('assigned', $limit, $task_ids);
        $overDue = $this->getTasksForBoardsByCategory('overdue', $limit, $task_ids);
        $completed = $this->getTasksForBoardsByCategory('completed', $limit, $task_ids);
        $mentioned = $this->getTasksForBoardsByCategory('mentioned', $limit, $task_ids);
        $upcoming = $this->getTasksForBoardsByCategory('upcoming', $limit, $task_ids);
        $others = $this->getTasksForBoardsByCategory('others', $limit, $task_ids);

        return [
            'assigned'            => $assigned ?? [],
            'overdue'             => $overDue ?? [],
            'upcoming'            => $upcoming ?? [],
            'mentioned'           => $mentioned ?? [],
            'completed'           => $completed ?? [],
            'others'              => $others ?? []
        ];
    }

    public function getTasksForBoardsByCategory($category, $limit, $taskIds)
    {
        unset($taskQuery);
        $taskQuery = Task::whereIn('id', $taskIds)
            ->with(['assignees', 'board', 'stage'])
            ->whereNull('archived_at')
            ->where('parent_id', null)
            ->orderBy('due_at', 'DESC');

        switch ($category) {
            case 'overdue':
                $taskQuery->overdue();
                break;
            case 'upcoming':
                $taskQuery->upcoming();
                break;
            case 'others':
                $taskQuery->whereNull('due_at');
                break;
            case 'completed':
                $taskQuery->where('status', 'closed');
                break;
            case 'assigned':
                // Rebuild query to order by latest assignment (pivot created_at) so the most recently assigned tasks come first.
                $currentUserId = get_current_user_id();
                $taskQuery = Task::query()
                    ->select('fbs_tasks.*')
                    ->distinct()
                    ->with(['assignees', 'board', 'stage'])
                    ->whereIn('fbs_tasks.id', $taskIds)
                    ->whereNull('fbs_tasks.archived_at')
                    ->whereNull('fbs_tasks.parent_id')
                    ->join('fbs_relations as rel', function ($join) use ($currentUserId) {
                        $join->on('rel.object_id', '=', 'fbs_tasks.id')
                            ->where('rel.object_type', Constant::OBJECT_TYPE_TASK_ASSIGNEE)
                            ->where('rel.foreign_id', $currentUserId);
                    })
                    ->orderBy('rel.created_at', 'DESC')
                    ->orderBy('fbs_tasks.updated_at', 'DESC');
                break;
            case 'mentioned':
                $currentUserId = get_current_user_id();
                $userNotifications = NotificationUser::where('user_id', $currentUserId)
                    ->with(['notification' => function ($query) {
                        $query->where('action', 'task_comment_mentioned');
                    }])
                    ->orderBy('created_at', 'desc')
                    ->get();
                $taskIds = $userNotifications->filter(function ($userNotification) {
                    $notification = $userNotification->notification;
                    return $notification && $notification->task && is_null($notification->task->archived_at) && is_null($notification->task->parent_id);
                })->pluck('notification.task_id')->unique();
                $validTasks = Task::whereIn('id', $taskIds)
                    ->with(['assignees', 'board', 'stage'])
                    ->get();

                return $validTasks->toArray();
            default:
                return [];
        }

        $tasks = $taskQuery->take($limit)->get();

        return $tasks->toArray();
    }

    /*
      * TODO: Refactor this function - For me.
	*/
    public function updateTaskProperty($col, $value, $task)
    {
        $oldTask = clone $task;  // normal assigning won't work here. because objects are passed by reference in php
        $validColumns = [
            'board_id',
            'type',
//            'reminder_type',
            'remind_at',
            'log_minutes',
            'settings'
        ];

        if (in_array($col, $validColumns) && $task->{$col} != $value) {
            if ($col == 'settings' && isset($value['cover']['backgroundColor']) && $value['cover']['backgroundColor']) {
                $settings = $task->settings;
                $this->deleteTaskCoverImage($settings);
                unset($value['cover']['imageId']);
                unset($value['cover']['backgroundImage']);
            }
            $task->{$col} = $value ?: null;
            $task->save();
            //            do_action('fluent_boards/task_prop_changed', $col, $task, $oldTask);
        } else {
            switch ($col) {
                case 'assignees':
                    if (is_array($value)) {
                        foreach ($value as $id) {
                            $this->updateAssignee($id, $task);
                        }
                    } else {
                        $this->updateAssignee($value, $task);
                    }
                    break;

                case 'crm_contact_id':
                    $this->updateAssociate($value, $task);
                    break;

                case 'archived_at':
                    $this->updateArchive($value, $task);
                    break;

                case 'status':
                    $this->updateStatus($value, $task);
                    break;

                case 'parent_id':
                    $this->updateParent($value, $task);
                    break;

                case 'title':
                    $this->updateTitle($col, $value, $task, $oldTask);
                    break;

                case 'description':
                    $this->updateDescription($col, $value, $task, $oldTask);
                    break;

                case 'due_at':
                    $this->updateDueDate($value, $task);
                    break;

                case 'started_at':
                    $this->updateStartedDate($value, $task);
                    break;

                case 'priority':
                    $this->updatePriority($value, $task);
                    break;

                case 'is_watching':
                    $this->updateObservationOfUser($value, $task);
                    break;

                case 'last_completed_at':
                    $isClosed = $value == 'true' || $value === true;
                    if ($isClosed) {
                        $task = $task->close();
                    } else {
                        $task = $task->reopen();
                    }
                    $task->save();
                    break;

                case 'attachment_count':
                    $settings = $task->settings;
                    $settings['attachment_count'] = $task->attachments()->count();
                    $task->settings = $settings;
                    $task->save();
                    break;

                case 'subtask_count':
                    $settings = $task->settings;
                    $subtasksCount = Task::where('parent_id', $task->id)->count();
                    $settings['subtask_count'] = $subtasksCount;
                    $task->settings = $settings;
                    $task->save();
                    break;

                case 'is_template':
                    if (defined('FLUENT_BOARDS_PRO')) {
                        $task->updateMeta(Constant::IS_TASK_TEMPLATE, $value);
                    }
                    break;

                case 'reminder_type':
                    if (defined('FLUENT_BOARDS_PRO')) {
                        $allowedTypes = Helper::taskReminderTypes();

                        // check in keys of allowed types
                        if (array_key_exists($value, $allowedTypes)) {
                            $value = $value;
                        } else {
                            $value = null;
                        }

                        $task->reminder_type = $value;
                        $task->save();
                        do_action('fluent_boards/task_reminder_type_changed', $task, $value);
                    }
                    break;
            }
        }

        return $task;
    }

    public function updateAssignee($payloadAssigneeId, $task)
    {
        $operation = $task->addOrRemoveAssignee($payloadAssigneeId);
        $task->load('assignees');
        $task->updated_at = current_time('mysql');

        $task->save();

        if ($operation == 'added') {
            if ((new NotificationService())->checkIfEmailEnable($payloadAssigneeId, Constant::BOARD_EMAIL_TASK_ASSIGN, $task->board_id) && $payloadAssigneeId != get_current_user_id()) {
                $this->sendMailAfterTaskModify('add_assignee', $payloadAssigneeId, $task->id);
            }
//            $assigneeIdsToSendEmail = $this->filterAssigneeToSendEmail($task, $idArray, Constant::BOARD_EMAIL_TASK_ASSIGN);
//            $this->sendMailAfterAddAssignees($assigneeIdsToSendEmail, $task->id);
            do_action('fluent_boards/task_assignee_added', $task, $payloadAssigneeId);
            if($payloadAssigneeId != get_current_user_id()){
                do_action('fluent_boards/assign_another_user', $task, $payloadAssigneeId);
            }
        } else {
            if ((new NotificationService())->checkIfEmailEnable($payloadAssigneeId, Constant::BOARD_EMAIL_REMOVE_FROM_TASK, $task->board_id) && $payloadAssigneeId != get_current_user_id()) {
                $this->sendMailAfterTaskModify('remove_assignee', $payloadAssigneeId, $task->id);
            }
            do_action('fluent_boards/task_assignee_removed', $task, $payloadAssigneeId);
        }

    }

//    public function filterAssigneeToSendEmail($task, $newAssigneeIds, $purpose)
//    {
//        $toSendEmail = array();
//        foreach ($newAssigneeIds as $assigneeId) {
//            if ((new NotificationService())->checkIfEmailEnabled($task->board_id, $assigneeId, $purpose)) {
//                $toSendEmail[] = $assigneeId;
//            }
//        }
//        return $toSendEmail;
//    }

//    public function defaultWatchingTaskByNewUsers($task, $newIds)
//    {
//        foreach ($newIds as $newId) {
//            if (!$task->watchers->contains($newId)) {
//                $task->watchers()->attach(
//                    $newId,
//                    [
//                        'object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH,
//                    ]
//                );
//            }
//        }
//    }

//    public function checkIfAnybodyRemovedFromTask($newAssigneeIds, $oldAssigneeIds, $task)
//    {
//        $removedAssignees = array_diff($oldAssigneeIds, $newAssigneeIds);
//        $this->sendMailAfterTaskModify('removed_from_task', $removedAssignees, $task->id);
//        dd($removedAssignees);
//    }

    private function updateAssociate($value, $task)
    {
        // if task has no crm contact and got value null then return current task
        if (($task->crm_contact_id == null || $task->crm_contact_id == 0) && $value == null) {
            return $task;
        }

        $oldAssociateId = $task->crm_contact_id;
        $task->crm_contact_id = $value;
        $task->save();
        $task->contact = Task::lead_contact($task->crm_contact_id);
        do_action('fluent_boards/contact_added_to_task', $task);
        do_action('fluent_boards/associate_user_add_change_remove_activity', $oldAssociateId, $task->crm_contact_id, $task->id);
    }

    private function updateArchive($value, $task)
    {
        if ($value != null) {
            $task->position = 0;
        } else {
            $task->moveToNewPosition(1);
        }
        $task->archived_at = $value == null ? null : current_time('mysql');
        $task->save();
        do_action('fluent_boards/task_archived', $task);
        $watchersToSendEmail = (new NotificationService())->filterAssigneeToSendEmail($task->id, Constant::BOARD_EMAIL_TASK_ARCHIVE);
        $this->sendMailAfterTaskModify('task_archived', $watchersToSendEmail, $task->id);
    }

    private function updateStatus($value, $task)
    {
        if ($value == 'closed') {
            $task = $task->close();
        } else {
            $task = $task->reopen();
        }

        do_action('fluent_boards/task_completed_activity', $task, $value);
    }

    private function updateParent($value, $task)
    {
        $task->parent_id = $value;
        $task->save();
    }

    private function updateTitle($col, $value, $task, $oldTask)
    {
        $task->title = $value;
        $task->save();
        do_action('fluent_boards/task_content_updated', $task, $col, $oldTask);
    }

    private function updateDescription($col, $value, $task, $oldTask)
    {
        $task->description = $value;
        $task->save();
        do_action('fluent_boards/task_content_updated', $task, $col, $oldTask);
    }

    private function updateDueDate($value, $task)
    {
        $oldValue = $task->due_at;
        $value = $this->filterNullDate($value);
        $task->due_at = $value;
        $task->save();

        $task = $task->reopen();

        if($value){
            do_action('fluent_boards/task_due_date_changed', $task, $oldValue);
        } else {
            do_action('fluent_boards/task_due_date_removed', $task);
        }

        $wathersToSendEmail = (new NotificationService())->filterAssigneeToSendEmail($task->id, Constant::BOARD_EMAIL_DUE_DATE_CHANGE);
        $this->sendMailAfterTaskModify('due_date_update', $wathersToSendEmail, $task->id);
    }

    private function updateStartedDate($value, $task)
    {
        $oldValue = $task->started_at;
        $value = $this->filterNullDate($value);
        $task->started_at = $value;
        $task->save();

        if($value){
            do_action('fluent_boards/task_start_date_changed', $task, $oldValue);
        }
    }

    private function updatePriority($value, $task)
    {
        $oldPriority = $task->priority;
        $task->priority = $value;
        $task->save();
        do_action('fluent_boards/task_priority_changed', $task, $oldPriority);
    }

    public function updateObservationOfUser($value, $task)
{
    if (is_array($value) && isset($value['userId'])) {
        $userId = intval($value['userId']);
        $action = isset($value['action']) ? $value['action'] : 'start';
    } else {
        $userId = get_current_user_id();
        $action = is_string($value) ? $value : 'start';
    }

    if (!$userId || !in_array($action, ['stop', 'start'])) {
        return;
    }

    if ($action == 'stop') {
        $task->watchers()->detach($userId);
    } else {
        $task->watchers()->syncWithoutDetaching([$userId => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
    }
    $task->updated_at = current_time('mysql');
    $task->save();
}

    public function taskCoverPhotoUpdate($taskId, $imagePath)
    {
        $task = Task::find($taskId);
        if (!$task) {
            return null;
        }

        $settings = $task->settings;
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['logo'] = $imagePath;
        $task->settings = $settings;
        $task->save();

        return $task;
    }

    public function taskStatusUpdate($taskId, $integrationType)
    {
        $task = Task::find($taskId);
        if (!$task) {
            return null;
        }

        $settings = $task->settings;
        $settings['integration_type'] = $integrationType;
        $task->settings = $settings;
        $task->save();

        return $task;
    }

    public function assignYourselfInTask($boardId, $taskId)
    {
        $task = Task::find($taskId);
        $authUserId = get_current_user_id();

        $boardService = new BoardService();
        if (!$boardService->isAlreadyMember($boardId, $authUserId)) {
            $boardService->addMembersInBoard($boardId, $authUserId);
        }

        $task->addOrRemoveAssignee($authUserId);
        // when user assign himself then he will be watching that task
        $task->watchers()->syncWithoutDetaching([$authUserId => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);

        $task->load('assignees');
        do_action('fluent_boards/task_assignee_added', $task, $authUserId);

        return $task;
    }

    public function detachYourselfFromTask($boardId, $taskId)
    {
        $task = Task::find($taskId);
        $currentUserId = get_current_user_id();
        $task->addOrRemoveAssignee($currentUserId);
        $task->load('assignees');
        do_action('fluent_boards/task_assignee_removed', $task, $currentUserId);

        return $task;
    }

    public function deleteTask($task)
    {
        // If this is a parent task, delete all subtasks first
        if (!$task->parent_id) {
            $subtasks = Task::where('parent_id', $task->id)->get();
            foreach ($subtasks as $subtask) {
                // Recursively delete each subtask (cleans up all their relations)
                $this->deleteTask($subtask);
            }
        }

        $deleted = $task->delete();
        $dbInstance = App::getInstance('db');
        $dbInstance->beginTransaction();

        $deletedTask = clone $task;
         //cloning because after delete $task object will be useless

        try {
            $deleted = $task->delete();

            if ($deleted) {
                //task assignees watchers removed
                $task->watchers()->detach();
                $task->assignees()->detach();

                //removing all task related notifications
                $notificationIds = $task->notifications->pluck('id');
                $task->notifications()->delete();
                NotificationUser::whereIn('notification_id', $notificationIds)->delete();

                //task labels removed
                $task->labels()->detach();

            //task custom field value
            if (defined('FLUENT_BOARDS_PRO')) {
                $task->customFields()->detach();
            }
            $this->deleteTaskAttachments($task);
                //task custom field value
                if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                    $task->customFields()->detach();
                    $this->deleteTaskAttachments($task);
                }

            // Delete time tracking records for this task
            $this->deleteTimeTrackingRecords($task->id);

            do_action('fluent_boards/task_deleted', $task);
            TaskMeta::where('task_id', $task->id)->delete();
                do_action('fluent_boards/task_deleted', $deletedTask);
                TaskMeta::where('task_id', $task->id)->delete();
            }

            $dbInstance->commit();
        } catch (\Exception $e) {
            $dbInstance->rollBack();
            throw $e; // Re-throw the exception after rolling back
        }

    }
    public function deleteTaskForBulk($task)
    {
        // If this is a parent task, delete all subtasks first
        if (!$task->parent_id) {
            $subtasks = Task::where('parent_id', $task->id)->get();
            foreach ($subtasks as $subtask) {
                // Recursively delete each subtask (cleans up all their relations)
                $this->deleteTaskForBulk($subtask);
            }
        }

        $deleted = $task->delete();

        if ($deleted) {

            //task assignees watchers removed
            $task->watchers()->detach();
            $task->assignees()->detach();

            //removing all task related notifications
            $notificationIds = $task->notifications->pluck('id');
            $task->notifications()->delete();
            NotificationUser::whereIn('notification_id', $notificationIds)->delete();

            //task labels removed
            $task->labels()->detach();

            //task custom field value
             if (defined('FLUENT_BOARDS_PRO_VERSION')) {
                $task->customFields()->detach();
                $this->deleteTaskAttachments($task);
            }

            // For bulk delete, you might want to avoid firing hooks/actions,
            // so 'fluent_boards/task_deleted' is not triggered here.
            TaskMeta::where('task_id', $task->id)->delete();
        }
    }
    public function filterNullDate($date)
    {
        if ('0000-00-00 00:00:00' == $date || false === strtotime($date)) {
            return null;
        }
        return $date;
    }

    // this is invoked when task is moved to another board

    /**
     * @throws \Exception
     */
    public function changeBoardByTask($task, $targetBoardId)
    {
        // Input validation - must be positive integer
        if (!is_numeric($targetBoardId) || $targetBoardId <= 0 || !is_int($targetBoardId + 0) || $targetBoardId != (int)$targetBoardId) {
            throw new \Exception(esc_html__('Invalid board id - must be a positive integer', 'fluent-boards'), 400);
        }

        
        if ($task->board_id == $targetBoardId) {
            return $task;
        }

        $oldBoard = Board::find($task->board_id);
        $newBoard = Board::find($targetBoardId);

        if (!$oldBoard) {
            throw new \Exception(esc_html__('Source board not found', 'fluent-boards'), 404);
        }
        
        if (!$newBoard) {
            throw new \Exception(esc_html__('Target board not found', 'fluent-boards'), 404);
        }


        $task->board_id = (int) $targetBoardId;
        $task->type = $newBoard->type === 'roadmap' ? 'roadmap' : 'task';

        // Remove task cover if it contains image URL for security
        $this->removeTaskCoverImage($task);

        // REMOVE: Board-dependent data
        $task->labels()->detach();
        $task->assignees()->detach();
        $task->watchers()->detach();
        $this->removeCustomFieldAssociations($task);

        // REMOVE: User-specific data to prevent security issues
        $this->removeCommentsAndReplies($task->id);
        $this->removeTimeTrackingRecords($task->id);

        // REMOVE: File attachments to prevent access issues
        $this->removeAttachments($task->id);

        // REMOVE: Recurring task settings for security
        $this->removeRecurringTaskSettings($task->id);

        $task->save();

        // MOVE: Subtasks to new board (preserves subtask groups)
        $this->moveSubtasksToNewBoard($task->id, $targetBoardId, $newBoard->type);

        do_action('fluent_boards/task_moved_from_board', $task, $oldBoard, $newBoard);
        return $task;
    }

    /**
     * Move all subtasks to the new board when parent task is moved
     * Preserves subtask groups and their relationships
     */
    private function moveSubtasksToNewBoard($parentTaskId, $targetBoardId, $boardType)
    {
        // Get all subtasks of the parent task
        $subtasks = Task::where('parent_id', $parentTaskId)->get();

        if ($subtasks->isEmpty()) {
            return;
        }

        foreach ($subtasks as $subtask) {
            // Update board_id and type
            $subtask->board_id = (int) $targetBoardId;
            $subtask->type = $boardType === 'roadmap' ? 'roadmap' : 'task';

            // Remove task cover image for security
            $this->removeTaskCoverImage($subtask);

            // REMOVE: Board-dependent data for subtasks
            $subtask->labels()->detach();
            $subtask->assignees()->detach();
            $subtask->watchers()->detach();

            // Remove custom fields but preserve subtask group relationships
            $subtask->taskMeta()
                ->where('key', '!=', Constant::SUBTASK_GROUP_CHILD)
                ->delete();

            // REMOVE: User-specific data for security
            $this->removeCommentsAndReplies($subtask->id);
            $this->removeTimeTrackingRecords($subtask->id);

            // REMOVE: File attachments
            $this->removeAttachments($subtask->id);

            // REMOVE: Recurring task settings
            $this->removeRecurringTaskSettings($subtask->id);

            $subtask->save();
        }
    }

    /**
     * Remove task cover image for security reasons
     * Keeps background colors but removes image references
     */
    private function removeTaskCoverImage($task)
    {
        $settings = $task->settings;
        if (empty($settings) || !is_array($settings)) {
            return;
        }

        if (isset($settings['cover']) && is_array($settings['cover'])) {
            $cover = $settings['cover'];

            // Remove image references
            unset($cover['imageId']);
            unset($cover['backgroundImage']);

            // Keep only background color if it exists
            if (isset($cover['backgroundColor'])) {
                $settings['cover'] = array('backgroundColor' => $cover['backgroundColor']);
            } else {
                unset($settings['cover']);
            }

            $task->settings = $settings;
        }
    }

    /**
     * Remove custom field associations for board move
     * Custom field values are stored in fbs_relations table, not fbs_task_meta
     * This method removes task-to-customfield associations from fbs_relations
     */
    private function removeCustomFieldAssociations($task)
    {
        // Remove custom field values from fbs_relations table
        // Custom fields are board-specific, so they must be removed when task moves to different board
        if (defined('FLUENT_BOARDS_PRO')) {
            $task->customFields()->detach();
        }
    }

    /**
     * Remove comments and replies for security reasons
     * Prevents exposing user-specific data to unauthorized users
     */
    private function removeCommentsAndReplies($taskId)
    {
        // Input validation
        if (!is_numeric($taskId) || $taskId <= 0) {
            return;
        }
        
        // Remove all comments and replies for this task (delete individually to fire model events and clean up images)
        $comments = Comment::where('task_id', (int) $taskId)->get();
        foreach ($comments as $comment) {
            $comment->delete();
        }

    }

    /**
     * Remove time tracking records for security reasons
     * Prevents exposing user-specific time data to unauthorized users
     */
    private function removeTimeTrackingRecords($taskId)
    {
        // Input validation
        if (!is_numeric($taskId) || $taskId <= 0) {
            return;
        }

        // Remove all time tracking records for this task
        $this->deleteTimeTrackingRecords((int) $taskId);
    }

    /**
     * Remove attachments for security reasons
     * Prevents file access issues across boards
     */
    private function removeAttachments($taskId)
    {
        // Input validation
        if (!is_numeric($taskId) || $taskId <= 0) {
            return;
        }

        // Remove all attachments for this task
        if (class_exists('FluentBoardsPro\App\Models\TaskAttachment')) {
            \FluentBoardsPro\App\Models\TaskAttachment::where('object_id', (int) $taskId)
                ->where('object_type', 'task')
                ->delete();
        }
    }

    /**
     * Remove recurring task settings for security reasons
     * Prevents recurring task settings from being moved between boards
     */
    private function removeRecurringTaskSettings($taskId)
    {
        // Input validation
        if (!is_numeric($taskId) || $taskId <= 0) {
            return;
        }

        // Remove recurring task settings for this task from fbs_metas table
        Meta::where('object_id', (int) $taskId)
            ->where('object_type', Constant::REPEAT_TASK_META)
            ->delete();
    }

    public function getIdeaVoteStatistics($taskId)
    {
        return IdeaReaction::where('object_id', $taskId)
            ->where('object_type', 'idea')
            ->where('type', 'upvote')
            ->count();
    }


    /**
     * Summary of getArchivedOrCompletedTasks
     * this function will return completd tasks or archived tasks based on users input and also can search by name
     * @param mixed $data
     * @param mixed $taskType
     * @return mixed
     * @throws \Exception
     */
    public function getArchivedTasks($data, $boardId)
    {
        $per_page = isset($data['per_page']) ? $data['per_page'] : 25;
        $page = isset($data['page']) ? $data['page'] : 1;
        $tasksQuery = Task::where('board_id', $boardId)->whereNotNull('archived_at');

        if (isset($data['searchInput'])) {
            $query = strtolower($data['searchInput']);
            $firstThreeChars = substr($query, 0, 3);

            if($firstThreeChars == 'id:') {
                $idPart = substr($query, 3);
                $idPart = preg_replace('/[^a-zA-Z0-9]/', '', $idPart);
                $tasksQuery = $tasksQuery->where('id', 'LIKE', '%' . $idPart . '%');
            } else {
                $tasksQuery = $tasksQuery->where('title', 'LIKE', '%' . $data['searchInput'] . '%');
            }
        }

        // if board_id is not passed then throw an exception
        if (!$boardId) {
            throw new \Exception(esc_html__('Board id is required', 'fluent-boards'));
        }

        return $tasksQuery->orderBy('created_at', 'DESC')->with('assignees')->paginate($per_page, ['*'], 'page', $page);
    }

    public function sendMailAfterTaskModify($column, $assigneeIds, $taskId)
    {
        $current_user_id = get_current_user_id();
        /* this will run in background as soon as possible */
        /* sending Model or Model Instance won't work here */

        as_enqueue_async_action('fluent_boards/one_time_schedule_send_email_for_'.$column, [$taskId, $assigneeIds, $current_user_id], 'fluent-boards');
    }

    public function getStageByTask($task_id)
    {
        $task = Task::find($task_id);
        return $task->stage;
    }

    public function moveTaskToNextStage($task_id)
    {
        $task = Task::findOrFail($task_id);

        $oldStage = $task->stage;

        $nextStage = Stage::where('board_id', $task->board_id)
            ->where('position', '>', $oldStage->position)
            ->orderBy('position', 'ASC')
            ->first();

        if (!$nextStage) {
            return $task;
        }

        if ($nextStage->defaultTaskStatus() == 'closed' && $task->status != 'closed') {
            $task->status = 'closed';
            if (!$task->last_completed_at) {
                $task->last_completed_at = current_time('mysql');
            }
        }

        $task->stage_id = $nextStage->id;
        $task->save();

        $task->load(['board', 'stage', 'attachments']);

        $task = $this->loadNextStage($task);

        return $task;
    }

    public function loadNextStage($task)
    {
        $stage = $task->stage;
        $nextStage = Stage::where('board_id', $task->board_id)
            ->where('position', '>', $stage->position)
            ->orderBy('position', 'ASC')
            ->first();

        $task->nextStage = $nextStage ? $nextStage->title : null;
        return $task;
    }

    public function getActivities($taskId, $perPage, $filter = 'newest')
    {
        $activityQuery = Activity::where('object_id', $taskId)
            ->where('object_type', Constant::ACTIVITY_TASK);
        if ($filter == 'newest') {
            $activityQuery = $activityQuery->latest();
        } else if ($filter == 'oldest') {
            $activityQuery = $activityQuery->oldest();
        }
        return $activityQuery->with('user')->paginate($perPage);
    }

    public function getLastOneMinuteUpdatedTasks($boardId, $lastUpdated = null)
    {
        if (!$lastUpdated) {
            $lastUpdated = gmdate('Y-m-d H:i:s', current_time('timestamp') - 60); // 1 minute ago
        }

        $tasks = Task::query()
            ->where([
                'board_id'  => $boardId,
                'parent_id' => null,
            ])
            ->where('updated_at', '>', $lastUpdated) // updated in the last minute
            ->with(['assignees', 'labels', 'watchers', 'taskCustomFields'])
            ->orderBy('due_at', 'ASC')
            ->get();

        foreach ($tasks as $task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->is_watching = $task->isWatching();
            $task->contact = Task::lead_contact($task->crm_contact_id);
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
            $task->watchers = Helper::sanitizeUserCollections($task->watchers);
        }
        return $tasks;
    }

    public function getLastPositionOfTasks($stage_id)
    {
        $lastPosition = Task::query()
            ->where('stage_id', $stage_id)
            ->where('parent_id', null)
            ->whereNull('archived_at')
            ->orderBy('position', 'desc')
            ->pluck('position')
            ->first();

        return $lastPosition + 1;
    }

    public function getAssociatedTasks($associatedId)
    {
        $tasks = Task::query()
            ->where('crm_contact_id', $associatedId)
            ->with(['board', 'stage', 'assignees', 'labels', 'watchers', 'subtaskGroup', 'subtaskGroup.subtasks', 'subtaskGroup.subtasks.assignees'])
            ->orderBy('due_at', 'ASC')
            ->get();

        foreach ($tasks as $task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->contact = Task::lead_contact($task->crm_contact_id);
            $task->is_watching = $task->isWatching();

            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
            $task->watchers = Helper::sanitizeUserCollections($task->watchers);

            if(defined('FLUENT_BOARDS_PRO')) {
                $modules = fluent_boards_get_pref_settings();
                if($modules['timeTracking']['enabled'] == 'yes') {
                    $task->time_tracks= [
                        'tracks'            => (new ProTaskService())->getTaskTimeTrack($task->board_id, $task->id),
                        'estimated_minutes' => TimeTrackingHelper::getTaskEstimation($task->id)
                    ];
                }
            }


//            $subTasks = Task::query()
//                ->where('parent_id', $task->id)
//                ->with(['assignees'])
//                ->whereNull('archived_at')
//                ->orderBy('position', 'ASC')
//                ->get();
//
//            foreach ($subTasks as $subTask) {
//                $subTask->assignees = Helper::sanitizeUserCollections($subTask->assignees);
//            }
//
//            $task->subtasks = $subTasks;


            foreach ($task->subtaskGroup as $group) {
                foreach ($group->subtasks as $subtask) {
                    $subtask->assignees = Helper::sanitizeUserCollections($subtask->assignees);
                }
            }
            $task->subtask_group = $task->subtaskGroup;
        }

        return $tasks;
    }

    public function copySubtaskGroup($task, $newTask, $subtaskGroupMap)
    {
        $subtaskGroups = TaskMeta::where('task_id', $task->id)->where('key', Constant::SUBTASK_GROUP_NAME)->get();
        foreach ($subtaskGroups as $group) {
            $newGroup = TaskMeta::create([
                'task_id' => $newTask->id,
                'key' => Constant::SUBTASK_GROUP_NAME,
                'value' => $group->value
            ]);

            $subtaskGroupMap[$group->id] = $newGroup->id;
        }

        return $subtaskGroupMap;
    }

    public function copyTasks($boardId, $stageMap, $newBoard, $labelMap = [],$isWithTemplates='no')
    {
        $allActiveTasks = Task::where('board_id', $boardId)->whereNull('archived_at')->get();
        $taskMap = [];
        $subtaskGroupMap = [];
        $parentTaskCount = 0;
        foreach ($allActiveTasks as $task) {
            $newTask = array();
            $newTask['title'] = $task->title;
            $newTask['parent_id'] = $task->parent_id ? $taskMap[$task->parent_id] : null;
            $newTask['description'] = $task->description;
            $newTask['board_id'] = $newBoard->id;
            $newTask['stage_id'] = $stageMap[$task->stage_id];
            $newTask['status'] = $task->status;
            $newTask['priority'] = $task->priority;
            $newTask['position'] = $task->position;
            $newTask['due_at'] = $task->due_at;
            $backgroundColor = '';
            $backgroundColor = $task->settings['cover']['backgroundColor'];
            $newTask['settings'] = [
                'cover' => [
                    'backgroundColor' => $backgroundColor,
                ]
            ];
            
            $newTask = Task::create($newTask);

            if (!$task->parent_id) {
                //group mapping
                $subtaskGroupMap = $this->copySubtaskGroup($task, $newTask, $subtaskGroupMap);
            } else {
                $groupRelationOfTask = TaskMeta::where('key', Constant::SUBTASK_GROUP_CHILD)
                                            ->where('task_id', $task->id)
                                            ->first();

                if ($groupRelationOfTask && $subtaskGroupMap[$groupRelationOfTask->value]) {
                    TaskMeta::create([
                        'task_id' => $newTask->id,
                        'key' => Constant::SUBTASK_GROUP_CHILD,
                        'value' => $subtaskGroupMap[$groupRelationOfTask->value]
                    ]);
                }
            }

            if($isWithTemplates == 'yes') {
                $isTemplate = TaskMeta::where('task_id', $task->id)
                    ->where('key', 'is_template')
                    ->first();
                if($isTemplate) {
                    TaskMeta::create([
                        'task_id' => $newTask->id,
                        'key' => 'is_template',
                        'value' => $isTemplate->value
                    ]);
                }
            }
            if(!$task->parent_id){
                ++$parentTaskCount;
                $taskMap[$task['id']] = $newTask->id;
                //duplicate labels to task
                $labelIds = $task->labels->pluck('id')->toArray();
                if($labelIds){
                    $flipLabelIds = array_flip($labelIds);
                    $labelsToAttach = array_intersect_key($labelMap, $flipLabelIds);

                    $newTask->labels()->attach($labelsToAttach, [
                        'object_type' => Constant::OBJECT_TYPE_TASK_LABEL
                    ]);
                }
            }
        }

        $board = Board::findOrFail($newBoard->id);
        $settings = [];
        $settings['tasks_count'] = $parentTaskCount;
        $board->settings = $settings;
        $board->save();
    }

    private function subtaskCountUpdate($taskId){
        $parentTask = Task::findOrFail($taskId);
        $settings = $parentTask->settings;
        $settings['subtask_count'] = (int)($settings['subtask_count'] ?? 0) + 1;
        $parentTask->settings = $settings;
        $parentTask->save();
    }

    /**
     * @param $taskId
     * @param $perPage
     * @param $offset
     * @param string $filter
     * @return array
     */
    public function getCommentsAndActivities($taskId, $perPage, $page, string $filter = 'newest'): array
    {
        // Fetch the task
        $task = Task::findOrFail($taskId);

        // Fetch comments and activities separately
        $comments = $task->comments()->with('user')->orderBy('created_at', 'desc')->get()->toArray();
        $activities = $task->activities()
            ->with('user')
            ->where(function($query) {
                $query->whereNotIn('column', [ 'comment', 'a reply'])
                    ->orWhere(function($subQuery) {
                        $subQuery->whereNotIn('action', ['added', 'updated']);
                    });
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();



        // Merge comments and activities into a single array
        $commentsAndActivities = array_merge($comments, $activities);

        // Sort the merged array by created_at date in ascending or descending order
        $order = $filter == 'newest' ? -1 : 1;
        usort($commentsAndActivities, function ($a, $b) use ($order) {
            return $order * (strtotime($a['created_at']) - strtotime($b['created_at']));
        });

        // Paginate the results
        $offset = ($page - 1) * $perPage; // Calculate the offset for slicing the array
        $paginatedResults = array_slice($commentsAndActivities, $offset, $perPage);

        // Get the total count of comments and activities
        $total = count($commentsAndActivities);
        $lastPage = (int) ceil($total / $perPage);

        // Construct pagination metadata
        $path = "https://wordpress.test/wp-json/fluent-boards/v2/projects/{$task->board_id}/tasks/{$task->id}/comments-and-activities";
        return [
            'current_page' => (int) $page,
            'data' => $paginatedResults,
            'first_page_url' => "{$path}?page=1",
            'from' => $total > 0 ? (int) ($offset + 1) : null,
            'last_page' => (int) $lastPage,
            'last_page_url' => "{$path}?page={$lastPage}",
            'links' => [
                [
                    'url' => $page > 1 ? "{$path}?page=" . ($page - 1) : null,
                    'label' => 'pagination.previous',
                    'active' => false
                ],
                [
                    'url' => "{$path}?page={$page}",
                    'label' => (int) $page,
                    'active' => true
                ],
                [
                    'url' => $page < $lastPage ? "{$path}?page=" . ($page + 1) : null,
                    'label' => 'pagination.next',
                    'active' => false
                ]
            ],
            'next_page_url' => $page < $lastPage ? "{$path}?page=" . ($page + 1) : null,
            'path' => $path,
            'per_page' => (int) $perPage,
            'prev_page_url' => $page > 1 ? "{$path}?page=" . ($page - 1) : null,
            'to' => $total > 0 ? (int) min($offset + $perPage, $total) : null,
            'total' => (int) $total
        ];
    }

    /**
     * @param $task_id
     * @param $fileData
     * @param $type
     * @return Attachment
     */
    public function uploadMediaFileFromWpEditor($task_id, $fileData, $type)
    {
        $initialDataData = [
            'type' => 'url',
            'url' => '',
            'name' => '',
            'size' => 0,
        ];

        $attachData = array_merge($initialDataData, $fileData);
        $UrlMeta = [];
        if($attachData['type'] == 'url') {
            $UrlMeta = RemoteUrlParser::parse($attachData['url']);
        }
        $attachment = new TaskImage();
        $attachment->object_id = $task_id;
        $attachment->object_type = $type;
        $attachment->attachment_type = $attachData['type'];
        $attachment->title = $this->setTitle($attachData['type'], $attachData['name'], $UrlMeta);
        $attachment->file_path = $attachData['type'] != 'url' ?  $attachData['file'] : null;
        $attachment->full_url = esc_url($attachData['url']);
        $attachment->file_size = $attachData['size'];
        $attachment->settings = $attachData['type'] == 'url' ? [
            'meta' => $UrlMeta
        ] : '';
        $attachment->driver = 'local';
        $attachment->save();
        return $attachment;
    }


    /**
     * @param $type
     * @param $title
     * @param $UrlMeta
     * @return mixed|string
     */
    public function setTitle($type, $title, $UrlMeta)
    {
        if($type != 'url') {
            return sanitize_file_name($title);
        }
        return $title ?? $UrlMeta['title'] ?? '';
    }

    public function manageDefaultAssignees($task, $stageId)
    {
        $stage = Stage::findOrFail($stageId);
        if ($stage && isset($stage->settings['default_task_assignees'])) {
            $defaultAssignees = $stage->settings['default_task_assignees'];
            foreach ($defaultAssignees as $assigneeId) {
                $alreadyAssigneeIds = $task->assignees->pluck('ID')->toArray();
                $IfAlreadyAssignee = in_array($assigneeId, $alreadyAssigneeIds);
                if (!$IfAlreadyAssignee) {
                    $this->updateAssignee($assigneeId, $task);
                }
            }
        }
    }

    public function setDefaultAssigneesToEveryTasks($stage)
    {
        $tasks = $stage->tasks->whereNull('archived_at');
        foreach ($tasks as $task) {
            $this->manageDefaultAssignees($task, $stage->id);
        }
    }

    public function createTaskFromImage($board_id, $stage_id, $uploadInfo, $file)
    {

        $board = Board::find($board_id);
        $task = new Task();
        $taskType = $board->type === 'to-do' ? 'task' : 'roadmap' ;
        $taskData = [
            'title' => $uploadInfo[0]['name'],
            'board_id' => $board_id,
            'stage_id' => $stage_id,
            'type' => $taskType,
        ];
        $task->fill($taskData);
        $task->save();

        $fileData = $uploadInfo[0];
        $fileUploadedData = $this->uploadMediaFileFromWpEditor($task->id, $fileData, Constant::TASK_DESCRIPTION);
        if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
            $mediaData = (new AttachmentService())->processMediaData($fileData, $file);
            $fileUploadedData['driver'] = $mediaData['driver'];
            $fileUploadedData['file_path'] = $mediaData['file_path'];
            $fileUploadedData['full_url'] = $mediaData['full_url'];
            $fileUploadedData->save();
        }

        $settings = $task->settings;
        $settings['cover'] = [
            'imageId' => $fileUploadedData['id'],
            'backgroundImage' => (new CommentService())->createPublicUrl($fileUploadedData, $board_id),
        ];
        $task->settings = $settings;
        $task = $task->moveToNewPosition(1);
        $task->save();
        $task->load(['board', 'stage', 'labels', 'assignees']);

        $task->assignees = Helper::sanitizeUserCollections($task->assignees);

        $task->isOverdue = $task->isOverdue();
        $task->contact = Task::lead_contact($task->crm_contact_id);
        $task->board->stages = (new StageService())->stagesByBoardId($board_id);
        $task->is_watching = (new NotificationService())->isCurrentUserObservingTask($task);

        $task = $this->loadNextStage($task);

        if ($task->type == 'roadmap') {
            $task->vote_statistics = $this->getIdeaVoteStatistics($task->id);
        }

        return $task;
    }
    public function deleteTaskCoverImage($settings)
    {
        if (isset($settings['cover']['imageId']) && $settings['cover']['imageId']) {
            $image = TaskImage::find($settings['cover']['imageId']);
            if ($image) {
                $deletedImage = clone $image;
                $deletedImage->delete();

                do_action('fluent_boards/task_attachment_deleted', $deletedImage);
            }
        }

    }

    private function deleteTaskAttachments($task)
    {
        $attachments = TaskAttachment::where('object_id', $task->id)
            ->where('object_type', Constant::TASK_ATTACHMENT)
            ->get();
        foreach ($attachments as $attachment) {
            $deletedAttachment = clone $attachment;
            $attachment->delete();

            do_action('fluent_boards/task_attachment_deleted', $deletedAttachment);
        }
    }

    public function cloneTask(int $taskId, $taskData): Task
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic task cloning operation
        $wpdb->query('START TRANSACTION');

        try {
            // Load task with all necessary relationships
            $task = Task::with([
                'assignees',
                'labels',
                'watchers',
            ])->findOrFail($taskId);

            // Create new task with cloned data
            $clonedTask = $task->replicate();
            $clonedTask->title = $taskData['title'] ?? $task->title . ' (' . \__('cloned', 'fluent-boards') . ')';

            $settings = $clonedTask->settings ?? [];

            unset(
                $settings['attachment_count'],
                $settings['subtask_completed_count'],
                $settings['subtask_count']
            );
            $clonedTask->settings = $settings;
            $clonedTask->stage_id = $taskData['stage_id'] ?? $task->stage_id;
            
            // Validate that target stage belongs to the same board
            $targetStage = Stage::findOrFail($clonedTask->stage_id);
            if ($task->board_id != $targetStage->board_id) {
                throw new \Exception(esc_html__('Cannot clone task to a different board. Task and target stage must be on the same board.', 'fluent-boards'));
            }
            
            $clonedTask->board_id = $targetStage->board_id;
             
            $clonedTask->comments_count = 0; // Reset comments count for cloned task
            $clonedTask->save();

            $positionIndex = 1; // Default position index for new task
            if($task->stage_id === $clonedTask->stage_id) {
                // Calculate position for the cloned task next to original task
                $positionIndex = $this->calculateClonedTaskPosition($task);
            } 
            // Move cloned task to the new position
            $clonedTask->moveToNewPosition($positionIndex);

            $this->cloneTaskMeta($task, $clonedTask);

            $this->cloneTaskCustomFields($task, $clonedTask);

            // Apply stage default assignees if any are set
            $this->manageDefaultAssignees($clonedTask, $clonedTask->stage_id);

            if($taskData['assignee']) {
                $this->cloneAssignees($task, $clonedTask);
            }
            if($taskData['label']) {
                $this->cloneTaskLabels($task, $clonedTask);
            }
            $this->cloneTaskWatchers($task, $clonedTask);

            if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                // Clone time tracking data if Pro version is active
                if ($taskData['attachment']) {
                    $this->cloneAttachments($task, $clonedTask);
                }
                if ($taskData['subtask']) {
                    $this->cloneSubtasks($task, $clonedTask);
                }
            }

            if($taskData['comment']) {
                $this->cloneCommentsAndReplies($task, $clonedTask);
            }

            // Load and prepare the cloned task for response
            $clonedTask = $this->prepareClonedTaskForResponse($clonedTask);
            do_action('fluent_boards/task_cloned', $task, $clonedTask);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic task cloning operation
            $wpdb->query('COMMIT');
            return $clonedTask;

        } catch (\Exception $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control for atomic task cloning operation
            $wpdb->query('ROLLBACK');
            throw new \Exception(
                esc_html(\__('Failed to clone task: ', 'fluent-boards') . $e->getMessage()),
                (int) ($e->getCode() ?: 500)
            );
        }
    }

    private function calculateClonedTaskPosition(Task $originalTask): int
    {
        $tasks = Task::where('stage_id', $originalTask->stage_id)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get();

        $index = $tasks->search(function($task) use ($originalTask) {
            return $task->id === $originalTask->id;
        });

        return $index !== false ? $index + 2 : 1; // Return 1-based index
    }

    private function cloneTaskMeta(Task $originalTask, Task $clonedTask): void
    {
        $taskMetas = TaskMeta::where('task_id', $originalTask->id)
            ->where('key', '!=', Constant::SUBTASK_GROUP_NAME)
            ->get();
        foreach ($taskMetas as $meta) {
            TaskMeta::create([
                'task_id' => $clonedTask->id,
                'key' => $meta->key,
                'value' => $meta->value
            ]);
        }
    }

    private function cloneAssignees($originalTask, $clonedTask)
    {
        // Clone assignees
        if ($originalTask->assignees) {
            foreach ($originalTask->assignees as $assignee) {
                $clonedTask->assignees()->syncWithoutDetaching([$assignee->ID => ['object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE]]);
            }
        }
    }

    private function cloneTaskLabels(Task $originalTask, Task $clonedTask): void
    {
        // Clone labels
        if ($originalTask->labels) {
            foreach ($originalTask->labels as $label) {
                $clonedTask->labels()->syncWithoutDetaching([$label->id => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
            }
        }
    }

    private function cloneTaskWatchers(Task $originalTask, Task $clonedTask): void
    {
        /// Clone watchers
        if ($originalTask->watchers) {
            foreach ($originalTask->watchers as $watcher) {
                $clonedTask->watchers()->syncWithoutDetaching([$watcher->ID => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
            }
        }
    }
    private function cloneTaskCustomFields(Task $originalTask, Task $clonedTask): void
    {

        // Clone custom fields
        if ($originalTask->taskCustomFields) {
            foreach ($originalTask->taskCustomFields as $customField) {
                $clonedField = $customField->replicate();
                $clonedField->object_id = $clonedTask->id;
                $clonedField->save();
            }
        }
    }
    private function cloneAttachments(Task $originalTask, Task $clonedTask): void
    {
        $attachments = $originalTask->attachments;
        foreach ($attachments as $attachment) {
            $clonedAttachment = $attachment->replicate();
            $clonedAttachment->object_id = $clonedTask->id;
            $clonedAttachment->save();

            // If this is a cover image, update task settings
            if ($attachment->type === 'cover_image') {
                $settings = $clonedTask->settings;
                if (isset($settings['cover_image'])) {
                    $settings['cover_image'] = $clonedAttachment->id;
                    $clonedTask->settings = $settings;
                    $clonedTask->save();
                }
            }
        }
        $settings = $clonedTask->settings;
        $settings['attachment_count'] = $clonedTask->attachments()->count();
        $clonedTask['settings'] = $settings;
        $clonedTask->save();
    }
    private function cloneCommentsAndReplies(Task $originalTask, Task $clonedTask)
    {
        // Get comments ordered by created_at
        $comments = Comment::where('task_id', $originalTask->id)
            ->where('type', 'comment')
            ->whereNull('parent_id')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($comments->isEmpty()) {
            return;
        }

        foreach ($comments as $comment) {
            $clonedComment = $comment->replicate();
            $clonedComment->task_id = $clonedTask->id;
            $clonedComment->save();

            // Get replies ordered by created_at
            $replies = Comment::where('parent_id', $comment->id)
                ->where('type', 'reply')
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($replies as $reply) {
                $clonedReply = $reply->replicate();
                $clonedReply->task_id = $clonedTask->id;
                $clonedReply->parent_id = $clonedComment->id;
                $clonedReply->save();

                // Clone reply image if any
                $this->cloneCommentOrReplyImage($reply, $clonedReply);
            }

            // Clone comment image if any
            $this->cloneCommentOrReplyImage($comment, $clonedComment);
        }
        return;
    }
    private function cloneCommentOrReplyImage($oldCommentOrReply, $clonedCommentOrReply)
    {
        $images = CommentImage::where('object_id', $oldCommentOrReply->id)
            ->where('object_type', Constant::COMMENT_IMAGE)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($images->count() > 0) {
            foreach ($images as $image) {
                $clonedImage = $image->replicate();
                $clonedImage->object_id = $clonedCommentOrReply->id;
                $clonedImage->save();
            }
        }
    }
    private function cloneSubtasks(Task $originalTask, Task $clonedTask): void
    {
        // First clone subtask groups
        $subtaskGroupMap = $this->cloneSubtaskGroups($originalTask, $clonedTask);
        $completedSubtasksCount = 0;

        if ($originalTask->subtasks) {
            foreach ($originalTask->subtasks as $subtask) {
                $clonedSubtask = $subtask->replicate();
                $clonedSubtask->parent_id = $clonedTask->id;
                $clonedSubtask->board_id = $clonedTask->board_id; // Ensure subtask has same board_id as parent
                $clonedSubtask->save();
                if($clonedSubtask->status == 'closed') {
                    $completedSubtasksCount++;
                }

                // Update subtask group relationship if exists
                $groupRelation = TaskMeta::where('task_id', $subtask->id)
                    ->where('key', Constant::SUBTASK_GROUP_CHILD)
                    ->first();

                if ($groupRelation && isset($subtaskGroupMap[$groupRelation->value])) {
                    TaskMeta::create([
                        'task_id' => $clonedSubtask->id,
                        'key' => Constant::SUBTASK_GROUP_CHILD,
                        'value' => $subtaskGroupMap[$groupRelation->value]
                    ]);
                }
            }
        }
        $settings = $clonedTask->settings;
        $settings['subtask_count'] = $clonedTask->subtasks()->count();
        $clonedTask['settings'] = $settings;
        $clonedTask->settings['subtask_completed_count'] = $completedSubtasksCount;
        $clonedTask->save();
    }
    private function cloneSubtaskGroups(Task $originalTask, Task $clonedTask): array
    {
        $subtaskGroupMap = [];
        
        if ($originalTask->subtaskGroup) {
            foreach ($originalTask->subtaskGroup as $group) {
                $clonedGroup = TaskMeta::create([
                    'task_id' => $clonedTask->id,
                    'key' => Constant::SUBTASK_GROUP_NAME,
                    'value' => $group->value
                ]);
                
                $subtaskGroupMap[$group->id] = $clonedGroup->id;
            }
        }
        
        return $subtaskGroupMap;
    }
    private function prepareClonedTaskForResponse(Task $clonedTask): Task
    {
        // Load relationships
        $clonedTask->load(['board', 'stage', 'labels', 'assignees', 'subtasks']);
        
        // Sanitize assignees
        $clonedTask->assignees = Helper::sanitizeUserCollections($clonedTask->assignees);
        
        // Set additional properties
        $clonedTask->isOverdue = $clonedTask->isOverdue();
        $clonedTask->contact = Task::lead_contact($clonedTask->crm_contact_id);
        $clonedTask->board->stages = (new StageService())->stagesByBoardId($clonedTask->board_id);
        $clonedTask->is_watching = (new NotificationService())->isCurrentUserObservingTask($clonedTask);
        
        // Load next stage if applicable
        return $this->loadNextStage($clonedTask);
    }

    /**
     * Handle bulk actions for multiple tasks
     *
     * @param array $taskIds
     * @param string $action
     * @param array $params
     * @param int $boardId
     * @return array
     * @throws \Exception
     */
    public function bulkActions($taskIds, $action, $params, $boardId)
    {
        if (empty($taskIds) || !is_array($taskIds)) {
            throw new \Exception(esc_html__('No tasks selected', 'fluent-boards'));
        }

        if (count($taskIds) > 150) {
            throw new \Exception(esc_html__('Cannot process more than 150 tasks at once. Please select fewer tasks.', 'fluent-boards'));
        }

        if (empty($action)) {
            throw new \Exception(esc_html__('No action specified', 'fluent-boards'));
        }

        $tasks = Task::whereIn('id', $taskIds)
                    ->where('board_id', $boardId)
                    ->get();

        if ($tasks->isEmpty()) {
            throw new \Exception(esc_html__('No valid tasks found', 'fluent-boards'));
        }

        $result = [
            'successful_tasks' => [],
            'failed_tasks' => [],
            'message' => ''
        ];
        
        switch ($action) {
            case 'move_to_stage':
                $result = $this->bulkMoveToStage($tasks, $params, $boardId);
                break;

            case 'archive_tasks':
                $result = $this->bulkArchiveTasks($tasks);
                break;

            case 'change_priority':
                $result = $this->bulkChangePriority($tasks, $params);
                break;

            case 'assign_members':
                $result = $this->bulkAssignMembers($tasks, $params, $boardId);
                break;

            case 'add_labels':
                $result = $this->bulkAddLabels($tasks, $params, $boardId);
                break;

            default:
                throw new \Exception(esc_html__('Invalid action specified', 'fluent-boards'));
        }

        // Dispatch WordPress action for other plugins to hook into
        do_action('fluent_boards/bulk_action_completed', $action, $tasks, $boardId);

        return $result;
    }

    /**
     * Bulk move tasks to a stage
     */
    private function bulkMoveToStage($tasks, $params, $boardId)
    {
        $stageId = $params['stage_id'] ?? null;
        if (!$stageId) {
            throw new \Exception(esc_html__('Stage ID is required', 'fluent-boards'));
        }
        
        $successfulTasks = [];
        $failedTasks = [];
        
        foreach ($tasks as $task) {
            try {
                $oldStageId = $task->stage_id;
                
                // Set new stage
                $task->stage_id = $stageId;
                $task = $task->moveToNewPosition(1);
                
                // Only process stage-specific logic if stage actually changed
                if ($oldStageId != $stageId) {
                    // Manage default assignees for the new stage
                    $this->manageDefaultAssignees($task, $stageId);
                    
                    // Check if new stage has default closed status
                    $defaultPosition = $task->stage->defaultTaskStatus();
                    if ($defaultPosition == 'closed' && $task->status != 'closed') {
                        $task = $task->close();
                    }
                    
                    // Dispatch WordPress action for stage change
                    //currently commented, need to check in future for bulk action
//                    do_action('fluent_boards/task_stage_updated', $task, $oldStageId);
                    
                    // Send email notifications for stage change
                    $usersToSendEmail = (new \FluentBoards\App\Services\NotificationService())->filterAssigneeToSendEmail($task->id, Constant::BOARD_EMAIL_STAGE_CHANGE);
                    $this->sendMailAfterTaskModify('stage_change', $usersToSendEmail, $task->id);
                }
                
                // Dispatch general task update action
                //currently commented, need to check in future for bulk action
//                do_action('fluent_boards/task_updated', $task, 'position');

                // Reload task with all relationships
                $task->load(['labels', 'assignees', 'board', 'stage', 'watchers', 'taskCustomFields']);
                
                $successfulTasks[] = $task;
            } catch (\Exception $e) {
                $failedTasks[] = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $successCount = count($successfulTasks);
        $failureCount = count($failedTasks);
        
        $message = '';
        if ($failureCount === 0) {
            // translators: %d is the number of tasks successfully moved to the stage
            $message = sprintf(__('%d tasks moved to stage successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of tasks that failed to move to the stage
            $message = sprintf(__('Failed to move %d tasks to stage', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of tasks successfully moved; 2: number of tasks failed to move
            $message = sprintf(__('%1$d tasks moved successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }
        
        return [
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'message' => $message
        ];
    }

    /**
     * Bulk archive tasks
     */
    private function bulkArchiveTasks($tasks)
    {
        $successfulTasks = [];
        $failedTasks = [];
        
        foreach ($tasks as $task) {
            try {
                // Use the same logic as single task archiving
                $this->updateTaskProperty('archived_at', current_time('mysql'), $task);

                // Reload task with all relationships
                $task->load(['labels', 'assignees', 'board', 'stage', 'watchers', 'taskCustomFields']);
                
                $successfulTasks[] = $task;
            } catch (\Exception $e) {
                $failedTasks[] = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $successCount = count($successfulTasks);
        $failureCount = count($failedTasks);
        
        $message = '';
        if ($failureCount === 0) {
            // translators: %d is the number of tasks archived successfully
            $message = sprintf(__('%d tasks archived successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of tasks that failed to archive
            $message = sprintf(__('Failed to archive %d tasks', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of tasks archived successfully; 2: number of tasks failed to archive
            $message = sprintf(__('%1$d tasks archived successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }
        
        return [
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'message' => $message
        ];
    }

    /**
     * Bulk change task priority
     */
    private function bulkChangePriority($tasks, $params)
    {
        $priority = $params['priority'] ?? null;
        
        // Get valid priorities including custom ones added by hooks
        $validPriorities = array_keys(apply_filters('fluent_boards/task_priorities', [
            'low'    => __('Low', 'fluent-boards'),
            'medium' => __('Medium', 'fluent-boards'),
            'high'   => __('High', 'fluent-boards')
        ]));
        
        if (!in_array($priority, $validPriorities)) {
            throw new \Exception(esc_html__('Invalid priority level', 'fluent-boards'));
        }
        
        $successfulTasks = [];
        $failedTasks = [];
        
        foreach ($tasks as $task) {
            try {
                // Use the same logic as single task priority update
                $this->updateTaskProperty('priority', $priority, $task);

                // Reload task with all relationships
                $task->load(['labels', 'assignees', 'board', 'stage', 'watchers', 'taskCustomFields']);
                
                $successfulTasks[] = $task;
            } catch (\Exception $e) {
                $failedTasks[] = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $successCount = count($successfulTasks);
        $failureCount = count($failedTasks);
        
        $message = '';
        if ($failureCount === 0) {
            // translators: %d is the number of tasks whose priorities were updated successfully
            $message = sprintf(__('%d task priorities updated successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of tasks whose priorities failed to update
            $message = sprintf(__('Failed to update %d task priorities', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of tasks priorities updated; 2: number of tasks priorities failed to update
            $message = sprintf(__('%1$d task priorities updated successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }
        
        return [
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'message' => $message
        ];
    }

    /**
     * Bulk assign members to tasks
     */
    private function bulkAssignMembers($tasks, $params, $boardId)
    {
        $userIds = $params['user_ids'] ?? [];
        if (!is_array($userIds)) {
            throw new \Exception(esc_html__('User IDs must be an array', 'fluent-boards'));
        }
        
        // Validate that all users are valid WordPress users
        $validUsers = get_users(['include' => $userIds]);
        $validUserIds = array_map(function($user) {
            return $user->ID;
        }, $validUsers);
        
        if (count($validUserIds) !== count($userIds)) {
            throw new \Exception(esc_html__('Some user IDs are invalid', 'fluent-boards'));
        }
        
        // Filter only users who are already board members (skip non-members)
        $boardService = new \FluentBoards\App\Services\BoardService();
        $boardMemberIds = [];
        foreach ($validUserIds as $userId) {
            if ($boardService->isAlreadyMember($boardId, $userId)) {
                $boardMemberIds[] = $userId;
            }
        }
        
        // If no valid board members, skip assignment silently
        if (empty($boardMemberIds)) {
            return [
                'successful_tasks' => [],
                'failed_tasks' => [],
                'message' => __('No valid board members selected for assignment', 'fluent-boards')
            ];
        }
        
        // Use only board members for assignment
        $validUserIds = $boardMemberIds;
        
        $successfulTasks = [];
        $failedTasks = [];
        
        foreach ($tasks as $task) {
            try {
                // Use pure "add-only" logic for bulk assignment - never remove existing assignees
                $currentAssigneeIds = $task->assignees->pluck('ID')->toArray();
                $newAssignees = [];
                
                foreach ($validUserIds as $userId) {
                    // Only add if not already assigned
                    if (!in_array($userId, $currentAssigneeIds)) {
                        $newAssignees[] = $userId;
                    }
                }
                
                // Add all new assignees at once
                if (!empty($newAssignees)) {
                    $assigneeData = [];
                    foreach ($newAssignees as $userId) {
                        $assigneeData[$userId] = ['object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE];
                    }
                    $task->assignees()->syncWithoutDetaching($assigneeData);
                    
                    // Add as watchers
                    $watcherData = [];
                    foreach ($newAssignees as $userId) {
                        $watcherData[$userId] = ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH];
                    }
                    $task->watchers()->syncWithoutDetaching($watcherData);
                    
                    // Send notifications and actions only for new assignees
                    foreach ($newAssignees as $userId) {
                        // Send email notification if enabled and not current user
                        if ((new \FluentBoards\App\Services\NotificationService())->checkIfEmailEnable($userId, Constant::BOARD_EMAIL_TASK_ASSIGN, $task->board_id) && $userId != get_current_user_id()) {
                            $this->sendMailAfterTaskModify('add_assignee', $userId, $task->id);
                        }
                        
                        // Dispatch WordPress actions
                        //currently commented, need to check in future for bulk action
//                        do_action('fluent_boards/task_assignee_added', $task, $userId);
//                        if ($userId != get_current_user_id()) {
//                            do_action('fluent_boards/assign_another_user', $task, $userId);
//                        }
                    }
                }
                
                // Update task timestamp and reload all relationships
                $task->updated_at = current_time('mysql');
                $task->save();
                $task->load(['labels', 'assignees', 'board', 'stage', 'watchers', 'taskCustomFields']);
                
                $successfulTasks[] = $task;
            } catch (\Exception $e) {
                $failedTasks[] = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $successCount = count($successfulTasks);
        $failureCount = count($failedTasks);
        
        $message = '';
        if ($failureCount === 0) {
            // translators: %d is the number of tasks where members were assigned successfully
            $message = sprintf(__('%d tasks assigned members successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of tasks where assigning members failed
            $message = sprintf(__('Failed to assign members to %d tasks', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of tasks with members assigned successfully; 2: number of tasks where assigning members failed
            $message = sprintf(__('%1$d tasks assigned members successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }
        
        return [
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'message' => $message
        ];
    }

    /**
     * Bulk add labels to tasks
     */
    private function bulkAddLabels($tasks, $params, $boardId)
    {
        $labelIds = $params['label_ids'] ?? [];
        if (!is_array($labelIds)) {
            throw new \Exception(esc_html__('Label IDs must be an array', 'fluent-boards'));
        }
        
        // Validate that all labels exist and belong to the board
        $validLabels = \FluentBoards\App\Models\Label::whereIn('id', $labelIds)
            ->where('board_id', $boardId)
            ->whereNull('archived_at')
            ->get();
        
        if (count($validLabels) !== count($labelIds)) {
            throw new \Exception(esc_html__('Some label IDs are invalid or do not belong to this board', 'fluent-boards'));
        }
        
        $successfulTasks = [];
        $failedTasks = [];
        
        foreach ($tasks as $task) {
            try {
                // Load existing labels first to avoid query issues
                $task->load('labels');
                $existingLabelIds = $task->labels->pluck('id')->toArray();
                
                // Use the same logic as single task label adding
                foreach ($validLabels as $label) {
                    // Check if label is already attached
                    if (!in_array($label->id, $existingLabelIds)) {
                        // Add the label using syncWithoutDetaching to avoid duplicates
                        $task->labels()->syncWithoutDetaching([
                            $label->id => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]
                        ]);
                        
                        // Dispatch WordPress action for label addition
                        //currently commented, need to check in future for bulk action
//                        do_action('fluent_boards/task_label', $task, $label, 'added');
                    }
                }
                
                // Reload the task with all relationships
                $task->load(['labels', 'assignees', 'board', 'stage', 'watchers', 'taskCustomFields']);
                
                $successfulTasks[] = $task;
            } catch (\Exception $e) {
                $failedTasks[] = [
                    'id' => $task->id,
                    'title' => $task->title,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $successCount = count($successfulTasks);
        $failureCount = count($failedTasks);
        
        $message = '';
        if ($failureCount === 0) {
            // translators: %d is the number of tasks labeled successfully
            $message = sprintf(__('%d tasks labeled successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of tasks that failed to label
            $message = sprintf(__('Failed to label %d tasks', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of tasks labeled successfully; 2: number of tasks failed to label
            $message = sprintf(__('%1$d tasks labeled successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }
        
        return [
            'successful_tasks' => $successfulTasks,
            'failed_tasks' => $failedTasks,
            'message' => $message
        ];
    }
  
  /* Delete time tracking records for one or multiple tasks
     * Uses try-catch for better performance - avoids table existence check overhead
     *
     * @param int|array $taskIds Single task ID or array of task IDs
     * @return void
     */
    public function deleteTimeTrackingRecords($taskIds)
    {
        // Check if FluentBoards Pro time tracking is available
        if (!class_exists('FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack')) {
            return;
        }

        try {
            // Handle single task ID or array of task IDs
            if (is_array($taskIds)) {
                if (!empty($taskIds)) {
                    \FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack::whereIn('task_id', $taskIds)->delete();
                }
            } else {
                if (is_numeric($taskIds) && $taskIds > 0) {
                    \FluentBoardsPro\App\Modules\TimeTracking\Model\TimeTrack::where('task_id', (int) $taskIds)->delete();
                }
            }
        } catch (\Exception $e) {
            // Silently fail if table doesn't exist or any other error occurs
            // This is intentional for cleanup operations
        }
    }

}
