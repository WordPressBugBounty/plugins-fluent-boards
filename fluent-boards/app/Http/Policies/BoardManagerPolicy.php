<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

class BoardManagerPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentBoards\Framework\Request\Request $request
     * @param  int $board_id
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::isBoardManager($request->board_id);
    }
}
