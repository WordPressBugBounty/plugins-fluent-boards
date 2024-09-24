<?php

use FluentBoards\App\App;

// $app is available

if (!function_exists('fluentBoards')) {
    function fluentBoards($module = null)
    {
        return App::getInstance($module);
    }
}

if (!function_exists('FluentBoardsApi')) {
    function FluentBoardsApi($key = null)
    {
        $api = fluentBoards('api');
        return is_null($key) ? $api : $api->{$key};
    }
}

if (!function_exists('fluent_boards_user_avatar')) {
    function fluent_boards_user_avatar($email, $name = '')
    {
        $hash = md5(strtolower(trim($email)));
        /**
         * Gravatar URL by Email
         *
         * @return string $gravatar url of the gravatar image
         */
        $fallback = '';
        if ($name) {
            $fallback = '&d=https%3A%2F%2Fui-avatars.com%2Fapi%2F' . urlencode($name) . '/128';
        }

        return apply_filters('fluent_boards/get_avatar',
            "https://www.gravatar.com/avatar/{$hash}?s=128" . $fallback,
            $email
        );
    }
}

if (!function_exists('fluent_boards_mix')) {
    function fluent_boards_mix($path, $manifestDirectory = '')
    {
        return fluentBoards('url.assets') . ltrim($path, '/');
    }
}

if (!function_exists('FluentBoardsAssetUrl')) {
    function FluentBoardsAssetUrl($path = null)
    {
        $assetUrl = fluentBoards('url.assets');

        return $path ? ($assetUrl . $path) : $assetUrl;
    }
}

if (!function_exists('fluent_boards_page_url')) {
    function fluent_boards_page_url(): ?string
    {
        return apply_filters('fluent_boards/app_url', admin_url('admin.php?page=fluent-boards#/'));
    }
}

function fluent_boards_get_pref_settings($cached = true)
{
    static $pref = null;

    if ($cached && $pref) {
        return $pref;
    }

    $settings = [
        'timeTracking'  => [
            'enabled'         => 'no',
            'all_boards'      => 'yes',
            'selected_boards' => []
        ],
        'frontend'      => [
            'enabled'     => 'no',
            'slug'        => 'projects',
            'render_type' => 'standalone',
            'page_id'     => ''
        ],
        'menu_settings' => [
            'in_fluent_crm' => 'no',
            'menu_position' => 3
        ],
        'recurring_task'  => [
            'enabled'         => 'no',
            'all_boards'      => 'yes',
            'selected_boards' => []
        ],
    ];

    $storedSettings = get_option('fluent_boards_modules', []);
    if ($storedSettings && is_array($storedSettings)) {
        $settings = wp_parse_args($storedSettings, $settings);
    }

    $pref = $settings;

    return $settings;
}

if (!function_exists('fluent_boards_site_logo')) {
    function fluent_boards_site_logo()
    {
        $logo_url = '';
        if (function_exists('get_custom_logo') && has_custom_logo()) {
            $custom_logo_id = get_theme_mod('custom_logo');
            $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo) {
                $logo_url = $logo[0];
            }
        }
        return apply_filters('fluent_boards/site_logo', $logo_url);
    }
}

function fluent_boards_get_option($key, $default = null)
{
    $exit = \FluentBoards\App\Models\Meta::where('object_type', 'option')
        ->where('key', $key)
        ->first();

    if ($exit) {
        return $exit->value;
    }

    return $default;
}

function fluent_boards_update_option($key, $value)
{
    $exit = \FluentBoards\App\Models\Meta::where('object_type', 'option')
        ->where('key', $key)
        ->first();

    if ($exit) {
        $exit->value = $value;
        $exit->save();
    } else {
        $exit = \FluentBoards\App\Models\Meta::create([
            'object_type' => 'option',
            'key'         => $key,
            'value'       => $value
        ]);
    }

    return $exit;
}


function fluentBoardsDb()
{
    return fluentBoards('db');
}


function fbsGetFeaturesConfig()
{
    $features = fluent_boards_get_option('_fbs_features', []);

    $defaults = [
        'cloud_storage'       => 'no',
        // more advanced features/config can be added here
    ];

    if (defined('FLUENT_BOARDS_CLOUD_STORAGE') && FLUENT_BOARDS_CLOUD_STORAGE) {
        $features['cloud_storage'] = 'yes';
    }

    return wp_parse_args($features, $defaults);
}

function fbsGetOption($key, $default = null)
{
    return fluent_boards_get_option($key, $default);
}

function fbsUpdateOption($key, $value)
{
    return fluent_boards_update_option($key, $value);
}