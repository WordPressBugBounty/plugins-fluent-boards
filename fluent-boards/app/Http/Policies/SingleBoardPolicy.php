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
        return PermissionManager::isBoardManager($request->board_id);
    }


}
