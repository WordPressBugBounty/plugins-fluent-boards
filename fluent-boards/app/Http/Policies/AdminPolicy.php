<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

class AdminPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentBoards\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::isAdmin();
    }
}
