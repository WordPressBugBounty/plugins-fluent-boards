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
        if (!PermissionManager::hasAppAccess()) {
            return false;
        }

        // Match the controller's URL target; query/body parameters must not override it.
        $routeParams = $request->get_url_params();
        if (!array_key_exists('id', $routeParams)) {
            return true;
        }

        $targetUserId = intval($routeParams['id']);

        return $targetUserId > 0 && PermissionManager::userCanAccessMemberProfile($targetUserId);
    }

    public function globalSearch(Request $request)
    {
        return true;
    }

}
