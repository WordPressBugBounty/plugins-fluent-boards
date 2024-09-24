<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;

class WebhookPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        return PermissionManager::isAdmin();
    }
}
