<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\TelegramService;

class TelegramHandler
{
    /** A pre-move Pro build remains the owner until its compatibility update. */
    private function legacyProOwnsTelegram()
    {
        return defined('FLUENT_BOARDS_PRO_VERSION')
            && !defined('FLUENT_BOARDS_TELEGRAM_CORE_COMPAT')
            && class_exists('FluentBoardsPro\\App\\Services\\TelegramService');
    }

    /** Observe persisted jobs without delivering notifications in the task request. */
    public function notificationQueued($actionId)
    {
        if ($this->legacyProOwnsTelegram()) {
            return;
        }
        (new TelegramService())->notificationQueued($actionId);
    }

    /** Notify initial and bulk assignees from the shared task mutation hook. */
    public function assigneesAdded($task, $userIds)
    {
        if ($this->legacyProOwnsTelegram()) {
            return;
        }
        (new TelegramService())->queueNotification('add_assignee', $task, $userIds);
    }

    /** Map task mutations to Telegram categories without consulting email preferences. */
    public function taskChanged($task, $previous = null, $context = [])
    {
        if ($this->legacyProOwnsTelegram()) {
            return;
        }
        $events = [
            'fluent_boards/task_assignee_added' => 'add_assignee',
            'fluent_boards/task_assignee_removed' => 'remove_assignee',
            'fluent_boards/task_stage_updated' => 'stage_change',
            'fluent_boards/task_due_date_changed' => 'due_date_update',
            'fluent_boards/task_due_date_removed' => 'due_date_update',
            'fluent_boards/task_start_date_changed' => 'due_date_update',
            'fluent_boards/task_start_date_removed' => 'due_date_update',
            'fluent_boards/task_priority_changed' => 'priority_change',
            'fluent_boards/task_archived' => 'task_archived',
        ];
        $event = $events[current_filter()] ?? null;
        if ($event) {
            $recipients = in_array($event, ['add_assignee', 'remove_assignee'], true) ? [$previous] : null;
            $change = [];
            if ($event === 'stage_change') {
                $change = static function () use ($task, $previous, $context) {
                    $source = $context['source'] ?? Stage::where('board_id', $task->board_id)->find(absint($previous));
                    $target = $context['target'] ?? $task->stage;
                    return ['field' => 'stage', 'before' => $source && (int) $source->board_id === (int) $task->board_id ? $source->title : null, 'after' => $target ? $target->title : null];
                };
            } elseif ($event === 'due_date_update') {
                $field = in_array(current_filter(), ['fluent_boards/task_start_date_changed', 'fluent_boards/task_start_date_removed'], true) ? 'started_at' : 'due_at';
                $change = ['field' => $field, 'before' => $previous, 'after' => $task->{$field}];
            }
            (new TelegramService())->queueNotification($event, $task, $recipients, 0, $change);
        }
    }

    public function contentUpdated($task, $column, $oldTask)
    {
        if (!$this->legacyProOwnsTelegram() && $column === 'description' && (string) $task->description !== (string) $oldTask->description) {
            (new TelegramService())->queueNotification('description_update', $task);
        }
    }

    /** Queue comments and mentions independently from their email jobs. */
    public function commentCreated($comment, $mentionedIds = null)
    {
        if ($this->legacyProOwnsTelegram()) {
            return;
        }
        (new TelegramService())->queueCommentNotification($comment, $mentionedIds);
    }

    /** Restore jobs for waiting rows and orphaned batches during scheduler maintenance. */
    public function reconcileDigests()
    {
        if (!$this->legacyProOwnsTelegram()) {
            (new TelegramService())->reconcileDigests();
        }
    }

    public function sendDigest($userId, $attempt = 0, $batch = '')
    {
        if (!$this->legacyProOwnsTelegram()) {
            (new TelegramService())->sendDigest($userId, $attempt, $batch);
        }
    }

    public function deliverNotification($event, $taskId, $commentId, $boardId, $userId, $generation, $attempt = 0)
    {
        if ($this->legacyProOwnsTelegram()) {
            return;
        }
        (new TelegramService())->deliverNotification($event, $taskId, $commentId, $boardId, $userId, $generation, $attempt);
    }
}
