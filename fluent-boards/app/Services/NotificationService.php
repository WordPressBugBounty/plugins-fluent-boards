<?php

namespace FluentBoards\App\Services;

use InvalidArgumentException;
use RuntimeException;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Notification;
use FluentBoards\App\Models\NotificationUser;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;

class NotificationService
{
    const READ_BATCH_SIZE = 20;

    /** Return UTC expiries or the indefinite sentinel; expired values need no cron cleanup. */
    public function getNotificationPause($userId, $boardId = 0)
    {
        $userId = absint($userId);
        $boardId = absint($boardId);
        $now = time();
        $keys = [Constant::USER_NOTIFICATION_PAUSE];
        if ($boardId) {
            $keys[] = Constant::USER_BOARD_NOTIFICATION_PAUSE_PREFIX . $boardId;
        }
        $pauses = Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('object_id', $userId)
            ->whereIn('key', $keys)
            ->get(['key', 'value']);
        $globalUntil = 0;
        $boardUntil = 0;
        foreach ($pauses as $pause) {
            $until = (int) $pause->value;
            if ($until !== Constant::NOTIFICATION_PAUSE_INDEFINITE && $until <= $now) {
                continue;
            }
            if ($pause->key === Constant::USER_NOTIFICATION_PAUSE) {
                $globalUntil = $globalUntil === Constant::NOTIFICATION_PAUSE_INDEFINITE || $until === Constant::NOTIFICATION_PAUSE_INDEFINITE
                    ? Constant::NOTIFICATION_PAUSE_INDEFINITE : max($globalUntil, $until);
            } else {
                $boardUntil = $boardUntil === Constant::NOTIFICATION_PAUSE_INDEFINITE || $until === Constant::NOTIFICATION_PAUSE_INDEFINITE
                    ? Constant::NOTIFICATION_PAUSE_INDEFINITE : max($boardUntil, $until);
            }
        }

        return [
            'global_until' => $globalUntil,
            'board_until' => $boardUntil,
            'effective_until' => $globalUntil === Constant::NOTIFICATION_PAUSE_INDEFINITE || $boardUntil === Constant::NOTIFICATION_PAUSE_INDEFINITE
                ? Constant::NOTIFICATION_PAUSE_INDEFINITE : max($globalUntil, $boardUntil),
            'server_time' => $now,
        ];
    }

    /** Validate access to a personal pause without granting board mutation permissions. */
    public function authorizeNotificationPause($boardId = 0)
    {
        $boardId = absint($boardId);
        if (!get_current_user_id() || !PermissionManager::hasAppAccess()) {
            throw new RuntimeException(__('You do not have permission to change notification preferences.', 'fluent-boards'), 403);
        }
        if ($boardId && (!Board::find($boardId) || !PermissionManager::userHasBoardPermission($boardId, 'GET'))) {
            throw new RuntimeException(__('You do not have access to this board.', 'fluent-boards'), 403);
        }
    }

