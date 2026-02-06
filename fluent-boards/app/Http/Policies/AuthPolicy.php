<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;

class AuthPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentBoards\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return !!get_current_user_id();
    }

    public function create()
    {
        return PermissionManager::userHasBoardCreationPermission();
    }

    public function skipOnboarding()
    {
        return PermissionManager::isAdmin();
    }

    public function createFirstBoard()
    {
        return PermissionManager::isAdmin();
    }

    public function getUsersOfBoards()
    {
        return PermissionManager::userHasAnyBoardAccess();
    }
}
