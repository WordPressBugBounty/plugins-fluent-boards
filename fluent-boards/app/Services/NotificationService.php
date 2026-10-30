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
            ->first();

        return $boardSettings;
    }

    public function getGlobalNotificationSettingsOfUser($userId)
    {
        $settings =  Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('object_id', $userId)
            ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
            ->first();

        return $settings;
    }

    /**
     * Return board notification preferences with defaults and global watch fallbacks applied.
     */
    public function getBoardNotificationSettingsWithDefaults($boardId, $userId)
    {
        $settings = $this->getDefaultBoardNotificationSettings($userId);

        $boardSettings = $this->getBoardNotificationSettingsOfUser($boardId, $userId);
        if ($boardSettings && $boardSettings->preferences) {
            $preferences = maybe_unserialize($boardSettings->preferences);
            if (is_array($preferences)) {
                $settings = array_merge($settings, array_intersect_key($preferences, $settings));
            }
        }

        foreach ($settings as $key => $value) {
            $settings[$key] = $this->normalizePreferenceValue($value);
        }

        return $settings;
    }

    /**
     * Check if a board-scoped auto-watch preference is enabled for a user.
     */
    public function isBoardAutoWatchEnabled($boardId, $userId, $preferenceKey)
    {
        $settings = $this->getBoardNotificationSettingsWithDefaults($boardId, $userId);

        return array_key_exists($preferenceKey, $settings) && $this->normalizePreferenceValue($settings[$preferenceKey]);
    }

    /**
     * Get global watch preferences for fallback when a board has no saved watch keys.
     */
    private function getGlobalWatchNotificationSettings($userId)
    {
        $watchSettings = $this->getWatchNotificationDefaults();

        $globalSettings = $this->getGlobalNotificationSettingsOfUser($userId);
        if (!$globalSettings || !$globalSettings->value) {
            return $watchSettings;
        }

        $preferences = maybe_unserialize($globalSettings->value);
        if (!is_array($preferences)) {
            return $watchSettings;
        }

        foreach (array_keys($watchSettings) as $key) {
            if (array_key_exists($key, $preferences)) {
                $watchSettings[$key] = $this->normalizePreferenceValue($preferences[$key]);
            }
        }

        return $watchSettings;
    }

    private function getDefaultBoardNotificationSettings($userId)
    {
        return array_merge(
            Constant::BOARD_NOTIFICATION_TYPES,
            $this->getGlobalWatchNotificationSettings($userId)
        );
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
     * Convert stored preference values into strict booleans.
     */
    private function normalizePreferenceValue($value)
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }

    public function updateBoardNotificationSettings($newSettings, $id)
    {
        $userId = get_current_user_id();
        $boardSettings =  $this->getBoardNotificationSettingsOfUser($id, $userId);
        if(empty($boardSettings)){
            return;
        }

        $allowedSettings = array_merge(
            Constant::BOARD_NOTIFICATION_TYPES,
            $this->getWatchNotificationDefaults()
        );
        $filteredSettings = [];
        foreach ($newSettings as $index => $setting) {
            if (!array_key_exists($index, $allowedSettings)) {
                continue;
            }

            $filteredSettings[$index] = $this->normalizePreferenceValue($setting);
        }

        $boardSettings->preferences = $filteredSettings;
        $boardSettings->save();

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
        $currentUserId = get_current_user_id();
        $watchers = $task->watchers;
        $watcherIds = array_diff($watchers->pluck('ID')->toArray(), [$currentUserId]);
        $eligibleIds = $this->getEmailEnabledUserIds($task->board_id, $watcherIds, Constant::BOARD_EMAIL_COMMENT);
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $recipientUserIds = [];

        foreach ($watchers as $watcher) {
            $watcherId = absint($watcher->ID);
            if (isset($eligibleLookup[$watcherId])) {
                $recipientUserIds[] = $watcherId;
            }
        }

        return array_values(array_unique($recipientUserIds));
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
     * Resolve board email recipients in one query; missing preference keys default to enabled without writes.
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

        $enabledUserIds = [];
        foreach ($relations as $relation) {
            $preferences = (array) maybe_unserialize($relation->preferences);
            if (!array_key_exists($purpose, $preferences) || $preferences[$purpose]) {
                $enabledUserIds[] = (int) $relation->foreign_id;
            }
        }

        return $this->filterUnmutedUserIds($boardId, $enabledUserIds);
    }

    /** Apply personal pauses before the existing board email preference. */
    public function checkIfEmailEnabled($boardId, $userId, $purpose)
    {
        if ($this->isNotificationMuted($userId, $boardId)) {
            return false;
        }

        $boardSettings = $this->getBoardNotificationSettingsOfUser($boardId, $userId);

        if($boardSettings){
            $preferences = maybe_unserialize($boardSettings['preferences']);
            if(!array_key_exists($purpose, $preferences)){
                $preferences[$purpose] = true;
                $boardSettings->preferences = $preferences;
                $boardSettings->save();
                return true;
            }
            return $preferences[$purpose];
        }

        return false;
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
        $this->sendMailAfterMention($comment->id, $uniqueIds);

        //sending desktop notifications
        do_action('fluent_boards/mention_comment_notification', $comment, $uniqueIds);
    }

    public function sendMailAfterMention($commentId, $recipientUserIds)
    {
        if (empty($recipientUserIds)) {
            return;
        }

        $current_user_id = get_current_user_id();

        /* this will run in background as soon as possible */
        /* sending Model or Model Instance won't work here */
        as_enqueue_async_action('fluent_boards/one_time_schedule_send_email_for_mention', [$commentId, $recipientUserIds, $current_user_id], 'fluent-boards');
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
