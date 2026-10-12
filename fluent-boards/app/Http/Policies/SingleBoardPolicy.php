<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

class SingleBoardPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentBoards\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        // Authorize the controller's URL target, never a query/body override.
        $routeParams = $request->get_url_params();
        $boardId = absint($routeParams['board_id'] ?? 0);

        return PermissionManager::userHasBoardPermission($boardId, $request->getMethod());
    }

    public function delete(Request $request)
    {
        return PermissionManager::isAdmin();
    }

    /**
     * Task AI actions. `summarize` only reads content the member can already see,
     * so it is treated as a read for permission purposes — otherwise viewer-only
     * members would be denied an action the UI intentionally offers them. Every
     * other action writes to the task and keeps the normal write restrictions.
     */
    public function taskAssist(Request $request)
    {
        $routeParams = $request->get_url_params();
        $boardId = absint($routeParams['board_id'] ?? 0);
        $method = $request->get('action') === 'summarize' ? 'GET' : $request->getMethod();

        return PermissionManager::userHasBoardPermission($boardId, $method);
    }

    /**
     * Allow board role changes while reserving admin targets for global admins.
     */
    public function makeManager(Request $request)
    {
        return $this->canManageBoardUser($request);
    }

    /**
     * Allow board role changes while reserving admin targets for global admins.
     */
    public function removeManager(Request $request)
    {
        return $this->canManageBoardUser($request);
    }

    /**
     * Allow board role changes while reserving admin targets for global admins.
     */
    public function makeViewer(Request $request)
    {
        return $this->canManageBoardUser($request);
    }

    /**
     * Allow board role changes while reserving admin targets for global admins.
     */
    public function makeMember(Request $request)
    {
        return $this->canManageBoardUser($request);
    }

    public function getInvitations(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    public function deleteInvitation(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict board membership changes to board managers.
     */
    public function addMembersInBoard(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Allow members to leave; only global admins may remove admin targets.
     */
    public function removeUserFromBoard(Request $request)
    {
        $routeParams = $request->get_url_params();
        $boardId = absint($routeParams['board_id'] ?? 0);
        $targetUserId = absint($routeParams['user_id'] ?? 0);
        $currentUserId = get_current_user_id();

        if (!$boardId || !$targetUserId) {
            return false;
        }

        if ($currentUserId && $targetUserId === $currentUserId) {
            return PermissionManager::userHasPermission($boardId, $currentUserId);
        }

        return $this->canManageBoardUser($request);
    }

    /**
     * Restrict permanent task deletion to board managers.
     */
    public function deleteTask(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict permanent bulk task deletion to board managers.
     */
    public function bulkDeleteTasks(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict board background changes to board managers.
     */
    public function setBoardBackground(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict board background uploads to board managers.
     */
    public function uploadBoardBackground(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict public board exposure to board managers.
     */
    public function togglePublicAccess(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict custom-field creation to board managers (Pro).
     */
    public function createCustomField(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict custom-field edits to board managers (Pro).
     */
    public function updateCustomField(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict custom-field deletion to board managers (Pro).
     */
    public function deleteCustomField(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict custom-field reordering to board managers (Pro).
     */
    public function updateCustomFieldPosition(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict stage default-assignee configuration to board managers (Pro).
     */
    public function setDefaultAssignees(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Restrict stage default-watcher configuration to board managers (Pro).
     */
    public function setDefaultWatchers(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    /**
     * Keep roadmap and board-level properties global-admin-only.
     */
    public function updateBoardProperties(Request $request)
    {
        return PermissionManager::isAdmin();
    }

    /**
     * Keep whole-board archival global-admin-only.
     */
    public function archiveBoard(Request $request)
    {
        return PermissionManager::isAdmin();
    }

    /**
     * Keep whole-board restoration global-admin-only.
     */
    public function restoreBoard(Request $request)
    {
        return PermissionManager::isAdmin();
    }

    /**
     * Managers can manage ordinary board users; global admins alone can manage admins.
     */
    private function canManageBoardUser(Request $request)
    {
        // Match controller route arguments; body/query IDs must not authorize another target.
        $routeParams = $request->get_url_params();
        $boardId = absint($routeParams['board_id'] ?? 0);
        $targetUserId = absint($routeParams['user_id'] ?? 0);

        if (!$boardId || !$targetUserId) {
            return false;
        }

        if (PermissionManager::isAdmin($targetUserId)) {
            return PermissionManager::isAdmin();
        }

        return PermissionManager::isBoardManager($boardId);
    }

    /**
     * Resolve board-manager access from the sanitized route board id.
     */
    private function userIsBoardManager(Request $request)
    {
        $routeParams = $request->get_url_params();

        return PermissionManager::isBoardManager(absint($routeParams['board_id'] ?? 0));
    }


}
