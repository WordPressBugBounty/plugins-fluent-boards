<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\User;
use FluentBoards\App\App;

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

    private function getDefaultGlobalNotificationSettings()
    {
        return [
            Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENT => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_MENTION => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_STAGE_CHANGE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_TASK_ASSIGN => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_DUE_DATE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_REMOVE_FROM_TASK => true,
            Constant::BOARD_EMAIL_PRIORITY_CHANGE => true,
            Constant::BOARD_EMAIL_DESCRIPTION_UPDATE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_TASK_ARCHIVE => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_CREATING_TASK => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENTING => true,
            Constant::GLOBAL_EMAIL_NOTIFICATION_ASSIGNING => true
        ];
    }

    private function normalizeNotificationPreferenceValue($value)
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }

    /**
     * @param array      $newSettings
     * @param array|null $onlyKeys When given, only these global keys change.
     *                             Boards without overrides read these values at delivery time.
     */
    public function updateGlobalNotificationSettings($newSettings, $onlyKeys = null)
    {
        $userId = get_current_user_id();

        $allowedSettings = $this->getDefaultGlobalNotificationSettings();
        $newSettings = array_intersect_key($newSettings, $allowedSettings);
        if (is_array($onlyKeys)) {
            $newSettings = array_intersect_key($newSettings, array_flip($onlyKeys));
        }

        $filteredSettings = [];
        foreach ($newSettings as $index => $setting) {
            $filteredSettings[$index] = $this->normalizeNotificationPreferenceValue($setting);
        }

        App::getInstance('db')->transaction(function () use ($userId, $onlyKeys, $allowedSettings, $filteredSettings) {
            $globalNotification = $this->loadGlobalNotificationSettings($userId);
            $baseSettings = is_array($onlyKeys) && is_array($globalNotification->value)
                ? $globalNotification->value
                : $allowedSettings;
            $globalNotification->value = array_merge($baseSettings, $filteredSettings);
            $globalNotification->save();
        });
    }

    public function getGlobalNotificationSettings()
    {
        return App::getInstance('db')->transaction(function () {
            return $this->loadGlobalNotificationSettings(get_current_user_id());
        });
    }

    /** Called inside a transaction so concurrent first visits and partial saves share one row. */
    private function loadGlobalNotificationSettings($userId)
    {
        User::where('ID', $userId)->lockForUpdate()->firstOrFail(['ID']);
        $rows = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_GLOBAL_NOTIFICATIONS)
            ->orderBy('id')->lockForUpdate()->get();
        $globalNotification = $rows->first();
        if ($rows->count() > 1) {
            Meta::whereIn('id', $rows->slice(1)->pluck('id')->all())->delete();
        }

        //default notification settings
        $newSettingsArray = $this->getDefaultGlobalNotificationSettings();

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

        $storedSettings = maybe_unserialize($globalNotification->value);
        if (is_array($storedSettings)) {
            // Mention emails used to follow the comment preference; keep that until the user sets it.
            if (!array_key_exists(Constant::GLOBAL_EMAIL_NOTIFICATION_MENTION, $storedSettings) && array_key_exists(Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENT, $storedSettings)) {
                $storedSettings[Constant::GLOBAL_EMAIL_NOTIFICATION_MENTION] = $storedSettings[Constant::GLOBAL_EMAIL_NOTIFICATION_COMMENT];
            }
            $filteredSettings = array_merge($newSettingsArray, array_intersect_key($storedSettings, $newSettingsArray));
            foreach ($filteredSettings as $index => $setting) {
                $filteredSettings[$index] = $this->normalizeNotificationPreferenceValue($setting);
            }

            if ($filteredSettings !== $storedSettings) {
                $globalNotification->value = $filteredSettings;
                $globalNotification->save();
            } else {
                $globalNotification->value = $filteredSettings;
            }
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
