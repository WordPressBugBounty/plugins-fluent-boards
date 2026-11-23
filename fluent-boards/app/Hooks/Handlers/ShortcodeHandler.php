<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\TransStrings;
use FluentBoards\App\Vite;

class ShortcodeHandler
{
    public function register()
    {
        add_shortcode('fluent_board', [$this, 'renderBoard']);
    }

    public function renderBoard($atts = [])
    {
        $atts = shortcode_atts([
            'id' => 0,
        ], (array)$atts, 'fluent_board');

        $boardId = absint($atts['id']);
        if (!$boardId) {
            return '';
        }

        // Check login before the lookup so visitors can't probe which board ids exist.
        if (!is_user_logged_in()) {
            return $this->loginRequiredHtml();
        }

        if (!PermissionManager::userHasPermission($boardId)) {
            return '';
        }

        $board = Board::where('id', $boardId)->whereNull('archived_at')->first();
        if (!$board) {
            return '';
        }

        $this->enqueueAssets($boardId);

        return Helper::loadView('frontend.public_board', [
            'board_id' => $boardId
        ]);
    }

    /**
     * Assets for the read-only public apps (board view and task share page): the shared
     * stylesheet plus the standalone public bundle. The admin SPA is never loaded for visitors.
     */
    public function enqueuePublicAssets()
    {
        $app = App::getInstance();
        $slug = $app->config->get('app.slug');
        $handle = $slug . '_public_board_view';

        if (wp_script_is($handle, 'enqueued')) {
            return;
        }

        // Inject Vite HMR client in dev mode
        Vite::injectViteClient();

        $isRtl = $this->enqueueStyles($app, '_public_board_app');

        Vite::enqueueScript($handle, 'admin/public_board.js', [], FLUENT_BOARDS_PLUGIN_VERSION, true);

        // Only what the public app needs. Board id and token live on each mount element,
        // and exposure settings come from the API so cached pages pick up setting changes.
        wp_localize_script($handle, 'fluentAddonVars', [
            'rest_url'         => rest_url($app->config->get('app.rest_namespace') . '/' . $app->config->get('app.rest_version')),
            'trans'            => TransStrings::getStrings(),
            'is_rtl'           => $isRtl,
            'date_format'      => get_option('date_format', 'F j, Y'),
            'locale'           => get_locale(),
            'time_format'      => get_option('time_format', 'g:i'),
            'server_time_zone' => current_datetime()->format('P'),
            'priorities'       => apply_filters('fluent_boards/task_priorities', (new AdminMenuHandler())->getDefaultPriorities()),
        ]);
    }

    private function enqueueAssets($boardId)
    {
        $app = App::getInstance();
        $slug = $app->config->get('app.slug');

        // Inject Vite HMR client in dev mode
        Vite::injectViteClient();

        $this->enqueueStyles($app, '_public_board_app');

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
            $this->getPrivateAddonVars($app)
        );
    }

    /**
     * Shared board stylesheet (+ merged chunk CSS in production, + RTL overrides). Returns whether RTL is active.
     */
    private function enqueueStyles($app, $handleSuffix)
    {
        $assets = $app['url.assets'];
        $slug = $app->config->get('app.slug');
        $styleHandle = $slug . $handleSuffix;

        $isRtl = fluent_boards_is_rtl();
        Vite::enqueueStyle($styleHandle, 'scss/admin.scss');

        // Enqueue merged chunk CSS in production
        if (!Vite::underDevelopment()) {
            $styleCssPath = FLUENT_BOARDS_PLUGIN_PATH . 'assets/admin/style.css';
            if (file_exists($styleCssPath)) {
                wp_enqueue_style(
                    $slug . '_public_vite_style',
                    $assets . 'admin/style.css',
                    [$styleHandle],
                    FLUENT_BOARDS_PLUGIN_VERSION
                );
            }
        }

        // RTL overrides must load after style.css so rules appended to the
        // element-plus layer win by source order, not only by specificity.
        if ($isRtl) {
            $rtlDeps = [$styleHandle];
            if (wp_style_is($slug . '_public_vite_style', 'enqueued')) {
                $rtlDeps[] = $slug . '_public_vite_style';
            }
            Vite::enqueueStyle(
                $styleHandle . '_rtl',
                'scss/admin_rtl.scss',
                $rtlDeps,
                FLUENT_BOARDS_PLUGIN_VERSION
            );
        }

        return $isRtl;
    }

    private function getPrivateAddonVars($app)
    {
        $vars = (new AdminMenuHandler())->getAddonVars($app);
        $baseUrl = get_permalink() ?: site_url('/');
        $vars['base_url'] = rtrim($baseUrl, '/') . '/#/';
        $vars['render_in'] = 'front';

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
