<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Services\DiscordService;
use FluentBoards\App\Services\SlackService;
use FluentBoards\Framework\Foundation\Application;
use FluentBoards\App\Services\TaskEmailDigest;

class DeactivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle()
    {
        wp_clear_scheduled_hook(TaskEmailDigest::RECOVERY_SWEEP);
        wp_clear_scheduled_hook(SlackService::RECONCILE_HOOK);
        wp_clear_scheduled_hook(DiscordService::RECONCILE_HOOK);
    }
}
