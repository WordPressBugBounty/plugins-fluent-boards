<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\TransStrings;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\Framework\Support\Collection;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Support\DateTime;

class AdminMenuHandler
{

    public function register()
    {
        add_action('admin_menu', [$this, 'add'], 11);

        add_filter('fluent_crm/core_menu_items', function ($items) {
            if (PermissionManager::userHasAnyBoardAccess()) {
                $items['fluent-boards'] = [
                    'key'       => 'fluent-boards',
                    'label'     => __('Fluent Boards', 'fluent-boards'),
                    'permalink' => admin_url('admin.php?page=fluent-boards#/')
                ];
            }
            return $items;
        });

        add_action('admin_enqueue_scripts', function () {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking admin page context for asset enqueuing, no data modification
            if (!isset($_REQUEST['page']) || $_REQUEST['page'] !== 'fluent-boards') {
                return;
            }

            $this->enqueueAssets();
        });

        add_filter('fluent_crm/sidebar_core_menu_items', function($menuItems, $permissions) {
            $settings = fluent_boards_get_pref_settings();
            if (Arr::get($settings, 'menu_settings.in_fluent_crm') === 'yes') {
                $menuItems[] = [
                    'key'        => 'fluent-boards',
                    'page_title' => __('Fluent Boards', 'fluent-boards'),
                    'menu_title' => __('Fluent Boards', 'fluent-boards'),
                    'capability' => 'manage_options', 
                    'uri'        => admin_url('admin.php?page=fluent-boards')
                ];
            }
            return $menuItems;
        }, 10, 2);
    }

    public function add()
    {
        if (!PermissionManager::userHasAnyBoardAccess()) {
            return;
        }

        $user = get_user_by('ID', get_current_user_id());

        if (current_user_can('manage_options')) {
            $capability = 'manage_options';
        } else {
            $roles = array_values((array)$user->roles);
            $capability = Arr::get($roles, 0);
        }

        $settings = fluent_boards_get_pref_settings();

        if (defined('FLUENTCRM') && \FluentCrm\App\Services\PermissionManager::currentUserPermissions() && Arr::get($settings, 'menu_settings.in_fluent_crm') === 'yes') {
            add_submenu_page(
                'fluentcrm-admin', //$parent_slug
                __('Fluent Boards', 'fluent-boards'), //$page_title
                __('Fluent Boards', 'fluent-boards'), //$menu_title
                $capability,
                'fluent-boards', //$menu_slug
                [$this, 'render'],
            );
            return;
        }

        add_menu_page(
            __('Fluent Boards', 'fluent-boards'),
            __('Fluent Boards', 'fluent-boards'),
            $capability,
            'fluent-boards',
            [$this, 'render'],
            $this->getMenuIcon(),
            Arr::get($settings, 'menu_settings.menu_position', 3)
        );

        add_submenu_page(
            'fluent-boards',
            __('Dashboard', 'fluent-boards'),
            __('Dashboard', 'fluent-boards'),
            $capability,
            'fluent-boards',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-boards',
            __('Boards', 'fluent-boards'),
            __('Boards', 'fluent-boards'),
            $capability,
            'fluent-boards#/boards',
            [$this, 'render']
        );

        do_action('fluent_boards/after_core_menu_items', $permissions = [], $isAdmin = true);

        add_submenu_page(
            'fluent-boards',
            __('Reports', 'fluent-boards'),
            __('Reports', 'fluent-boards'),
            $capability,
            'fluent-boards#/reports',
            [$this, 'render']
        );

        add_submenu_page(
            'fluent-boards',
            __('Settings', 'fluent-boards'),
            __('Settings', 'fluent-boards'),
            'manage_options',
            'fluent-boards#/settings/members-role',
            [$this, 'render']
        );
    }

