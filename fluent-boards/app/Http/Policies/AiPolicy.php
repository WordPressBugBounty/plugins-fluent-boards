<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;

/**
 * AI assistant permissions.
 *
 * Managing AI settings (get/save/models/test) is admin-only. Generating text
 * is available to any authenticated Fluent Boards user, since it is used from
 * the task description editor.
 */
class AiPolicy extends BasePolicy
{
    /**
     * Default guard for AI settings routes.
     */
    public function verifyRequest(Request $request)
    {
        return PermissionManager::isAdmin();
    }

    /**
     * Text generation is allowed for Fluent Boards users (admins or board members).
     *
     * Deliberately stricter than a plain logged-in check: this endpoint accepts an
     * arbitrary prompt and spends the site owner's AI credits on every call, so a
     * WP user with no board access must not be able to use it as a free AI proxy.
     */
    public function generate(Request $request)
    {
        return PermissionManager::userHasAnyBoardAccess();
    }
}
