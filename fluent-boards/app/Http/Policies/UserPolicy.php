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

    /** Only an authenticated Fluent Boards user may change their own name. */
    public function updateDisplayName(Request $request)
    {
        $routeParams = $request->get_url_params();
        $currentUserId = get_current_user_id();
        return $currentUserId > 0
            && (int) ($routeParams['id'] ?? 0) === $currentUserId
            && PermissionManager::isFluentBoardsUser($currentUserId);
    }

    /** Apply the same ownership check to profile photo uploads. */
    public function updateProfilePhoto(Request $request)
    {
        return $this->updateDisplayName($request);
    }

    /** MCP connections are managed only by their owner. */
    public function getMcpConnections(Request $request)
    {
        $routeParams = $request->get_url_params();
        $currentUserId = get_current_user_id();

        return $currentUserId > 0 && (int) ($routeParams['id'] ?? 0) === $currentUserId;
    }

    public function createMcpConnection(Request $request)
    {
        return $this->getMcpConnections($request);
    }

    public function revokeMcpConnection(Request $request)
    {
        return $this->getMcpConnections($request);
    }

    public function globalSearch(Request $request)
    {
        return true;
    }

}