    public function render()
    {
        $this->changeFooter();

        $config = App::getInstance('config');

        $name = $config->get('app.name');
        $app = App::getInstance();
        $assets = $app['url.assets'];
        $slug = $config->get('app.slug');
        $baseUrl = fluent_boards_page_url();

        do_action('fluent_boards/rendering_app');

        // For subtask sync
        UpdateHandler::maybeSubtaskGroupSync();

        App::make('view')->render('admin.menu', [
            'name'         => $name,
            'slug'         => $slug,
            'menuItems'    => $this->getMenuItems($app),
            'baseUrl'      => $baseUrl,
            'logo'         => apply_filters('fluent_boards/app_logo', $assets . 'images/logo.svg'),
            'icon'         => apply_filters('fluent_boards/app_icon', $assets . 'images/icon.svg'),
            'is_new'       => Board::count() == 0 ? 'yes' : 'no',
            'is_onboarded' => $this->getOnboardingValue()
        ]);
    }

    public function getMenuItems($app)
    {
        $config = $app->config;
        $slug = $config->get('app.slug');

        $baseUrl = fluent_boards_page_url();

        $isDiffUrl = false;

        if (is_admin()) {
            $adminUrl = admin_url('admin.php?page=fluent-boards#/');

            if ($adminUrl != $baseUrl) {
                $isDiffUrl = true;
                $baseUrl = $adminUrl;
            }
        }

        $menuItems = [
            'dashboard' => [
                'key'       => 'dashboard',
                'label'     => __('Dashboard', 'fluent-boards'),
                'permalink' => $baseUrl,
            ],
            'boards'    => [
                'key'       => 'boards',
                'label'     => __('Boards', 'fluent-boards'),
                'permalink' => $baseUrl . 'boards'
            ]
        ];
        
        $menuItems = apply_filters('fluent_boards/core_menu_items', $menuItems);

        $menuItems['reports'] = [
            'key'       => 'reports',
            'label'     => __('Reports', 'fluent-boards'),
            'permalink' => $baseUrl . 'reports'
        ];

        $isAdmin = PermissionManager::isAdmin();

        if ($isAdmin) {
            $menuItems['settings'] = [
                'key'       => 'settings',
                'label'     => __('Settings', 'fluent-boards'),
                'permalink' => $baseUrl . 'settings/members-role'
            ];
        }

        if (!defined('FLUENT_BOARDS_PRO')) {
            $menuItems['get_pro'] = [
                'key'       => 'get_pro',
                'label'     => __('Get Pro', 'fluent-boards'),
                'permalink' => 'https://fluentboards.com?utm_source=plugin&utm_medium=menu&utm_campaign=pro&utm_id=wp',
                'class'     => 'pro_link'
            ];
        }

        if ($isAdmin) {
            $menuItems['help'] = [
                'key'       => 'help',
                'label'     => __('Community', 'fluent-boards'),
                'target'    => '_blank',
                'permalink' => 'https://community.wpmanageninja.com/portal/community/fluent-boards/home'
            ];
        }

        if ($isDiffUrl) {
            $menuItems['front'] = [
                'key'       => 'front',
                'label'     => __('Frontend Portal', 'fluent-boards'),
                'target'    => '_blank',
                'permalink' => fluent_boards_page_url()
            ];
        }

        $menuItems = apply_filters('fluent_boards/menu_items', $menuItems);

        return array_values($menuItems);
    }

    public function enqueueAssets()
    {
        if (!PermissionManager::hasAppAccess()) {
            return;
        }

        add_action('wp_print_scripts', function () {

            $isSkip = apply_filters('fluent_boards/skip_no_conflict', false);

            if ($isSkip) {
                return;
            }

            global $wp_scripts;
            if (!$wp_scripts) {
                return;
            }

            $approvedSlugs = apply_filters('fluent_boards/asset_listed_slugs', [
                '\/fluent-crm\/'
            ]);

            $approvedSlugs[] = '\/fluent-boards\/';

            $approvedSlugs = array_unique($approvedSlugs);

            $approvedSlugs = implode('|', $approvedSlugs);

            $pluginUrl = plugins_url();

            $pluginUrl = str_replace(['http:', 'https:'], '', $pluginUrl);

            foreach ($wp_scripts->queue as $script) {
                if (empty($wp_scripts->registered[$script]) || empty($wp_scripts->registered[$script]->src)) {
                    continue;
                }

                $src = $wp_scripts->registered[$script]->src;
                $isMatched = (strpos($src, $pluginUrl) !== false) && !preg_match('/' . $approvedSlugs . '/', $src);
                if (!$isMatched) {
                    continue;
                }
                wp_dequeue_script($wp_scripts->registered[$script]->handle);
            }
        });

        if (function_exists('wp_enqueue_media')) {
            // Editor default styles.
            add_filter('user_can_richedit', '__return_true');
            if (is_admin()) {
                wp_tinymce_inline_scripts();
            }
            wp_enqueue_editor();
            wp_enqueue_script('thickbox');
            wp_enqueue_script('editor');
        }
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }

