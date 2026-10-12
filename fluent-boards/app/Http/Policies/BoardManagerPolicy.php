<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

class BoardManagerPolicy extends BasePolicy
{
    /**
     * Require management access to the route or report board; only admins may omit it.
     * @param  \FluentBoards\Framework\Http\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        $routeParams = $request->get_url_params();

        // Timesheet routes pass their board filter as an input, not a URL parameter.
        $boardId = $routeParams['board_id'] ?? $request->get('board_id', 0);
        $boardId = filter_var($boardId === '' ? 0 : $boardId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0]
        ]);

        return $boardId !== false && PermissionManager::isBoardManager($boardId);
    }
}
