<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

class SingleBoardPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentBoards\Framework\Http\Request\Request $request
     * @param  int $board_id
     * @return bool
     */
    public function verifyRequest(Request $request)
    {

        return PermissionManager::userHasBoardPermission($request->board_id, $request->getMethod());
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
        $method = $request->get('action') === 'summarize' ? 'GET' : $request->getMethod();

        return PermissionManager::userHasBoardPermission($request->board_id, $method);
    }

    public function makeManager(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    public function removeManager(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    public function makeViewer(Request $request)
    {
        return $this->userIsBoardManager($request);
    }

    public function makeMember(Request $request)
    {
        return $this->userIsBoardManager($request);
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
     * Allow members to leave a board while restricting removal of others.
     */
    public function removeUserFromBoard(Request $request)
    {
        $boardId = absint($request->board_id);
        $targetUserId = absint($request->user_id);
        $currentUserId = get_current_user_id();

        if ($currentUserId && $targetUserId === $currentUserId) {
            return PermissionManager::userHasPermission($boardId, $currentUserId);
        }

        return PermissionManager::isBoardManager($boardId);
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
     * Resolve board-manager access from the sanitized route board id.
     */
    private function userIsBoardManager(Request $request)
    {
        return PermissionManager::isBoardManager(absint($request->board_id));
    }


}
