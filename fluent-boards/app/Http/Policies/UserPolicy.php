<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;

class UserPolicy extends BasePolicy
{

    /**
     * @param  Request  $request
     *
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        // Check if user has access to the app
        return PermissionManager::hasAppAccess();
    }

    public function quickSearch(Request $request)
    {
        return true;
    }

}
