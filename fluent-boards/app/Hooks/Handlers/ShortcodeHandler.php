<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\PublicAccessService;
use FluentBoards\App\Services\TransStrings;
use FluentBoards\App\Vite;

class ShortcodeHandler
{
    public function register()
    {
        add_shortcode('fluent_board', [$this, 'renderBoard']);
        add_shortcode('fluent_board_public', [$this, 'renderPublicBoard']);
    }

    public function renderPublicBoard($atts = [])
    {
        $atts = shortcode_atts([
            'id' => 0
        ], (array)$atts, 'fluent_board_public');

        return $this->renderBoardInternal($atts, true);
    }

    public function renderBoard($atts = [])
    {
        $atts = shortcode_atts([
            'id' => 0,
        ], (array)$atts, 'fluent_board');

        return $this->renderBoardInternal($atts, false);
    }

    private function renderBoardInternal($atts, $isPublicMode)
    {
        $boardId = absint($atts['id']);
        if (!$boardId) {
            return '';
        }

        $board = Board::where('id', $boardId)->whereNull('archived_at')->first();
        if (!$board) {
            return '';
        }

        if (!$isPublicMode) {
            if (!is_user_logged_in()) {
                return $this->loginRequiredHtml();
            }

            if (!PermissionManager::userHasBoardAccess($boardId)) {
                return '';
            }
        } else {
            if (!$board->getMetaByKey('public_access_enabled')) {
                return '';
            }
        }

        $this->enqueueAssets($boardId, $isPublicMode);

        return Helper::loadView('frontend.public_board', [
            'board_id' => $boardId
        ]);
    }

    private function enqueueAssets($boardId, $isPublicMode)
    {
        $app = App::getInstance();
        $assets = $app['url.assets'];
        $slug = $app->config->get('app.slug');

        // Inject Vite HMR client in dev mode
        Vite::injectViteClient();

        $isRtl = is_rtl();
        Vite::enqueueStyle($slug . '_public_board_app', 'scss/admin.scss');

        if ($isRtl && !Vite::underDevelopment()) {
            wp_enqueue_style(
                $slug . '_public_board_app_rtl',
                $assets . 'admin/admin-rtl.css',
                [$slug . '_public_board_app'],
                FLUENT_BOARDS_PLUGIN_VERSION
            );
        }

        // Enqueue merged chunk CSS in production
        if (!Vite::underDevelopment()) {
            $styleCssPath = FLUENT_BOARDS_PLUGIN_PATH . 'assets/admin/style.css';
            if (file_exists($styleCssPath)) {
                wp_enqueue_style(
                    $slug . '_public_vite_style',
                    $assets . 'admin/style.css',
                    [$slug . '_public_board_app'],
                    FLUENT_BOARDS_PLUGIN_VERSION
                );
            }
        }

        Vite::enqueueStaticScript(
            $slug . '_public_global_admin',
            'admin/global_admin.js',
            [],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );

        Vite::enqueueScript(
            $slug . '_public_single_board',
            'admin/single_board.js',
            ['jquery'],
            FLUENT_BOARDS_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            $slug . '_public_single_board',
            'fluentAddonVars',
            $isPublicMode ? $this->getPublicAddonVars($app, $boardId, $assets, $isRtl) : $this->getPrivateAddonVars($app)
        );
    }

    private function getPublicAddonVars($app, $boardId, $assets, $isRtl)
    {
        $boardToken = PublicAccessService::generateAccessToken($boardId);
        $baseUrl = get_permalink() ?: site_url('/');
        $baseUrl = rtrim($baseUrl, '/') . '/#/';
        $guestName = __('Guest', 'fluent-boards');

        return [
            'slug'             => $app->config->get('app.slug'),
            'nonce'            => '',
            'rest'             => [
                'base_url'  => esc_url_raw(rest_url()),
                'url'       => rest_url($app->config->get('app.rest_namespace') . '/' . $app->config->get('app.rest_version')),
                'nonce'     => '',
                'namespace' => $app->config->get('app.rest_namespace'),
                'version'   => $app->config->get('app.rest_version'),
            ],
            'asset_url'        => $assets,
            'admin_url'        => '',
            'base_url'         => $baseUrl,
            'site_url'         => site_url('/'),
            'server_time'      => current_datetime()->format('Y-m-d H:i:s P'),
            'server_time_zone' => current_datetime()->format('P'),
            'utc_offset'       => current_time('timestamp') - strtotime(gmdate('Y-m-d H:i:s')),
            'trans'            => TransStrings::getStrings(),
            'is_onboarded'     => 'yes',
            'render_in'        => 'front',
            'dashboard_notices'=> [],
            'is_beta'          => false,
            'advanced_modules' => (object)[],
            'me'               => [
                'id'                         => 0,
                'full_name'                  => $guestName,
                'display_name'               => $guestName,
                'email'                      => '',
                'photo'                      => '',
                'fluent_boards_role'         => 'guest',
                'fluent_boards_capabilities' => [],
                'is_wp_admin'                => 'no',
            ],
            'start_of_week'    => intval(get_option('start_of_week', 0)),
            'time_format'      => get_option('time_format', 'g:i'),
            'priorities'       => apply_filters('fluent_boards/task_priorities', (new AdminMenuHandler())->getDefaultPriorities()),
            'wpContentCss'     => '',
            'dashiconsCss'     => site_url('/wp-includes/css/dashicons.css'),
            'fluent_crm_exists'      => false,
            'fluent_roadmap_exists'  => false,
            'has_pro'                => false,
            'is_rtl'           => $isRtl,
            'public_mode'      => true,
            'public_token'     => $boardToken,
        ];
    }

    private function getPrivateAddonVars($app)
    {
        $vars = (new AdminMenuHandler())->getAddonVars($app);
        $baseUrl = get_permalink() ?: site_url('/');
        $vars['base_url'] = rtrim($baseUrl, '/') . '/#/';
        $vars['render_in'] = 'front';
        $vars['public_mode'] = false;
        $vars['public_token'] = '';

        return $vars;
    }

    private function loginRequiredHtml()
    {
        $loginUrl = wp_login_url(get_permalink() ?: site_url('/'));
        return '<p>' . sprintf(
            // translators: %1$s is the opening login link tag, %2$s is the closing login link tag.
            esc_html__('Please %1$slog in%2$s to view this board.', 'fluent-boards'),
            '<a href="' . esc_url($loginUrl) . '">',
            '</a>'
        ) . '</p>';
    }
}
