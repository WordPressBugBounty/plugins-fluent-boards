<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\App\Http\Policies\BasePolicy;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;

/** All profile operations act on the current user, never a caller-supplied user ID. */
class DiscordPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        return get_current_user_id() > 0 && PermissionManager::isFluentBoardsUser(get_current_user_id());
    }
}
