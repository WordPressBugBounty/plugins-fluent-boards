<?php
//if accessed directly exit
if(!defined( 'ABSPATH' ))  exit;

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
