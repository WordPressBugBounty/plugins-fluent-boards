<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\Constant;

class PermissionManager
{
    public static function getFormattedBoardPermissions()
    {
        return [
            'board_admin'  => 'Board Admin',
            'create_tasks' => 'Create Tasks',
            'edit_tasks'   => 'Edit Tasks',
            'delete_tasks' => 'Delete Tasks',
        ];
    }

    public static function getTopLevelPermissions()
    {
        return [
            'all_board_admin'        => 'All Board Admin',
            'create_boards'          => 'Create Boards',
            'edit_boards'            => 'Edit Boards',
            'delete_boards'          => 'Delete Boards',
            'can_manage_permissions' => 'Can Manage Permissions',
        ];
    }

    public static function getBoardPermissions($boardId, $userId)
    {
        if (user_can($userId, 'manage_options')) {
            return array_keys(self::getFormattedBoardPermissions());
        }

        $globalPermissions = self::getTopUserPermission($userId);

        if (in_array('all_board_admin', $globalPermissions)) {
            return array_keys(self::getFormattedBoardPermissions());
        }

        // The user does not have global permission now we are checking for board permission

        $isUserOn = Relation::query()->where('board_id', $boardId)->where('user_id', $userId)->exists();

        $permissions = [

        ];

        if (in_array('board_admin', $permissions)) {
            return array_keys(self::getFormattedBoardPermissions());
        }

        return $permissions;
    }

    private static function getTopUserPermission($userId)
    {
        $allAccesses = ['all_board_admin'];

        $userPermission = ['create_boards', 'edit_boards', 'delete_boards', 'can_manage_permissions'];

        return array_intersect($allAccesses, $userPermission);
    }

    public static function userHasBoardAccess($boardId, $userId = null, $permissions = [])
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return false;
        }

        $userPermissions = self::getBoardPermissions($boardId, $userId);

        if (!$permissions) {
            return false;
        }

        return (bool)array_intersect($permissions, $userPermissions);
    }

    public static function isBoardManager($boardId, $userId = null, $useCache = false)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        if (static::isAdmin($userId, $useCache)) {
            return true;
        }

        $boardUser = Relation::where('object_id', $boardId)
            ->where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->first();

        if (!$boardUser) {
            return false;
        }

        return (bool)$boardUser->settings ? $boardUser->settings['is_admin'] : null;

    }

    public static function userHasAnyBoardAccess($userId = null): bool
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        if (!$userId) {
            return false;
        }

        if (static::isAdmin($userId)) {
            return true;
        }

        return Relation::where('foreign_id', $userId)
            ->where('object_type', 'board_user')
            ->exists();
    }

    /// this is function currently being used , we will go permission way next time
    public static function userHasPermission($boardId, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        // if boardId is not provided then we will return false
        if (!$boardId) {
            return false;
        }

        if (static::isAdmin($userId)) {
            return true; // if task some admin we will allow him.
        }


        /* now, if user is board admin or board member then will allow him */
        return Relation::where('object_id', $boardId)
            ->where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->exists();
    }

    public static function isFluentBoardsAdmin($userId = null): bool
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        $isExists = (bool)Meta::where('object_id', $userId)
            ->where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->exists();

        if (!$isExists) {
            return false;
        }

        return true;
    }

    public static function isFluentBoardsUser($userId = null): bool
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        if (static::isAdmin($userId)) {  // boardAdmin is super admin and this function returns if loggedUser is task superadmin or not.
            return true; // if board super admin we will allow him.
        }

        $isExists = (bool)Relation::where('foreign_id', $userId)
            ->whereNotNull('object_id')
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->exists();
        if (!$isExists) {
            return false;
        }

        return true;
    }

    public static function isAdmin($userId = null, $useCache = true)
    {

        static $cache = [];

        if (isset($cache[$userId]) && $useCache) {
            return $cache[$userId];
        }

        /*
         * Two types of admin we have
         * 1. WordPress admin
         * 2. FluentBoard admin
         * if user is WordPress admin then we will allow him
         * if user is FluentBoard admin then we will allow him
         */

        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        if (!$userId) {
            return false;
        }

        if (user_can($userId, 'manage_options')) { // WordPress Administrator

            $cache[$userId] = true;

            return true;
        }

        if (static::isFluentBoardsAdmin($userId)) { // FluentBoard Plugin Administrator
            $cache[$userId] = true;
            return true;
        }

        $cache[$userId] = false;
        return false;
    }

    /**
     * Get array of board Ids for logged-in user
     * @param null $userId
     * @return array
     */
    public static function getBoardIdsForUser($userId = null, $boardId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return [];
            }
        }

        if (static::isAdmin($userId)) {
            if ($boardId) {
                return Board::where('archived_at', null)->where('id', $boardId)->pluck('id')->toArray();
            }
            return Board::where('archived_at', null)->pluck('id')->toArray();
        }

        return Relation::where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->pluck('object_id')->toArray();
    }

    public static function getTaskIdsWatchByUser($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return [];
            }
        }

        return Relation::where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER_TASK_WATCH)
            ->pluck('object_id')->toArray();
    }

    public static function userCan($permission, $userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        if (static::isAdmin($userId)) {
            return true;
        }
        // if user has edit_posts capability then we will allow him
        if (user_can($userId, 'edit_posts')) {
            return true;
        }
    }

    public static function hasAppAccess($userId = null)
    {
        return !!is_user_logged_in();
    }

    public static function getAll_WP_Admins($searchquery = '')
    {
        $args = array(
            'role'       => '',
            'capability' => 'manage_options',
            'search'     => '*' . $searchquery . '*',
        );

        return get_users($args);
    }

    public static function isWPAdmin($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false;
            }
        }

        return user_can($userId, 'manage_options');
    }

    public static function userHasBoardPermission($boardId, $requestMethod, $userId = null)
    {
        // Get the current user if no user ID is provided
        if (!$userId) {
            $userId = get_current_user_id();
            if (!$userId) {
                return false; // Return false if no user is logged in
            }
        }

        // Ensure the board ID is valid
        if (!$boardId) {
            return false; // Return false if no board ID is provided
        }

        // Admins have full permissions, allow access immediately
        if (static::isAdmin()) {
            return true;
        }

        // Fetch user permissions for the given board
        $boardPermissions = Relation::where('object_id', $boardId)
            ->where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->first();

        // If no permissions are found, deny access
        if (!$boardPermissions) {
            return false;
        }

        // Allow read-only access for GET requests
        if ($requestMethod === 'GET') {
            return true;
        }

        // Deny access if the user is a viewer only and trying to modify data
        return !($boardPermissions->settings['is_viewer_only'] ?? false);
    }
}
