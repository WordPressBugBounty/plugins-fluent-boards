<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentCrm\App\Models\Subscriber;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Services\Helper;

class ActivityHandler
{
    public function createLogActivity($taskId, $action, $column, $oldValue = null, $newValue = null, $description = null, $settings = null )
    {
        $data = [
            'object_type' => Constant::ACTIVITY_TASK,
            'object_id' => $taskId,
            'action' => $action, // action type: changed, updated, added, removed
            'column' => $column,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
            'settings' => $settings
        ];
        $userId = get_current_user_id();
        if($userId == 0){
            $task = Task::find($taskId);
            $data['created_by'] = $task->created_by;
        }

        Helper::createActivity($data);
    }
    public function logMoveTaskToAnotherBoardActivity($task, $oldTask)
    {
        $old = $this->getBoardTitleById($oldTask->board_id);
        $new = $this->getBoardTitleById($task->board_id);
        $this->createLogActivity( $task->id,'changed', 'board', $old, $new);
    }

    public function logAssigneeAddedActivity($task, $newAssigneeId)
    {
        $currentUserId = get_current_user_id();
        if($newAssigneeId == $currentUserId) {
            $this->taskAssigneeJoin($task->id);
            return;
        }
        $user = User::findOrFail($newAssigneeId);
        $currentUserId = get_current_user_id();
        if($currentUserId === $user->ID){
            $this->taskAssigneeJoin($task->id);
        } elseif ($user) {
            $this->createLogActivity($task->id, 'added', 'assignee', null, $user->display_name);
        }
    }

    public function logAssigneeRemovedActivity($task, $newAssigneeId)
    {
        $currentUserId = get_current_user_id();
        if($newAssigneeId == $currentUserId) {
            $this->taskAssigneeLeave($task->id);
            return;
        }
        $user = User::findOrFail($newAssigneeId);
        $currentUserId = get_current_user_id();
        if($currentUserId === $user->ID){
            $this->taskAssigneeLeave($task->id);
        } else if($user) {
            $this->createLogActivity($task->id, 'removed', 'assignee', null, $user->display_name);
        }
    }

    public function logTaskCreationActivity($task)
    {
        $this->createLogActivity( $task->id,'created', 'task', null, $task->title, null );
    }

    public function logTaskContentUpdatedActivity($task, $col, $oldTask = null)
    {
        // Description autosaves are intentionally excluded to avoid flooding the activity feed.
        if ($col === 'description') {
            return;
        }

        $action = 'updated';
        if($task->parent_id){
            $this->createLogActivity( $task->parent_id, $action, 'subtask', $oldTask->title, $task->title );
        }else{
            $this->createLogActivity( $task->id, $action, $col, $oldTask->title, $task->title );
        }
    }

    public function taskLabelActivity($task, $label, $action)
    {
        $column = 'label';
        $settings = [
            'bg_color' => $label->bg_color,
            'color' => $label->color,
            'title' => $label->title,
        ];

        $this->createLogActivity($task->id, $action, $column, null, null, null, $settings);
    }

    public function logDueDateActivity($task, $oldDate)
    {
        $column = 'Due Date';
        $action = 'changed';

        if($oldDate){
            $new = gmdate("F j, Y, g:i a", strtotime($task->due_at));
            $old = gmdate("F j, Y, g:i a", strtotime($oldDate));
            if($new == $old)
                return;
        }
        $newDueDate = $task->due_at;
        $new = null;
        if ($newDueDate) {
            $new = gmdate("F j, Y, g:i a", strtotime($newDueDate));
            if(str_contains($new, '12:00 am')){
                $new = gmdate("F j, Y", strtotime($newDueDate));
            }
        }
        $old = null;
        if(!$oldDate){
            $action = 'added';
        }else{
            $old = gmdate("F j, Y, g:i a", strtotime($oldDate));
            if(str_contains($old, '12:00 am')){
                $old = gmdate("F j, Y", strtotime($oldDate));
            }
        }

        $this->createLogActivity($task->id, $action, $column, $old, $new);
    }
    public function logDueDateRemoveActivity($task)
    {
        $this->createLogActivity( $task->id, 'removed', 'Due Date', null);
    }

