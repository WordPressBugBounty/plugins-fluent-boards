<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM;

use FluentBoards\App\App;
use FluentBoards\App\Hooks\Handlers\FluentCrmIntegration;
use FluentBoards\App\Services\Intergrations\FluentCRM\Automations\ContactAddedBoardTrigger;
use FluentBoards\App\Services\Intergrations\FluentCRM\Automations\ContactAddedTaskTrigger;
use FluentBoards\App\Services\Intergrations\FluentCRM\Automations\StageChangedTrigger;
use FluentBoards\App\Services\Intergrations\FluentCRM\Automations\TaskCreateAction;
use FluentBoards\App\Services\TransStrings;

class Init
{
    public function __construct()
    {
        $this->registerToContactSection();

        (new DeepIntegration())->init();
        $this->registerAutomationFunnels();


        add_filter('fluent_crm_asset_listed_slugs', function ($lists) {
            $lists[] = 'fluent-boards';
            return $lists;
        });

    }

    public function registerToContactSection()
    {
//        (new FluentCrmIntegration())->registerCustomSection();
        add_action( 'fluent_crm/global_appjs_loaded', function () {
            $app = App::getInstance();

            $assets = $app['url.assets'];

            $slug = $app->config->get('app.slug');
            wp_enqueue_script( $slug . '_in_crm', FLUENT_BOARDS_PLUGIN_URL . 'assets/crm-contact-app.js', [], FLUENT_BOARDS_PLUGIN_VERSION, true);
            $isRtl = is_rtl();
            $crmContactCss = 'assets/admin/crm-contact-app.css';
            if($isRtl) {
                $crmContactCss = 'assets/admin/crm-contact-app-rtl.css';
            }
            wp_enqueue_style($slug . '_in_crm', FLUENT_BOARDS_PLUGIN_URL . $crmContactCss, [], FLUENT_BOARDS_PLUGIN_VERSION);
            wp_localize_script($slug . '_in_crm', 'fluentAddonVars', [
                'slug'                            => $slug = $app->config->get('app.slug'),
                'nonce'                           => wp_create_nonce($slug),
                'rest'                            => $this->getRestInfo($app),
                'ajaxurl'                         => admin_url('admin-ajax.php'),
                'asset_url'                       => $assets,
                'trans'                           => TransStrings::getStrings(),
                'base_url'                        => fluent_boards_page_url(),
                'admin_url'                       => admin_url('admin.php'),
                'render_in'                       => is_admin() ? 'admin' : 'front',
                'advanced_modules'                => fluent_boards_get_pref_settings(),
            ]);
        });
    }

    public function registerAutomationFunnels()
    {
//        new ContactAddedBoardTrigger();
        new ContactAddedTaskTrigger();
        new StageChangedTrigger();

        new TaskCreateAction();
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
}
