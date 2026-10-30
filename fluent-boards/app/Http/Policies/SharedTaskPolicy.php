<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\TaskShareService;
use FluentBoards\Framework\Http\Request\Request;

/**
 * Task share links: read-only access for anyone holding a valid share token, no WordPress account needed.
 */
class SharedTaskPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        if (strtoupper($request->getMethod()) !== 'GET') {
            return false;
        }

        $token = $request->getSafe('share_token', 'sanitize_text_field');
        $shared = (new TaskShareService())->findSharedTask($token);

        return (bool)$shared;
    }
}
