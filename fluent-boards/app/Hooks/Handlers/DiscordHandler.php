<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Services\DiscordService;

class DiscordHandler
{
    /** Notify initial and bulk assignees from the shared task mutation hook. */
    public function assigneesAdded($task, $userIds)
    {
        (new DiscordService())->queueNotification('add_assignee', $task, $userIds);
    }

    /** Map task mutations to Discord categories without consulting email preferences. */
    public function taskChanged($task, $userId = null)
    {
        $events = [
            'fluent_boards/task_assignee_added' => 'add_assignee',
            'fluent_boards/task_assignee_removed' => 'remove_assignee',
            'fluent_boards/task_stage_updated' => 'stage_change',
            'fluent_boards/task_due_date_changed' => 'due_date_update',
            'fluent_boards/task_due_date_removed' => 'due_date_update',
            'fluent_boards/task_start_date_changed' => 'due_date_update',
            'fluent_boards/task_start_date_removed' => 'due_date_update',
            'fluent_boards/task_archived' => 'task_archived',
        ];
        $event = $events[current_filter()] ?? null;
        if ($event) {
            $recipients = in_array($event, ['add_assignee', 'remove_assignee'], true) ? [$userId] : null;
            (new DiscordService())->queueNotification($event, $task, $recipients);
        }
    }

    public function priorityChanged($task, $oldPriority)
    {
        if ((string) $task->priority !== (string) $oldPriority) {
            (new DiscordService())->queueNotification('priority_change', $task);
        }
    }

    public function contentUpdated($task, $column, $oldTask)
    {
        if ($column === 'description' && (string) $task->description !== (string) $oldTask->description) {
            (new DiscordService())->queueNotification('description_update', $task);
        }
    }

    /** Queue comments and mentions independently from their email jobs. */
    public function commentCreated($comment, $mentionedIds = null)
    {
        (new DiscordService())->queueCommentNotification($comment, $mentionedIds);
    }

    public function ensureReconciliation()
    {
        if (!wp_next_scheduled(DiscordService::RECONCILE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', DiscordService::RECONCILE_HOOK);
        }
    }

    public function reconcileDigests()
    {
        (new DiscordService())->reconcileDigests();
    }

    /** Send a member's grouped activity once their waiting window ends. */
    public function sendDigest($userId, $attempt = 0, $batch = '')
    {
        (new DiscordService())->sendDigest($userId, $attempt, $batch);
    }

    /** OAuth2 redirect target. Runs with the member's WordPress session (admin-post.php). */
    public function oauthCallback()
    {
        $service = new DiscordService();
        $userId = get_current_user_id();
        // Query values come from Discord; the service validates the format of each one.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- the single-use OAuth state is the CSRF check.
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $error = isset($_GET['error']) ? sanitize_key(wp_unslash($_GET['error'])) : '';
        // phpcs:enable
        $result = $service->completeConnection($userId, $state, $code, $error);
        wp_safe_redirect($service->accountUrl($userId, $result));
        exit;
    }

    /** After login the empty callback opens the member's account with the reconnect prompt. */
    public function oauthCallbackSignedOut()
    {
        header('Referrer-Policy: no-referrer');
        wp_safe_redirect(wp_login_url((new DiscordService())->redirectUri()));
        exit;
    }
}
