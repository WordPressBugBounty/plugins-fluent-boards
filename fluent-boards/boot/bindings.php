<?php

/**
 * Add only the plugin specific bindings here.
 *
 * $app
 * @var $app FluentBoards\Framework\Foundation\App
 * @var $app->app FluentBoards\Framework\Foundation\ComponentBinder
 */

$app->app->singleton('FluentBoards\App\Api\Api', function ($app) {
    return new FluentBoards\App\Api\Api($app);
});

$app->app->alias('FluentBoards\App\Api\Api', 'api');