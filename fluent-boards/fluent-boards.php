<?php

defined('ABSPATH') or exit;

/**
Plugin Name: Fluent Boards - Project Management Tool
Description: Fluent Boards is a powerful tool designed for efficient management of to-do lists, projects, and tasks with kanban board and more..
Version: 1.65
Author: WPManageNinja
Author URI: https://fluentboards.com
Plugin URI: https://fluentboards.com
License: GPLv2 or later
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: fluent-boards
Domain Path: /language
*/

if (defined('FLUENT_BOARDS')) {
    return;
}

define('FLUENT_BOARDS', 'fluent-boards');
define('FLUENT_BOARDS_PLUGIN_VERSION', '1.65');
define('FLUENT_BOARDS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FLUENT_BOARDS_DIR_FILE', __FILE__);
define('FLUENT_BOARDS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FLUENT_BOARDS_UPLOAD_DIR', 'fluent-boards');

define('FLUENT_BOARDS_DB_VERSION', '1.60'); // don't change if sync or db update not required

require __DIR__ . '/vendor/autoload.php';

call_user_func(function ($bootstrap) {
    $bootstrap(__FILE__);
}, require(__DIR__ . '/boot/app.php'));
