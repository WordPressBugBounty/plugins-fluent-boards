<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Foundation\Policy;

class TaskPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentBoards\Framework\Request\Request $request
     * @return bool
     */
    public function verifyRequest(Request $request)
    {
        return true;
        // we have to check like logged-in user has the permission to view the tasks,
        // but we are not using this policy
    }
}