    /** Save a preset, custom UTC expiry or indefinite pause; zero resumes only this scope. */
    public function updateNotificationPause($duration, $boardId = 0, $customUntil = null)
    {
        $boardId = absint($boardId);
        $this->authorizeNotificationPause($boardId);
        if ($duration === 'indefinite') {
            $until = Constant::NOTIFICATION_PAUSE_INDEFINITE;
        } elseif ($duration === 'custom') {
            // The client converts its local date/time to UTC seconds; do not apply the site timezone.
            $until = (is_int($customUntil) || is_string($customUntil))
                ? filter_var($customUntil, FILTER_VALIDATE_INT) : false;
            if ($until === false || $until <= time() || (int) gmdate('Y', $until) > 9999) {
                throw new InvalidArgumentException(__('Please select a future date and time.', 'fluent-boards'), 422);
            }
        } else {
            if ((!is_int($duration) && !(is_string($duration) && ctype_digit($duration))) ||
                !in_array((int) $duration, Constant::NOTIFICATION_PAUSE_DURATIONS, true)) {
                throw new InvalidArgumentException(__('Please select a valid notification pause duration.', 'fluent-boards'), 422);
            }
            $duration = (int) $duration;
            $until = $duration ? time() + $duration * MINUTE_IN_SECONDS : 0;
        }

        $userId = get_current_user_id();
        $key = $boardId ? Constant::USER_BOARD_NOTIFICATION_PAUSE_PREFIX . $boardId : Constant::USER_NOTIFICATION_PAUSE;
        $query = Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('object_id', $userId)
            ->where('key', $key);
        if ($query->exists()) {
            // Update all matching rows, including duplicates from older concurrent saves.
            $query->update(['value' => $until]);
        } else {
            Meta::create(['object_type' => Constant::OBJECT_TYPE_USER, 'object_id' => $userId, 'key' => $key, 'value' => $until]);
        }
        do_action('fluent_boards/notification_pause_updated', $userId, $boardId, $until);

        return $this->getNotificationPause($userId, $boardId);
    }

    /** Filter recipients in one metadata query, preserving independent global and board pauses. */
    public function filterUnmutedUserIds($boardId, $userIds)
    {
        $boardId = absint($boardId);
        $userIds = array_values(array_unique(array_filter(array_map('absint', (array) $userIds))));
        if (!$userIds) {
            return [];
        }
        $keys = [Constant::USER_NOTIFICATION_PAUSE];
        if ($boardId) {
            $keys[] = Constant::USER_BOARD_NOTIFICATION_PAUSE_PREFIX . $boardId;
        }
        $pauses = Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->whereIn('object_id', $userIds)
            ->whereIn('key', $keys)
            ->get(['object_id', 'value']);
        $mutedIds = [];
        $now = time();
        foreach ($pauses as $pause) {
            if ((int) $pause->value === Constant::NOTIFICATION_PAUSE_INDEFINITE || (int) $pause->value > $now) {
                $mutedIds[(int) $pause->object_id] = true;
            }
        }

        return array_values(array_filter($userIds, function ($userId) use ($mutedIds) {
            return !isset($mutedIds[$userId]);
        }));
    }

    /** Check pause state at delivery time rather than trusting a queued preference snapshot. */
    public function isNotificationMuted($userId, $boardId = 0)
    {
        return empty($this->filterUnmutedUserIds($boardId, [$userId]));
    }

    public function getAllNotifications($per_page, $page, $action = 'all')
    {
        $per_page = Sanitize::perPage($per_page);
        $page = max(1, (int) $page);
        $user = wp_get_current_user();

        $query = $this->getAccessibleNotificationsQuery($user->ID)
            ->whereHas('users', function($q) use ($user) {
                $q->where('user_id', $user->ID);
            });

        // Add action filter if not 'all'
        if ($action !== 'all') {
            $query->where('action', $action);
        }

        $notifications = $query->with('activitist', 'task')
            ->orderBy('created_at', 'desc')
            ->paginate($per_page, ['*'], 'page', $page);

        foreach ($notifications as $notification){
            $notification->read = $notification->checkReadOrNot();
        }

        return  $notifications;
    }

    /**
     * @throws \Exception
     */
    public function getAllUnreadNotifications($per_page, $page)
    {
        $per_page = Sanitize::perPage($per_page);
        $page = max(1, (int) $page);
        $userId = get_current_user_id();
        if (!$userId) {
            throw new \Exception(esc_html__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        $unreadNotifications = $this->getAccessibleNotificationsQuery($userId)
            ->whereHas('users', function($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->whereNull('marked_read_at');
            })->with('activitist', 'task')->orderBy('created_at', 'desc')->paginate($per_page, ['*'], 'page', $page);
        return $unreadNotifications;
    }

    /** Return persisted read receipts, including IDs already read during an earlier attempt. */
    public function markBatchRead($ids)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            throw new RuntimeException(__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        if (!is_array($ids) || count($ids) > self::READ_BATCH_SIZE) {
            throw new InvalidArgumentException(__('Invalid notification selection.', 'fluent-boards'), 422);
        }
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
                throw new InvalidArgumentException(__('Invalid notification selection.', 'fluent-boards'), 422);
            }
        }
        $ids = array_unique(array_map('intval', $ids));
        if (!$ids) {
            return [];
        }
        $selected = NotificationUser::where('user_id', $userId)->whereIn('notification_id', $ids);
        (clone $selected)->whereNull('marked_read_at')->update(['marked_read_at' => current_time('mysql')]);
        return $selected->whereNotNull('marked_read_at')->get(['notification_id', 'marked_read_at']);
    }

