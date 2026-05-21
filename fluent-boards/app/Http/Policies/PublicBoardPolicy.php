<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\PublicAccessService;
use FluentBoards\Framework\Http\Request\Request;

class PublicBoardPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        $boardId = absint($request->board_id);
        if (!$boardId) {
            return false;
        }

        $token = $request->getSafe('public_token', 'sanitize_text_field');
        if (!PublicAccessService::validateAccessToken($boardId, $token)) {
            return false;
        }

        $board = Board::where('id', $boardId)->whereNull('archived_at')->first();
        if (!$board) {
            return false;
        }

        return (bool)$board->getMetaByKey('public_access_enabled');
    }
}

