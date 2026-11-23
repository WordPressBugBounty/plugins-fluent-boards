<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Services\SlackService;
use RuntimeException;

class SlackHandler
{
    /** Notify initial and bulk assignees from the shared task mutation hook. */
    public function assigneesAdded($task, $userIds)
    {
        (new SlackService())->queueNotification('add_assignee', $task, $userIds);
    }

    /** Map task mutations to Slack categories without consulting email preferences. */
    public function taskChanged($task, $userId = null)
    {
        $events = [
            'fluent_boards/task_assignee_added' => 'add_assignee',
            'fluent_boards/task_assignee_removed' => 'remove_assignee',
            'fluent_boards/task_stage_updated' => 'stage_change',
            'fluent_boards/task_archived' => 'task_archived',
        ];
        $event = $events[current_filter()] ?? null;
        if ($event) {
            $recipients = in_array($event, ['add_assignee', 'remove_assignee'], true) ? [$userId] : null;
            (new SlackService())->queueNotification($event, $task, $recipients);
        }
    }

    public function dateChanged($task, $oldDate = null)
    {
        $dueDate = in_array(current_filter(), ['fluent_boards/task_due_date_changed', 'fluent_boards/task_due_date_removed'], true);
        $newDate = $dueDate ? $task->due_at : $task->started_at;
        // Older producers of the removal hook pass only the task.
        if (func_num_args() < 2 || ($newDate ? strtotime($newDate) : null) !== ($oldDate ? strtotime($oldDate) : null)) {
            (new SlackService())->queueNotification('due_date_update', $task);
        }
    }

    public function priorityChanged($task, $oldPriority)
    {
        if ((string) $task->priority !== (string) $oldPriority) {
            (new SlackService())->queueNotification('priority_change', $task);
        }
    }

    public function contentUpdated($task, $column, $oldTask)
    {
        if ($column === 'description' && (string) $task->description !== (string) $oldTask->description) {
            (new SlackService())->queueNotification('description_update', $task);
        }
    }

    /** Queue comments and mentions independently from their email jobs. */
    public function commentCreated($comment, $mentionedIds = null)
    {
        (new SlackService())->queueCommentNotification($comment, $mentionedIds);
    }

    public function ensureReconciliation()
    {
        if (get_option(SlackService::CREDENTIALS) && !wp_next_scheduled(SlackService::RECONCILE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', SlackService::RECONCILE_HOOK);
        }
    }

    public function reconcileDigests()
    {
        (new SlackService())->reconcileDigests();
    }

    /** Send a member's grouped activity once their waiting window ends. */
    public function sendDigest($userId, $attempt = 0, $batch = '')
    {
        (new SlackService())->sendDigest($userId, $attempt, $batch);
    }

    /** OAuth2 redirect target. Runs with the member's WordPress session (admin-post.php). */
    public function oauthCallback()
    {
        $service = new SlackService();
        $userId = get_current_user_id();
        // Query values come from Slack; the service validates the format of each one.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the single-use OAuth state is the CSRF check.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
        // phpcs:enable
        try {
            $result = $service->completeConnection($userId, $state, $code, $error);
        } catch (RuntimeException $exception) {
            $result = 'failed';
        }
        wp_safe_redirect($service->accountUrl($userId, $result));
        exit;
    }

    /** After login, the empty callback opens the member's account with the reconnect prompt. */
    public function oauthCallbackSignedOut()
    {
        header('Referrer-Policy: no-referrer');
        wp_safe_redirect(wp_login_url((new SlackService())->redirectUri()));
        exit;
    }
}
