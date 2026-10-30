<?php
//if accessed directly exit
if(!defined( 'ABSPATH' ))  exit;

use FluentBoards\App\Hooks\Handlers\RestLocaleHandler;

/**
 * All registered filter's handlers should be in app\Hooks\Handlers,
 * addFilter is similar to add_filter and addCustomFilter is just a
 * wrapper over add_filter which will add a prefix to the hook name
 * using the plugin slug to make it unique in all WordPress plugins,
 * ex: $app->addCustomFilter('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_filter('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentBoards\Framework\Foundation\Application
 */
$app->addCustomFilter('ajax_options_task_assignees', 'TaskHandler@searchAssignees', 10, 3);
$app->addCustomFilter('ajax_options_non_board_wordpress_users', 'TaskHandler@searchNonBoardWordpressUsers', 10, 3);
$app->addCustomFilter('ajax_options_crm_contacts', 'TaskHandler@searchContact', 10, 3);
$app->addCustomFilter('task_attachment_file_upload', 'FileHandler@handleUpload', 10, 1);
$app->addCustomFilter('wp_editor_media_file_upload', 'FileHandler@handleMediaFileUpload', 10, 1 );
$app->addCustomFilter('board_find', 'BoardHandler@attachFolderToBoard', 10, 1);

$restLocaleHandler = new RestLocaleHandler(
    $app->config->get('app.rest_namespace'),
    $app->config->get('app.rest_version')
);
$app->addFilter('rest_request_before_callbacks', [$restLocaleHandler, 'switchToUserLocale'], 1, 3);
$app->addFilter('rest_request_after_callbacks', [$restLocaleHandler, 'restoreLocale'], PHP_INT_MAX, 3);

// Public board and task share responses: never cached or indexed, so turning access off takes effect at once.
$app->addFilter('rest_post_dispatch', function ($response, $server, $request) {
    $route = (string)$request->get_route();
    if ($response instanceof \WP_REST_Response && preg_match('#^/fluent-boards/v2/(public/boards|shared-task)(/|$)#', $route)) {
        foreach (wp_get_nocache_headers() as $name => $value) {
            $response->header($name, $value);
        }
        $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->header('X-Robots-Tag', 'noindex, nofollow');
        $response->header('Referrer-Policy', 'no-referrer');
    }
    return $response;
}, 10, 3);
