<?php
//if accessed directly exit
if(!defined( 'ABSPATH' ))  exit;

use FluentBoards\Framework\Foundation\Application;
use FluentBoards\App\Hooks\Handlers\ActivationHandler;
use FluentBoards\App\Hooks\Handlers\DeactivationHandler;
use FluentBoards\App\Services\Intergrations\FluentCRM\Init;


return function ($file) {
    $app = new Application($file);
    require_once FLUENT_BOARDS_PLUGIN_PATH . 'app/Functions/helpers.php';

    register_activation_hook($file, function () use ($app) {
        ($app->make(ActivationHandler::class))->handle();
    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    require_once FLUENT_BOARDS_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

    add_action('plugins_loaded', function () use ($app) {
        do_action('fluent_boards_loaded', $app);
    });

    add_action('fluentcrm_loaded', function () {
        new \FluentBoards\App\Services\Intergrations\FluentCRM\Init();
    });

    add_action('fluentform/loaded', function ($app) {
        new \FluentBoards\App\Services\Intergrations\FluentFormIntegration\Bootstrap($app);
    });

    // Add this new hook for multisite support
    add_action('wp_initialize_site', ['\FluentBoards\Database\DBMigrator', 'handle_new_site']);

};