        $app = App::getInstance();

        $assets = $app['url.assets'];

        $slug = $app->config->get('app.slug');

        $isRtl = is_rtl();
        $adminAppCss = 'admin/admin.css';
        if($isRtl) {
            $adminAppCss = 'admin/admin-rtl.css';
        }
        wp_enqueue_style(
            $slug . '_admin_app',
            $assets . $adminAppCss,
            [],
            FLUENT_BOARDS_PLUGIN_VERSION
        );

        do_action('fluent-boards_loading_app');

        wp_enqueue_script(
            $slug . '_admin_app',
            $assets . 'admin/app.min.js',
            ['jquery'],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );

        wp_enqueue_script(
            $slug . '_global_admin',
            $assets . 'admin/global_admin.js',
            [],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );
        /*
        * This script only for resolve the conflict of lodash and underscore js
        * Resolved the issue of media uploader specially for image upload
        */
        wp_add_inline_script($slug . '_global_admin', $this->getInlineScript(), 'after');

        wp_localize_script($slug . '_admin_app', 'fluentAddonVars', $this->getAddonVars($app));

        do_action('fluent_boards/after_enqueue_assets', $app);
    }

    public function getAddonVars($app)
    {
        $currentUser = get_user_by('ID', get_current_user_id());
        $assets = $app['url.assets'];
        $roleAndPermissions = $this->getRoleAndPermissions($currentUser->ID);
        $onboardingValue = $this->getOnboardingValue();

        return apply_filters('fluent_boards/app_vars', [
            'slug'                            => $slug = $app->config->get('app.slug'),
            'nonce'                           => wp_create_nonce($slug),
            'rest'                            => $this->getRestInfo($app),
            'fluent_boards_file_upload_nonce' => wp_create_nonce('fluent_boards_file_upload_nonce'),
            'ajaxurl'                         => admin_url('admin-ajax.php'),
            'file_upload_limit'               => $this->fileUploadLimit(),
            'brand_logo'                      => $this->getMenuIcon(),
            'asset_url'                       => $assets,
            'admin_url'                       => admin_url('admin.php'),
            'fluent_crm_exists'               => !defined('FLUENTCRM') ? false : true,
            'fluent_roadmap_exists'           => !defined('FLUENT_ROADMAP') ? false : true,
            'has_pro'                         => !!defined('FLUENT_BOARDS_PRO_VERSION'),
            'me'                              => [
                'id'                         => $currentUser->ID,
                'full_name'                  => trim($currentUser->first_name . ' ' . $currentUser->last_name),
                'display_name'               => $currentUser->display_name,
                'email'                      => $currentUser->user_email,
                'photo'                      => fluent_boards_user_avatar($currentUser->user_email, $currentUser->display_name),
                'fluent_boards_role'         => $roleAndPermissions['role'],
                'fluent_boards_capabilities' => $roleAndPermissions['permissions'],
                'is_wp_admin'                => user_can($currentUser->ID, 'manage_options') ? 'yes' : 'no'
            ],
            'base_url'                        => fluent_boards_page_url(),
            'site_url'                        => site_url('/'),
            // 'server_time'                     => (new DateTime('now'))->format('Y-m-d H:i:s P'), // Server's default timezone
            'server_time'                       => (new DateTime('now', wp_timezone()))->format('Y-m-d H:i:s P'), // wordpress site time 
            'server_time_zone'                => (new DateTime('now', wp_timezone()))->format( 'P'),
            'utc_offset'                      => current_time('timestamp') - strtotime(gmdate('Y-m-d H:i:s')),
            'trans'                           => TransStrings::getStrings(),
            'is_new'                          => Board::count() == 0 ? 'yes' : 'no',
            'is_onboarded'                    => $onboardingValue,
            'render_in'                       => is_admin() ? 'admin' : 'front',
            'dashboard_notices'               => apply_filters('fluent_boards/dashboard_notices', []),
            'is_beta'                         => defined('FLUENT_BOARDS_PRO_VERSION') && !defined('FLUENT_BOARDS_PRO_LIVE'),
            'advanced_modules'                => fluent_boards_get_pref_settings(),
            'crm_base_url'                    => defined('FLUENTCRM') ? fluentcrm_menu_url_base() : '',
            'start_of_week'                   => intval(get_option('start_of_week', 0)),
            'time_format'                     => get_option('time_format', 'g:i'),
            'priorities'                      => apply_filters('fluent_boards/task_priorities', $this->getDefaultPriorities()),
            'wpContentCss' => add_query_arg(
                'ver', get_bloginfo('version'),
                site_url('/wp-includes/js/tinymce/skins/wordpress/wp-content.css')
            ),
            'dashiconsCss' => add_query_arg(
                'ver', get_bloginfo('version'),
                site_url('/wp-includes/css/dashicons.css')
            ),
            'is_rtl' => is_rtl(),
            'task_tabs' => apply_filters('fluent_boards/task_tabs', $this->getDefaultTaskTabs()),
            'board_menu_items' => BoardMenuHandler::getMenuItems(),
            'reminder_types' => defined('FLUENT_BOARDS_PRO') ? Helper::taskReminderTypes() : [],
        ]);
    }

    private function getOnboardingValue()
    {
        $onboarding = Meta::where('key', Constant::FBS_ONBOARDING)->first();
        if ($onboarding) {
            return $onboarding->value;
        }
//        if (Board::first()) {
//            return 'yes';
//        }
//
//        return 'no';
    }

    private function getDefaultTaskTabs()
    {
        return [
            'all' => [
                'key' => 'all',
                'label' => __('All', 'fluent-boards'),
                'component' => 'task-all-comments-and-activities'
            ],
            'comment' => [
                'key' => 'comment',
                'label' => __('Comments', 'fluent-boards'),
                'component' => 'task-comments'
            ],
            'activity' => [
                'key' => 'activity',
                'label' => __('Activities', 'fluent-boards'),
                'component' => 'task-activities'
            ]
        ];
    }
    public function getDefaultPriorities()
    {
        return [
            'low'    => __('Low', 'fluent-boards'),
            'medium' => __('Medium', 'fluent-boards'),
            'high'   => __('High', 'fluent-boards')
        ];
    }

    /*
     * TODO: This method should be moved to PermissionManager and Helper . Task for Masiur.
     */
    protected function getRoleAndPermissions($userId): array
    {
        $role = 'fluent_boards_admin';
        $boardsWithPermissions = Relation::query()->where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->get();
        $boardUserCollection = Collection::make($boardsWithPermissions);

        if (PermissionManager::isFluentBoardsAdmin($userId)) {
            $role = 'fluent_boards_admin';
        } elseif (user_can($userId, 'manage_options')) {
            $role = 'wordpress_admin';
        } else {
            $role = 'member';
        }

        $permissions = [];
        foreach ($boardUserCollection as $boardWithPermission) {
            $permissions[] = [
                'board_id'    => $boardWithPermission->object_id,
                'role'        => $this->boardUserRole($boardWithPermission),
                'preferences' => $boardWithPermission->preferences,
                'permissions' => [],
            ];
        }

        return [
            'role'        => $role,
            'permissions' => $permissions,
        ];
    }

    protected function boardUserRole($boardWithPermission)
    {
        return $boardWithPermission['settings']['is_admin'] ? 'board_admin' : (Arr::has($boardWithPermission, 'settings.is_viewer_only') && $boardWithPermission['settings']['is_viewer_only'] ? 'board_viewer' : 'board_member');
    }

    protected function getRestInfo($app)
    {
        $ns = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver,
        ];
    }

    protected function getMenuIcon()
    {
        /*
         * Left Sidebar Menu Icon
         */
        return 'data:image/svg+xml;base64,' . base64_encode('<svg width="256" height="256" viewBox="0 0 256 256" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M0 25.6C0 11.4615 11.4615 0 25.6 0H230.4C244.538 0 256 11.4615 256 25.6V230.4C256 244.538 244.538 256 230.4 256H25.6C11.4615 256 0 244.538 0 230.4V25.6ZM140.8 89.6C140.8 75.4615 152.262 64 166.4 64H186.88C189.708 64 192 66.2923 192 69.12V166.4C192 180.538 180.538 192 166.4 192H145.92C143.092 192 140.8 189.708 140.8 186.88V89.6ZM89.6 64C75.4615 64 64 75.4615 64 89.5999V148.48C64 151.308 66.2923 153.6 69.12 153.6H89.6C103.739 153.6 115.2 142.138 115.2 128V69.12C115.2 66.2923 112.908 64 110.08 64H89.6Z" fill="white"/></svg>');
    }

    public function changeFooter()
    {
        add_filter('admin_footer_text', function ($content) {
            $url = '#';
            return '';

            // translators: %s is the URL for FluentBoards link
            return sprintf(wp_kses(__('Thank you for using <a href="%s">FluentBoards</a>', 'fluent-boards'), ['a' => ['href' => []]]), esc_url($url)) . '<span title="based on your WP timezone settings" style="margin-left: 10px;" data-timestamp="' . current_time('timestamp') . '" id="fc_server_timestamp"></span>';
        });

        add_filter('update_footer', function ($text) {
            return FLUENT_BOARDS_PLUGIN_VERSION;
        });
    }

    /**
     * Retrieves the maximum upload limit based on PHP and WordPress configurations.
     *
     * This method calculates and returns the minimum value among the following:
     * 1. The PHP 'upload_max_filesize' configuration.
     * 2. The PHP 'post_max_size' configuration.
     * 3. The WordPress maximum upload size limit using the 'wp_max_upload_size' function.
     *
     * @return int The minimum of the mentioned upload size limits in bytes.
     */
    public function fileUploadLimit()
    {
        // Calculate the minimum of 'upload_max_filesize', 'post_max_size', and WordPress maximum upload size.
        return min(
            wp_convert_hr_to_bytes(ini_get('upload_max_filesize')),
            wp_convert_hr_to_bytes(ini_get('post_max_size')),
            wp_max_upload_size()
        );
    }

    public function getInlineScript()
    {
        return "
        function isLodash () {
    
            let isLodash = false;

            // If _ is defined and the function _.forEach exists then we know underscore OR lodash are in place
            if ( 'undefined' != typeof( _ ) && 'function' == typeof( _.forEach ) ) {

                // A small sample of some of the functions that exist in lodash but not underscore
                const funcs = [ 'get', 'set', 'at', 'cloneDeep' ];

                // Simplest if assume exists to start
                isLodash  = true;

                funcs.forEach( function ( func ) {
                    // If just one of the functions do not exist, then not lodash
                    isLodash = ( 'function' != typeof( _[ func ] ) ) ? false : isLodash;
                } );
            }

            if ( isLodash ) {
                // We know that lodash is loaded in the _ variable
                return true;
            } else {
                // We know that lodash is NOT loaded
                return false;
            }
        };

        if ( isLodash() ) {
            _.noConflict();
        }
        ";
    }
    public function singleBoardRender()
    {
        $config = App::getInstance('config');

        $slug = $config->get('app.slug');



        App::make('view')->render('admin.single_board_shortcode', [
            'slug'         => $slug,
        ]);
    }

}
