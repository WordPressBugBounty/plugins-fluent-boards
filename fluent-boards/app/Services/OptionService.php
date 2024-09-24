<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;

class OptionService
{
    public function createSuperAdmin($userId)
    {
        $existUser = $this->searchUserMeta($userId);
        if (!$existUser) {
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::FLUENT_BOARD_ADMIN;
            $meta->save();
        }
    }

    public function removeUserSuperAdmin($userId)
    {
        $existUser = $this->searchUserMeta($userId);
        if ($existUser) {
            $existUser->delete();
        }
    }

    private function searchUserMeta($userId)
    {
        return Meta::query()->where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::FLUENT_BOARD_ADMIN)
            ->first();
    }

    public function updateGlobalNotificationSettings($newSettings)
    {
        $userId = get_current_user_id();

        $globalNotification = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
            ->first();

        foreach ($newSettings as $index => $setting)
        {
            $newSettings[$index] = $setting == 'true' ? true : false;
        }

        $globalNotification->value = $newSettings;
        $globalNotification->save();

        //set this settings to all board
        $relatedBoardsQuery = Board::where('type', 'to-do')->byAccessUser($userId)->get();
        $notificationService = new NotificationService();
        foreach ($relatedBoardsQuery as $board)
        {
            $notificationService->updateBoardNotificationSettings($newSettings, $board->id);
        }
    }

    public function getGlobalNotificationSettings()
    {
        $userId = get_current_user_id();
        $globalNotification = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
            ->first();

        //default notification settings
        $newSettingsArray = [
            Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENT => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_STAGE_CHANGE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_TASK_ASSIGN => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_DUE_DATE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_REMOVE_FROM_TASK => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_TASK_ARCHIVE => true,
        ];

        //if no settings found of this user then store default
        if(!$globalNotification) {
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_GLOBAL_NOTIFICATIONS;
            $meta->value = $newSettingsArray;
            $meta->save();

            return $meta;
        }

        return $globalNotification;
    }

    public function getDashboardViewSettings()
    {
        $userId = get_current_user_id();
        $dshboardViewSettings = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_DASHBOARD_VIEW)
            ->first();

        //if no settings found of this user then store default
        if(!$dshboardViewSettings) {
            //default notification settings
            $newSettingsArray = [
                'dashboard_view_label' => true,
                'dashboard_view_priority' => true,
                'dashboard_view_assignee' => true,
                'dashboard_view_subtask' => true,
                'dashboard_view_due_date' => true,
                'dashboard_view_comment' => true,
                'dashboard_view_notification' => true
            ];

            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_DASHBOARD_VIEW;
            $meta->value = $newSettingsArray;
            $meta->save();

            return $meta;
        }

        return $dshboardViewSettings;
    }
    public function updateDashboardViewSettings($newSettings)
    {
        $userId = get_current_user_id();

        $dshboardViewSettings = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_DASHBOARD_VIEW)
            ->first();

        foreach ($newSettings as $index => $setting)
        {
            $newSettings[$index] = $setting == 'true' ? true : false;
        }

        $dshboardViewSettings->value = $newSettings;
        $dshboardViewSettings->save();
    }
}