    /**
     * Mark every unread notification the current user has on one task as read.
     * Opening a task counts as reading it, so the unread badge on its card clears.
     *
     * @return int Number of notifications that were unread before this call.
     */
    public function markTaskNotificationsRead($taskId)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            throw new RuntimeException(__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        $taskId = absint($taskId);
        if (!$taskId) {
            throw new InvalidArgumentException(__('Invalid task.', 'fluent-boards'), 422);
        }

        // Only this user's own read receipts are touched, so no board access check is needed.
        return (int) NotificationUser::where('user_id', $userId)
            ->whereNull('marked_read_at')
            ->whereIn('notification_id', Notification::query()->select('id')->where('task_id', $taskId))
            ->update(['marked_read_at' => current_time('mysql')]);
    }

    public function markAllRead()
    {
        $user = wp_get_current_user();

        $userNotifications = NotificationUser::where('user_id', $user->ID)
                                ->with('notification')
                                ->get();

        foreach ($userNotifications as $data)
        {
            $data->marked_read_at = current_time('mysql');
            $data->save();
        }
    }

    public function markNotificationRead($notificationId)
    {
        $user = wp_get_current_user();

        $notification = NotificationUser::where('user_id', $user->ID)
                                            ->where('notification_id', $notificationId)
                                            ->first();
        if (!$notification) {
            throw new \Exception(esc_html__('Notification could not be found', 'fluent-boards'), 404);
        }

        $notification->marked_read_at = current_time('mysql');
        $notification->save();
        return $notification;
    }

    public function newNotificationNumber()
    {
        $user = wp_get_current_user();

        $unread_notifications = $this->getAccessibleNotificationsQuery($user->ID)
            ->whereHas('users', function($q) use ($user) {
                $q->where('user_id', $user->ID)
                    ->whereNull('marked_read_at');
            });

        return $unread_notifications->count();
    }

    /**
     * Historical recipients must still have access to the source board and current task.
     * Keep legacy subtasks readable through their parent when their board_id is null.
     */
    private function getAccessibleNotificationsQuery($userId)
    {
        $query = Notification::where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION);
        if (!$userId) {
            return $query->where('id', 0);
        }

        $boardIds = Board::select('id');
        if (!PermissionManager::isAdmin($userId)) {
            $boardIds->whereIn('id', Relation::select('object_id')
                ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
                ->where('foreign_id', $userId));
        }

