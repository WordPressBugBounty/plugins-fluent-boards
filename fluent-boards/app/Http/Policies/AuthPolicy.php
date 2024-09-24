<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;

class AuthPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentBoards\Framework\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return !!get_current_user_id();
    }

    public function create()
    {
        return PermissionManager::hasAppAccess();
    }
}
