<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Notification;
use FluentBoards\App\Models\NotificationUser;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;

class NotificationService
{
    public function getAllNotifications($per_page, $page, $action = 'all')
    {
        $user = wp_get_current_user();

        $query = Notification::where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
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
        $userId = get_current_user_id();
        if (!$userId) {
            throw new \Exception(esc_html__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        $unreadNotifications = Notification::where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
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

        $unread_notifications = Notification::query()->where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
            ->whereHas('users', function($q) use ($user) {
                $q->where('user_id', $user->ID)
                    ->whereNull('marked_read_at');
            });

        return $unread_notifications->count();
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

    public function updateBoardNotificationSettings($newSettings, $id)
    {
        $userId = get_current_user_id();
        $boardSettings =  $this->getBoardNotificationSettingsOfUser($id, $userId);
        if(empty($boardSettings)){
            return;
        }
        foreach ($newSettings as $index => $setting)
        {
            $newSettings[$index] = $setting == 'true' ? true : false;
        }
        $boardSettings->preferences = $newSettings;
        $boardSettings->save();

    }

    public function filterAssigneeToSendEmail($taskId, $emailPurpose){
        $task = Task::findOrFail($taskId);
        $watchers = $task->watchers;
        $currentUserId = get_current_user_id();

        $wathersToSendEmail = array();

        foreach ($watchers as $watcher){
            if($watcher->ID != $currentUserId)
            {
                if($this->checkIfEmailEnable($watcher->ID, $emailPurpose, $task->board_id))
                {
                    $wathersToSendEmail[] = $watcher->user_email;
                }
            }
        }

        return $wathersToSendEmail;
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

    public function checkIfEmailEnabled($boardId, $userId, $purpose)
    {
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

    public function mentionInComment($comment, $mentionedUserIds)
    {
        $uniqueIds = array_unique($mentionedUserIds);

        $uniqueIds = array_filter($uniqueIds, function($value) {
            return (int)$value !== get_current_user_id();
        });

        //sending emails to mentioned users
        $mentionedUserEmails = User::whereIn('ID', $uniqueIds)->pluck('user_email');
        $this->sendMailAfterMention($comment->id, $mentionedUserEmails);

        //sending desktop notifications
        do_action('fluent_boards/mention_comment_notification', $comment, $uniqueIds);
    }

    public function sendMailAfterMention($commentId, $usersToSendEmail)
    {
        $current_user_id = get_current_user_id();

        /* this will run in background as soon as possible */
        /* sending Model or Model Instance won't work here */
        as_enqueue_async_action('fluent_boards/one_time_schedule_send_email_for_mention', [$commentId, $usersToSendEmail, $current_user_id], 'fluent-boards');
    }

    public function getUnreadNotificationsOfTasks($task)
    {
        $userId = get_current_user_id();
        if (!$userId) {
            throw new \Exception(esc_html__('You are not allowed to do that', 'fluent-boards'), 403);
        }
        $unreadNotifications = Notification::where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
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

        $rows = Notification::query()
            // Board/list views can return many tasks at once, so unread counts are
            // grouped in one query instead of issuing a count query per task.
            ->selectRaw('task_id, COUNT(*) as unread_count')
            ->join(
                (new NotificationUser())->getTable(),
                'fbs_notifications.id',
                '=',
                'fbs_notification_users.notification_id'
            )
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
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