        return $query->whereIn('object_id', $boardIds)
            ->where(function ($notifications) use ($boardIds) {
                $notifications->whereNull('task_id')
                    ->orWhereHas('task', function ($tasks) use ($boardIds) {
                        $tasks->where(function ($ownedTasks) use ($boardIds) {
                            $ownedTasks->whereIn('board_id', $boardIds)
                                ->orWhere(function ($legacySubtasks) use ($boardIds) {
                                    $legacySubtasks->whereNull('board_id')
                                        ->whereIn('parent_id', Task::select('id')->whereIn('board_id', $boardIds));
                                });
                        });
                    });
            });
    }

    public function isCurrentUserObservingTask($task)
    {
        $currentUserId = get_current_user_id();
        $observers = $task->watchers()->get()->pluck('ID');

        foreach ($observers as $id){
            if($id == $currentUserId)
                return true;
        }

        return false;
    }

    public function getBoardNotificationSettingsOfUser($id, $userId)
    {
        $boardSettings =  Relation::where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('object_id', $id)
            ->where('foreign_id', $userId)
            ->orderBy('id', 'ASC')
            ->first();

        return $boardSettings;
    }

    public function getGlobalNotificationSettingsOfUser($userId)
    {
        $settings =  Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('object_id', $userId)
            ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
            ->orderBy('id')
            ->first();

        return $settings;
    }

    /**
     * Return board notification preferences with defaults and global watch fallbacks applied.
     */
    public function getBoardNotificationSettingsWithDefaults($boardId, $userId)
    {
        return $this->boardNotificationPreferences($boardId, $userId)['currentSettings'];
    }

    /** An empty membership preference set inherits global choices. Legacy saved sets are overrides. */
    public function boardNotificationPreferences($boardId, $userId)
    {
        $membership = $this->getBoardNotificationSettingsOfUser($boardId, $userId);
        $board = (array) ($membership->preferences ?? []);
        $globalRow = $this->getGlobalNotificationSettingsOfUser($userId);
        $global = $this->applyMentionFallback((array) ($globalRow->value ?? []));
        $watch = $this->getWatchNotificationDefaults();
        $defaults = array_merge(Constant::BOARD_NOTIFICATION_TYPES, $watch);
        // Legacy boards without watch keys used the member's global auto-watch settings.
        $values = $board ? array_replace(array_intersect_key($global, $watch), $this->applyMentionFallback($board)) : $global;
        $settings = array_replace($defaults, array_intersect_key($values, $defaults));

        return [
            'currentSettings' => array_map([$this, 'normalizePreferenceValue'], $settings),
            'same_as_global' => !$board,
            'legacy_email_settings' => $this->hasLegacyEmailSettings($board),
            'is_member' => (bool) $membership,
        ];
    }

    /**
     * Check if a board-scoped auto-watch preference is enabled for a user.
     */
    public function isBoardAutoWatchEnabled($boardId, $userId, $preferenceKey)
    {
        $settings = $this->getBoardNotificationSettingsWithDefaults($boardId, $userId);

        return array_key_exists($preferenceKey, $settings) && $this->normalizePreferenceValue($settings[$preferenceKey]);
    }

    private function getWatchNotificationDefaults()
    {
        return [
            Constant::GLOBAL_EMAIL_NOTIFICATION_CREATING_TASK => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENTING    => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_ASSIGNING     => true,
        ];
    }

    /**
     * Mention emails used to follow the comment preference. Until a mention value is saved, reuse the comment one.
     */
    private function applyMentionFallback(array $preferences)
    {
        if (!array_key_exists(Constant::BOARD_EMAIL_MENTION, $preferences) && array_key_exists(Constant::BOARD_EMAIL_COMMENT, $preferences)) {
            $preferences[Constant::BOARD_EMAIL_MENTION] = $preferences[Constant::BOARD_EMAIL_COMMENT];
        }

        return $preferences;
    }

    /**
     * Convert stored preference values into strict booleans.
     */
    private function normalizePreferenceValue($value)
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }

    private function hasLegacyEmailSettings($preferences)
    {
        return !empty($preferences) && empty($preferences[Constant::BOARD_EMAIL_OVERRIDE]);
    }

    /** Store a complete board override, or clear it to resume live global inheritance. */
    public function updateBoardNotificationSettings($newSettings, $id, $merge = false)
    {
        $userId = get_current_user_id();
        $membership = $this->getBoardNotificationSettingsOfUser($id, $userId);
        if (!$membership) {
            throw new RuntimeException(__('You must be a board member to update notification settings.', 'fluent-boards'));
        }

        $allowed = array_merge(Constant::BOARD_NOTIFICATION_TYPES, $this->getWatchNotificationDefaults());
        $settings = $merge ? $this->getBoardNotificationSettingsWithDefaults($id, $userId) : [];
        foreach (array_intersect_key($newSettings, $allowed) as $key => $value) {
            $settings[$key] = $this->normalizePreferenceValue($value);
        }
        if ($settings) {
            $settings[Constant::BOARD_EMAIL_OVERRIDE] = true;
        }
        $membership->preferences = $settings;
        $membership->save();
    }

    public function filterAssigneeToSendEmail($taskId, $emailPurpose){
        $task = Task::findOrFail($taskId);
        $watchers = $task->watchers;
        $currentUserId = get_current_user_id();

        $watcherIds = array_diff($watchers->pluck('ID')->toArray(), [$currentUserId]);
        $eligibleIds = $this->getEmailEnabledUserIds($task->board_id, $watcherIds, $emailPurpose);
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $watchersToSendEmail = [];

        foreach ($watchers as $watcher) {
            if (isset($eligibleLookup[$watcher->ID])) {
                $watchersToSendEmail[] = $watcher->user_email;
            }
        }

        return $watchersToSendEmail;
    }

    /**
     * Get comment notification recipients as revocable user IDs for async delivery.
     *
     * @param int $taskId
     * @return array
     */
    public function getCommentRecipientUserIds($taskId)
    {
        $task = Task::findOrFail(absint($taskId));
        return $this->getWatcherEmailRecipientIds($task, Constant::BOARD_EMAIL_COMMENT);
    }

    /** Snapshot eligible watcher IDs so queued mail can recheck each account at delivery. */
    public function getWatcherEmailRecipientIds($task, $purpose)
    {
        $watcherIds = array_diff($task->watchers->pluck('ID')->toArray(), [get_current_user_id()]);
        return $this->getEmailEnabledUserIds($task->board_id, $watcherIds, $purpose);
    }

    public function checkIfEmailEnable($userId, $emailPurpose, $boardId)
    {
        if(
            $this->checkIfEmailEnabled($boardId, $userId, $emailPurpose)
        ){
            return true;
        }else{
            return false;
        }
    }

    /**
     * Resolve membership overrides and inherited global preferences in batches.
     */
    public function getEmailEnabledUserIds($boardId, $userIds, $purpose)
    {
        $boardId = absint($boardId);
        $userIds = array_values(array_unique(array_filter(array_map('intval', (array) $userIds))));
        if (!$boardId || !$userIds) {
            return [];
        }

        // Match the first membership used by individual checks, including old duplicates.
        $relations = Relation::where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('object_id', $boardId)
            ->whereIn('foreign_id', $userIds)
            ->orderBy('id', 'ASC')
            ->get(['foreign_id', 'preferences'])
            ->unique('foreign_id');

        $inheritingIds = [];
        foreach ($relations as $relation) {
            if (empty($relation->preferences)) {
                $inheritingIds[] = (int) $relation->foreign_id;
            }
        }
        $globals = [];
        if ($inheritingIds) {
            $globals = Meta::where('object_type', Constant::OBJECT_TYPE_USER)
                ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
                ->whereIn('object_id', $inheritingIds)
                ->orderBy('id')
                ->get(['object_id', 'value'])->unique('object_id')->keyBy('object_id');
        }

        $enabledUserIds = [];
        foreach ($relations as $relation) {
            $id = (int) $relation->foreign_id;
            $values = $relation->preferences ?: ($globals[$id]->value ?? []);
            $preferences = $this->applyMentionFallback((array) $values);
            if ($this->normalizePreferenceValue($preferences[$purpose] ?? true)) {
                $enabledUserIds[] = $id;
            }
        }

        return $this->filterUnmutedUserIds($boardId, $enabledUserIds);
    }

    /** Apply personal pauses before the existing board email preference. */
    public function checkIfEmailEnabled($boardId, $userId, $purpose)
    {
        return !empty($this->getEmailEnabledUserIds($boardId, [$userId], $purpose));
    }

    /**
     * Return the supplied user IDs that currently belong to a board.
     *
     * Mention recipients are board-scoped because comment content is private to
     * the board, even when a caller supplies a valid WordPress user ID.
     *
     * @param int $boardId
     * @param array $mentionedUserIds
     * @return array
     */
    public function resolveBoardMentionUserIds($boardId, $mentionedUserIds)
    {
        $boardId = absint($boardId);
        $mentionedUserIds = array_values(array_unique(array_filter(array_map('absint', (array) $mentionedUserIds))));

        if (!$boardId || !$mentionedUserIds) {
            return [];
        }

        $boardMemberIdLookup = [];
        $boardRelations = Relation::where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('object_id', $boardId)
            ->whereIn('foreign_id', $mentionedUserIds)
            ->get(['foreign_id']);

        foreach ($boardRelations as $boardRelation) {
            $boardMemberIdLookup[absint($boardRelation->foreign_id)] = true;
        }

        return array_values(array_filter($mentionedUserIds, function ($mentionedUserId) use ($boardMemberIdLookup) {
            return isset($boardMemberIdLookup[$mentionedUserId]);
        }));
    }

    /** Skip paused mention recipients before enqueueing, so these events are never replayed. */
    public function mentionInComment($comment, $mentionedUserIds)
    {
        $currentUserId = get_current_user_id();
        $uniqueIds = array_values(array_unique(array_filter(array_map('absint', (array) $mentionedUserIds))));
        $uniqueIds = array_values(array_filter($uniqueIds, function ($mentionedUserId) use ($currentUserId) {
            return $mentionedUserId !== $currentUserId;
        }));

        $task = Task::find($comment->task_id);
        if (!$task) {
            return;
        }
        $uniqueIds = $this->filterUnmutedUserIds($task->board_id, $uniqueIds);
        if (!$uniqueIds) {
            return;
        }

        // Queued delivery revalidates membership and preferences before sending.
        $this->sendMailAfterMention($comment->id, $uniqueIds, $task->board_id);

        //sending desktop notifications
        do_action('fluent_boards/mention_comment_notification', $comment, $uniqueIds);
    }

    public function sendMailAfterMention($commentId, $recipientUserIds, $boardId = 0)
    {
        if (empty($recipientUserIds)) {
            return;
        }

        TaskEmailDigest::enqueue('mention', $commentId, $recipientUserIds, $boardId);
    }

    public function getUnreadNotificationsOfTasks($task)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            throw new \Exception(esc_html__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        $unreadNotifications = $this->getAccessibleNotificationsQuery($userId)
            ->whereHas('users', function($q) use ($userId) {
                $q->where('user_id', $userId)
                    ->whereNull('marked_read_at');
            })->whereHas('task', function($q) use ($task) {
                $q->where('id', $task->id);
            })
            ->with('activitist')->orderBy('created_at', 'desc')->count();
        return $unreadNotifications;
    }

    public function getUnreadNotificationCountsByTaskIds($taskIds)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            throw new \Exception(esc_html__('You are not allowed to do that', 'fluent-boards'), 403);
        }

        $taskIds = array_values(array_filter(array_map('intval', (array) $taskIds)));
        if (!$taskIds) {
            return [];
        }

        $rows = $this->getAccessibleNotificationsQuery($userId)
            // Board/list views can return many tasks at once, so unread counts are
            // grouped in one query instead of issuing a count query per task.
            ->selectRaw('task_id, COUNT(*) as unread_count')
            ->join(
                (new NotificationUser())->getTable(),
                'fbs_notifications.id',
                '=',
                'fbs_notification_users.notification_id'
            )
            ->where('fbs_notification_users.user_id', $userId)
            ->whereNull('fbs_notification_users.marked_read_at')
            ->whereIn('task_id', $taskIds)
            ->groupBy('task_id')
            ->get();

        $notificationCounts = [];
        foreach ($rows as $row) {
            $notificationCounts[(int) $row->task_id] = (int) $row->unread_count;
        }

        return $notificationCounts;
    }
}
