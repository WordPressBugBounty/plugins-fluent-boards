<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\Constant;
use FluentBoards\Database\DBSeeder;
use FluentBoards\Database\DBMigrator;
use FluentBoards\Framework\Foundation\Application;

class ActivationHandler
{
    protected $app = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function handle($network_wide = false)
    {
        DBMigrator::run($network_wide);
        DBSeeder::run();

        $this->setOnboarding();
    }

    private function setOnboarding()
    {
        $isOnboardingSet = Meta::where('key', Constant::FBS_ONBOARDING)->first();
        if(!$isOnboardingSet){
            $onboarding = new Meta();
            $onboarding->object_type = Constant::FBS_ONBOARDING;
            $onboarding->key = Constant::FBS_ONBOARDING;
            $onboarding->value = Board::count() > 0 ? 'yes' : 'no';
            $onboarding->save();
        }
    }
}