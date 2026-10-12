<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Modules\MCP\MCPInit;
use FluentBoards\App\Services\MCPAccessService;
use FluentBoards\Framework\Http\Request\Request;

/**
 * Settings endpoints for connecting Fluent Boards MCP tools to AI agents.
 */
class MCPSettingsController extends Controller
{
    const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';
    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    public function status()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $adapterInstalled = $this->isAdapterPresent();
        $toolkitInstalled = $this->isToolkitPresent();
        $adapterRuntimeAvailable = $this->isAdapterRuntimeAvailable();
        $standaloneActive = is_plugin_active(self::ADAPTER_PLUGIN_FILE) && $adapterRuntimeAvailable;
        $toolkitActive = $this->isToolkitLoaded();
        $toolkitAdapterActive = $toolkitActive && $this->isToolkitAdapterAvailable();
        $adapterActive = $standaloneActive || $toolkitAdapterActive;
        $adapterProvider = $standaloneActive ? 'plugin' : ($toolkitAdapterActive ? 'toolkit' : '');
        $abilitiesAvailable = function_exists('wp_register_ability');
        $canAutoInstall = $this->canAutoInstallFluentKit();
        $currentUser = wp_get_current_user();

        return $this->sendSuccess([
            'adapter_installed'              => $adapterInstalled || $toolkitInstalled,
            'adapter_active'                 => $adapterActive,
            'adapter_provider'               => $adapterProvider,
            'standalone_adapter_installed'   => $adapterInstalled,
            'toolkit_installed'              => $toolkitInstalled,
            'toolkit_active'                 => $toolkitActive,
            'toolkit_adapter_available'      => $toolkitAdapterActive,
            'adapter_runtime_available'      => $adapterRuntimeAvailable,
            'adapter_version'                => $this->detectAdapterVersion(),
            'toolkit_version'                => $this->detectToolkitVersion(),
            'abilities_api_loaded'           => $abilitiesAvailable,
            'endpoint_url'                   => MCPInit::getEndpointUrl(),
            'tools_count'                    => $abilitiesAvailable ? MCPInit::getToolsCount() : 0,
            'mcp_enabled'                    => fluent_boards_get_option('mcp_enabled', 'yes') === 'yes',
            'mcp_member_access'              => MCPAccessService::isMemberAccessEnabled(),
            'plugins_url'                    => admin_url('plugins.php'),
            'can_auto_install_adapter'       => $canAutoInstall,
            'toolkit_download_url'           => 'https://github.com/WPManageNinja/fluent-toolkit',
            'current_user_login'             => $currentUser ? $currentUser->user_login : '',
            'is_local_dev'                   => MCPAccessService::isLocalDev(),
        ]);
    }

    public function toggle(Request $request)
    {
        $value = $request->get('mcp_enabled');
        $enabled = is_string($value) ? ($value === 'yes' || $value === 'true' || $value === '1') : (bool) $value;

        fluent_boards_update_option('mcp_enabled', $enabled ? 'yes' : 'no');

        return $this->sendSuccess([
            'ok'          => true,
            'mcp_enabled' => $enabled,
            'message'     => $enabled
                ? __('MCP tools enabled. New requests will see the Fluent Boards abilities.', 'fluent-boards')
                : __('MCP tools disabled. The adapter will no longer report Fluent Boards abilities.', 'fluent-boards'),
        ]);
    }

    /**
     * All-or-none switch: when on, every board member can use MCP and create keys from Account.
     * Turning it off blocks members immediately; their keys are kept but stop working.
     */
    public function toggleMemberAccess(Request $request)
    {
        $value = $request->get('mcp_member_access');
        $enabled = is_string($value) ? in_array($value, ['yes', 'true', '1'], true) : (bool) $value;

        fluent_boards_update_option('mcp_member_access', $enabled ? 'yes' : 'no');

        return $this->sendSuccess([
            'mcp_member_access' => $enabled,
            'message'           => $enabled
                ? __('Board members can now create their own MCP connections.', 'fluent-boards')
                : __('MCP is now limited to administrators. Member connections are blocked.', 'fluent-boards'),
        ]);
    }

    public function installAdapter()
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry! you do not have permission to install plugins', 'fluent-boards'),
            ]);
        }

        $canAutoInstall = $this->canAutoInstallFluentKit();
        if (!$canAutoInstall) {
            return $this->sendError([
                'message'              => __('Please install FluentHub from GitHub, then reload this page to connect Fluent Boards with AI agents.', 'fluent-boards'),
                'toolkit_download_url' => 'https://github.com/WPManageNinja/fluent-toolkit',
            ]);
        }

        $this->autoInstallFluentKit();

        wp_clean_plugins_cache();

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $toolkitInstalled = $this->isToolkitPresent();
        $toolkitActive = $this->isToolkitLoaded();
        $adapterRuntimeAvailable = $this->isAdapterRuntimeAvailable();
        $toolkitAdapterAvailable = $toolkitActive && $this->isToolkitAdapterAvailable();
        $isInstalled = $this->isAdapterPresent() || $toolkitInstalled;
        $isActive = (is_plugin_active(self::ADAPTER_PLUGIN_FILE) && $adapterRuntimeAvailable) || $toolkitAdapterAvailable;

        if ($isInstalled && $isActive) {
            $message = __('FluentHub installed and activated. Reload the page to register Fluent Boards MCP tools.', 'fluent-boards');
        } elseif ($toolkitInstalled && $toolkitActive) {
            $message = __('FluentHub is installed and active, but this version does not include the bundled MCP adapter yet. Please update FluentHub when the MCP-ready build is available, then reload this page.', 'fluent-boards');
        } elseif ($toolkitInstalled) {
            $message = __('FluentHub is installed but could not be activated automatically. Please activate FluentHub from the Plugins page, then reload this page.', 'fluent-boards');
        } else {
            $message = __('Could not install FluentHub automatically. Please install FluentHub manually, then reload this page.', 'fluent-boards');
        }

        return $this->sendSuccess([
            'is_installed'              => $isInstalled,
            'adapter_active'            => $isActive,
            'toolkit_active'            => $toolkitActive,
            'toolkit_adapter_available' => $toolkitAdapterAvailable,
            'message'                   => $message,
        ]);
    }

    private function isAdapterPresent()
    {
        return $this->isPluginPresent(self::ADAPTER_PLUGIN_FILE);
    }

    private function isToolkitPresent()
    {
        return $this->isToolkitLoaded() || $this->isPluginPresent(self::TOOLKIT_PLUGIN_FILE);
    }

    private function detectAdapterVersion()
    {
        return $this->detectPluginVersion(self::ADAPTER_PLUGIN_FILE);
    }

    private function detectToolkitVersion()
    {
        if ($this->isToolkitLoaded()) {
            return (string) FLUENT_TOOLKIT_VERSION;
        }

        return $this->detectPluginVersion(self::TOOLKIT_PLUGIN_FILE);
    }

    private function isToolkitLoaded()
    {
        return defined('FLUENT_TOOLKIT_VERSION');
    }

    private function canAutoInstallFluentKit()
    {
        $canAutoInstall = (bool) apply_filters('fluent_kit/can_auto_install', false);

        if (!$canAutoInstall) {
            $canAutoInstall = (bool) apply_filters('fluent_toolkit/can_auto_install', false);
        }

        return $canAutoInstall;
    }

    private function autoInstallFluentKit()
    {
        if ((bool) apply_filters('fluent_kit/can_auto_install', false)) {
            do_action('fluent_kit/do_auto_install');
            return;
        }

        do_action('fluent_toolkit/do_auto_install');
    }

    private function isPluginPresent($pluginFile)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        return isset($plugins[$pluginFile]);
    }

    private function detectPluginVersion($pluginFile)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        if (!isset($plugins[$pluginFile])) {
            return null;
        }

        return $plugins[$pluginFile]['Version'] ?? null;
    }

    private function isToolkitAdapterAvailable()
    {
        if (!$this->isToolkitLoaded()) {
            return false;
        }

        if (class_exists('\FluentToolkit\Mcp\AdapterBootstrap') && method_exists('\FluentToolkit\Mcp\AdapterBootstrap', 'available')) {
            return (bool) \FluentToolkit\Mcp\AdapterBootstrap::available();
        }

        return $this->isAdapterRuntimeAvailable();
    }

    private function isAdapterRuntimeAvailable()
    {
        return defined('WP_MCP_VERSION')
            && class_exists('\WP\MCP\Core\McpAdapter')
            && function_exists('wp_register_ability');
    }

}