    public function logStartDateActivity($task, $oldDate)
    {
        $column = 'Start Date';
        $action = 'changed';

        if($oldDate){
            $new = gmdate("F j, Y", strtotime($task->started_at));
            $old = gmdate("F j, Y", strtotime($oldDate));
            if($new == $old)
                return;
        }
        $newStartDate = $task->started_at;
        $new = null;
        if ($newStartDate) {
            $new = gmdate("F j, Y", strtotime($newStartDate));
        }
        $old = null;
        if(!$oldDate){
            $action = 'added';
        }else{
            $old = gmdate("F j, Y", strtotime($oldDate));
        }

        $this->createLogActivity($task->id, $action, $column, $old, $new);
    }


    public function logPriorityChangeActivity($task, $old)
    {
        $new = $task->priority;
        $this->createLogActivity($task->id, 'changed', 'priority', $old, $new);
    }

    public function logCommentCreateActivity($comment)
    {
        if($comment->parent_id) {
            // dd($comment);
            $parent = Comment::findOrFail($comment->parent_id);
            $parentDescription = $parent->description;
            $this->createLogActivity($comment->task_id, 'added', 'a reply'); 
        }else{
            $commentPlainText = wp_strip_all_tags($comment->description);
            $this->createLogActivity($comment->task_id, 'added', 'comment', null, $commentPlainText, null);
        }
    }
    public function logCommentUpdateActivity($comment, $oldComment)
    {
        $taskId = $comment->task_id;
        $newComment = $comment->settings['raw_description'] ?? $comment->description;

        // Activity previews are plain text, while comment drafts now contain inline HTML.
        $plainText = static function ($content) {
            $content = preg_replace('~<br\s*/?>|</p\s*>~i', "\n", $content);
            return trim(html_entity_decode(wp_strip_all_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        };

        $this->createLogActivity($taskId, 'updated', 'comment', $plainText($oldComment), $plainText($newComment));
    }
    public function logCommentDeleteActivity($comment)
    {
        $commentDescription = wp_strip_all_tags($comment->settings['raw_description'] ?? $comment->description );
        $taskId = $comment->task_id;

        $this->createLogActivity($taskId, 'removed', 'comment', null, $commentDescription, null);
    }

    public function logSubtaskAddedActivity($parentTask, $subTask)
    {
        $this->createLogActivity($parentTask->id, 'added', 'subtask', null, $subTask->title);
    }

    public function logSubtaskCloneActivity($parentTask, $subTask)
    {
        $this->createLogActivity($parentTask->id, 'cloned', 'subtask', null, $subTask->title);
    }
    public function logSubtaskGroupAddedActivity($task_id, $subTaskGroup)
    {
        $this->createLogActivity($task_id, 'added', 'subtask group', null, $subTaskGroup->value);
    }

    public function logSubtaskDeletedActivity($id, $subTaskTitle)
    {
        $this->createLogActivity($id, 'removed', 'subtask', null, $subTaskTitle);
    }

    public function logSubtaskGroupDeletedActivity($task_id, $subTaskGroup)
    {
        $this->createLogActivity($task_id, 'removed', 'subtask group', null, $subTaskGroup->value);
    }
    public function logSubtaskGroupTitleUpdatedActivity($oldTitle, $group)
    {
        $this->createLogActivity($group->task_id, 'changed', 'subtask group title', $oldTitle, $group->value);
    }
    public function logTaskCompletedOrReopenActivity($task, $status)
    {
        if($status == 'closed'){
            if($task->parent_id){
                $this->createLogActivity($task->parent_id, 'closed', 'subtask', $task->title);
            }else{
                $this->createLogActivity($task->id, 'closed', 'task', $task->title);
            }
        }else{
            if($task->parent_id){
                $this->createLogActivity($task->parent_id, 'reopened', 'subtask', $task->title);
            }else{
                $this->createLogActivity($task->id, 'reopened', 'task', $task->title);
            }
        }
    }

    public function logTaskStageUpdatedActivity($task, $oldStageId, $stageContext = null)
    {
        $oldStage = $stageContext['source'] ?? null;
        $newStage = $stageContext['target'] ?? null;

        if (!$oldStage instanceof Stage || !$newStage instanceof Stage) {
            $oldStage = Stage::find($oldStageId);
            $newStage = Stage::findOrFail($task->stage_id);
        }

        if ($newStage) {
            $this->createLogActivity($task->id, 'changed', 'stage', $oldStage ? $oldStage->title : null, $newStage->title);
        }
    }

    public function associateUserAddChangeRemoveActivity($oldAssociateId, $newAssociateId, $taskId)
    {
        $subsciber = new Subscriber();
        $oldAssociateUser = $subsciber->find($oldAssociateId);
        $newAssociateUser = $subsciber->find($newAssociateId);
        $action = 'added';
        $column = 'the associate email';
        $old = null; $new = null;

        if ($oldAssociateUser && $newAssociateUser) {
            $old = $oldAssociateUser->email;
            $new = $newAssociateUser->email;
            $action = 'changed';
        } elseif ($oldAssociateUser && !$newAssociateUser) {
            $old = $oldAssociateUser->email;
            $action = 'removed';
        } else {
            $new = $newAssociateUser->email;
        }
        $this->createLogActivity($taskId, $action, $column, $old, $new);
    }

    public function taskAssigneeJoin($id)
    {
        $this->createLogActivity($id, 'joined', 'task', null, null);
    }

    public function taskAssigneeLeave($id)
    {
        $this->createLogActivity($id, 'left', 'task', null, null);
    }

    public function labelManageForTaskActivity($id, $action)
    {
        $this->createLogActivity($id, $action, 'label', null, null);
    }

    private function getBoardTitleById($boardId)
    {
        return Board::findOrFail($boardId)->title; // it can be further optimized , we may limit multiple query here.
    }

    public function taskAddedFromFluentForms($task)
    {
        $taskActivity = [
            'object_type' => Constant::ACTIVITY_TASK,
            'object_id' => $task->id,
            'action' => 'created', // action type: changed, updated, added, removed
            'column' => 'task',
            'old_value' => $task->title,
            'new_value' => null,
            'description' => " from Fluent Forms",
        ];

        $board = Board::find($task->board_id);
        $settings = $board->settings ?? [];

        if (isset($settings['tasks_count'])) {
            if ($settings['tasks_count'] != 0)  $settings['tasks_count'] += 1;
        } else {
            $settings['tasks_count'] = 1;
        }
        $board->settings = $settings;
        $board->save();
        $taskStage = $task->stage;
        $settings = ['task_id' => $task->id];

        $boardActivity = [
            'object_type' => Constant::ACTIVITY_BOARD,
            'object_id' => $task->board_id,
            'action' => 'created', // action type: changed, updated, added, removed, created
            'column' => 'task',
            'old_value' => null,
            'new_value' => $task->title,
            'description' => 'on stage '. $taskStage->title . ' from Fluent Forms ',
            'settings' => $settings
        ];
        if (intval($task->created_by) > 0) {
            $taskActivity['created_by'] = intval($task->created_by);
            $boardActivity['created_by'] = intval($task->created_by);

        } else {
            $taskActivity['description'] .= ' by '. $task['settings']['author']['name'] . " (" . $task['settings']['author']['email'] . ")";
            $boardActivity['description'] .= ' by '. $task['settings']['author']['name'] . " (" . $task['settings']['author']['email'] . ")";
        }
        Helper::createActivity($taskActivity);
        Helper::createActivity($boardActivity);

    }
    public function taskAttachmentAdded($attachment)
    {
        $this->createLogActivity($attachment->object_id , 'added', 'attachment', null, strlen($attachment->title) > 0 ? $attachment->title : $attachment->full_url);
    }
    public function taskAttachmentDeleted($attachment)
    {
        $this->createLogActivity($attachment->object_id , 'deleted', 'attachment', null, strlen($attachment->title) > 0 ? $attachment->title : $attachment->full_url);
    }

    public function taskArchived($task)
    {
        if(!$task->archived_at){
            $this->createLogActivity($task->id, 'restored', 'task', $task->title);
        }else{
            $this->createLogActivity($task->id, 'archived', 'task', $task->title);
        }
    }

    /** Record both recurrence links, retaining the full generated title in the source entry's settings. */
    public function logRepeatTaskCreatedActivity($newTask, $parentTask)
    {
        $this->createLogActivity(
            $newTask->id,
            'created',
            'repeat task',
            $newTask->title,
            $parentTask->title,
            null,
            $parentTask
        );

        // new_value is VARCHAR(50); keep a Unicode-safe preview and render settings.title.
        $this->createLogActivity(
            $parentTask->id,
            'created',
            'repeat task',
            null,
            mb_substr($newTask->title, 0, 50, 'UTF-8'),
            null,
            [
                'id' => $newTask->id,
                'board_id' => $newTask->board_id,
                'title' => $newTask->title,
            ]
        );
    }

    public function logRepeatTaskSet($newTask)
    {
        $this->createLogActivity(
            $newTask->id,
            'set',
            'Repeat Task',
            null,
            null,
        );
    }

    public function logRepeatTaskUpdated($newTask)
    {
        $this->createLogActivity(
            $newTask->id,
            'updated',
            'Repeat Task',
            null,
            null
        );
    }
    public function taskMovedFromBoard($task, $oldBoard, $newBoard)
    {
        $this->createLogActivity($task->id, 'moved', 'board', $oldBoard->title, $newBoard->title);
    }

    /** Log meaningful task value additions, changes and removals, preserving zero and false. */
    public function logCustomFieldActivity($taskId, $customField, $oldValue, $newValue, $isNewCustomField)
    {
        $fieldType = $customField->settings['custom_field_type'];
        $oldValue = $this->normalizeCustomFieldActivityValue($isNewCustomField ? null : $oldValue, $fieldType);
        $newValue = $this->normalizeCustomFieldActivityValue($newValue, $fieldType);

        if ($oldValue === $newValue) {
            return;
        }

        $action = Constant::ACTIVITY_ACTION_CHANGED;
        if ($oldValue === null) {
            $action = Constant::ACTIVITY_ACTION_ADDED;
        } elseif ($newValue === null) {
            $action = Constant::ACTIVITY_ACTION_REMOVED;
        }

        $settings = [
            'custom_field_id' => $customField->id,
            'custom_field_title' => $customField->title,
            'custom_field_type' => $fieldType
        ];

        $this->createLogActivity(
            $taskId,
            $action,
            /* translators: %s is the custom field title */
            sprintf(__('custom field %s', 'fluent-boards'), $customField->title),
            $this->formatValueForDisplay($oldValue, $fieldType),
            $this->formatValueForDisplay($newValue, $fieldType),
            null,
            $settings
        );
    }

    /** Compare logical values independently of transport types and selection order. */
    private function normalizeCustomFieldActivityValue($value, $fieldType)
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if ($fieldType === 'checkbox') {
            return rest_sanitize_boolean($value);
        }
        if ($fieldType === 'multi-select') {
            $value = array_values(array_unique(array_map('strval', (array) $value)));
            sort($value, SORT_STRING);
            return $value;
        }
        if ($fieldType === 'number' && is_numeric($value)) {
            return (string) (0 + $value);
        }
        return (string) $value;
    }

    /** Format custom-field values without treating zero or an unchecked box as empty. */
    private function formatValueForDisplay($value, $fieldType)
    {
        if ($value === null) {
            return null;
        }
        if ($fieldType === 'checkbox') {
            return $value ? __('checked', 'fluent-boards') : __('unchecked', 'fluent-boards');
        }
        if ($fieldType === 'multi-select') {
            return implode(', ', $value);
        }
        if ($fieldType === 'date' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return mysql2date('M j, Y g:i A', $value);
        }
        return (string) $value;
    }
    public function taskCloned($originalTask, $clonedTask)
    {
        $this->createLogActivity(
            $clonedTask->id,
            'cloned',
            'task',
            'from ' . $originalTask->title,
        );
    }
}
