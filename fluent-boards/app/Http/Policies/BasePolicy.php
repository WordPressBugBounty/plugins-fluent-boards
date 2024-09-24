<?php

namespace FluentBoards\App\Http\Policies;

use FluentBoards\Framework\Foundation\Policy;
use FluentBoards\Framework\Http\Request\Request;

/**
 *  BasePolicy - REST API Permission Policy
 *
 *
 * @version 1.0.0
 */
class BasePolicy extends Policy
{
    /**
     * @param  Request  $request
     * @return true
     */
    public function verifyRequest(Request $request)
    {
        return true;
    }
}
