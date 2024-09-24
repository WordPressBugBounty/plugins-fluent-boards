<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\Framework\Foundation\Application;

class DeactivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle()
    {
        // ...
    }
}
