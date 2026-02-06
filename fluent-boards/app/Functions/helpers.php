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
        $user = get_user_by('email', $email);

        if($user) {
            $has_custom_avatar = get_user_meta($user->ID, 'wp_user_avatar', true);
            if ($has_custom_avatar) {
                $custom_avatar_attachment = get_post($has_custom_avatar);
                if ($custom_avatar_attachment) {
                    $avatar_url = wp_get_attachment_url($has_custom_avatar);
                    return apply_filters('fluent_boards/get_avatar', $avatar_url, $email);
                }
            }
            $avatar_url = get_avatar_url($user->ID, array('size' => 128));
            return apply_filters('fluent_boards/get_avatar', $avatar_url, $email);
        }

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

/**
 * Retrieves features configuration.
 *
 * @deprecated 1.90 Use fluent_boards_get_features_config() instead.
 */
function fbsGetFeaturesConfig()
{
    _deprecated_function(__FUNCTION__, '1.90', 'fluent_boards_get_features_config');
    return fluent_boards_get_features_config();
}

function fluent_boards_get_features_config()
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

/**
 * Updates an option value.
 *
 * @deprecated 1.90 Use fluent_boards_get_option() instead.
 */
function fbsGetOption($key, $default = null)
{
    _deprecated_function(__FUNCTION__, '1.90', 'fluent_boards_get_option');
    return fluent_boards_get_option($key, $default);
}

/**
 * Updates an option value.
 *
 * @deprecated 1.90 Use fluent_boards_update_option() instead.
 */
function fbsUpdateOption($key, $value)
{
    _deprecated_function(__FUNCTION__, '1.90', 'fluent_boards_update_option');
    return fluent_boards_update_option($key, $value);
}


function fluentboardsCsvMimes()
{
    /**
     * Board Import CSV Mimes
     *
     * @return array array of CSV mimes
     */
    return apply_filters('fluent_boards_csv_mimes', [
        'text/csv',
        'text/plain',
        'application/csv',
        'text/comma-separated-values',
        'application/excel',
        'application/vnd.ms-excel',
        'application/vnd.msexcel',
        'text/anytext',
        'application/octet-stream',
        'application/txt'
    ]);
}

function fluent_boards_string_to_bool($value)
{
    // Normalize known string values to booleans
    $trueFalseMap = [
        'yes'   => true,
        'no'    => false,
        'true'  => true,
        'false' => false,
        '1'     => true,
        '0'     => false,
        true    => true,
        false   => false,
    ];

    // Allow modification of the map
    $trueFalseMap = apply_filters('fluent_boards/true_false_convert', $trueFalseMap);

    // Handle array input recursively
    if (is_array($value)) {
        return array_map('fluent_boards_string_to_bool', $value);
    }

    // Normalize string input
    $key = is_string($value) ? strtolower(trim($value)) : $value;

    // Match normalized value against map
    if (array_key_exists($key, $trueFalseMap)) {
        return $trueFalseMap[$key];
    }

    // Fallback: return original value if not recognized
    return $value;
}
