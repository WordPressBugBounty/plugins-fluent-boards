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
            Constant::GLOBAL_EMAIL_NOTIFICATION_CREATING_TASK => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENTING => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_ASSIGNING => true
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
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_DASHBOARD_VIEW;
            $meta->value = Constant::DEFAULT_DASHBOARD_VIEW_PREFERENCES;
            $meta->save();

            return $meta;
        }

        return $dshboardViewSettings;
    }

    public function getListViewPreferences()
    {
        $userId = get_current_user_id();
        $dshboardViewSettings = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_LISTVIEW_PREFERENCES)
            ->first();
        //if no settings found of this user then store default
        if(!$dshboardViewSettings) {
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_LISTVIEW_PREFERENCES;
            $meta->value = Constant::DEFAULT_DASHBOARD_VIEW_PREFERENCES;
            $meta->save();

            return $meta;
        }

        return $dshboardViewSettings;
    }
    public function updateDashboardViewSettings($newSettings, $view)
    {
        $userId = get_current_user_id();
    
        if ($view == 'listview') {
            $viewWiseKey = Constant::USER_LISTVIEW_PREFERENCES;
        } elseif ($view == 'tableview') {
            $viewWiseKey = Constant::USER_TABLEVIEW_PREFERENCES;
        } else {
            $viewWiseKey = Constant::USER_DASHBOARD_VIEW;
        }
    
        $dshboardViewSettings = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', $viewWiseKey)
            ->first();
    
        foreach ($newSettings as $index => $setting)
        {
            $newSettings[$index] = $setting == 'true' ? true : false;
        }
    
        $dshboardViewSettings->value = $newSettings;
        $dshboardViewSettings->save();
    }
    
    // Add this new method
    public function getTableViewPreferences()
    {
        $userId = get_current_user_id();
        $tableViewSettings = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_TABLEVIEW_PREFERENCES)
            ->first();
        if (!$tableViewSettings) {
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_TABLEVIEW_PREFERENCES;
            $meta->value = Constant::DEFAULT_TABLEVIEW_VIEW_PREFERENCES;
            $meta->save();
    
            return $meta;
        }
    
        return $tableViewSettings;
    }
}
