<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Modules\MCP\MCPInit;
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
            'app_passwords_url'              => admin_url('profile.php#application-passwords-section'),
            'plugins_url'                    => admin_url('plugins.php'),
            'can_auto_install_adapter'       => $canAutoInstall,
            'toolkit_download_url'           => 'https://github.com/WPManageNinja/fluent-toolkit',
            'current_user_login'             => $currentUser ? $currentUser->user_login : '',
            'is_local_dev'                   => self::detectLocalDevEnvironment(),
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
                'message'              => __('Please install FluentKit from GitHub, then reload this page to connect Fluent Boards with AI agents.', 'fluent-boards'),
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
            $message = __('FluentKit installed and activated. Reload the page to register Fluent Boards MCP tools.', 'fluent-boards');
        } elseif ($toolkitInstalled && $toolkitActive) {
            $message = __('FluentKit is installed and active, but this version does not include the bundled MCP adapter yet. Please update FluentKit when the MCP-ready build is available, then reload this page.', 'fluent-boards');
        } elseif ($toolkitInstalled) {
            $message = __('FluentKit is installed but could not be activated automatically. Please activate FluentKit from the Plugins page, then reload this page.', 'fluent-boards');
        } else {
            $message = __('Could not install FluentKit automatically. Please install FluentKit manually, then reload this page.', 'fluent-boards');
        }

        return $this->sendSuccess([
            'is_installed'              => $isInstalled,
            'adapter_active'            => $isActive,
            'toolkit_active'            => $toolkitActive,
            'toolkit_adapter_available' => $toolkitAdapterAvailable,
            'message'                   => $message,
        ]);
    }

    public function getConfigSnippet(Request $request)
    {
        $client = sanitize_key((string) $request->get('client', 'claude-code'));
        $endpoint = MCPInit::getEndpointUrl();
        $isLocalDev = $this->resolveLocalDevOverride($request);

        $basicPlaceholder = '<base64(your-username:application-password)>';
        $usernamePlaceholder = '<your-username>';
        $passwordPlaceholder = '<your-application-password>';
        $appPasswordsUrl = admin_url('profile.php#application-passwords-section');

        switch ($client) {
            case 'codex':
                $snippet = sprintf(
                    "Settings > Connect to a custom MCP\n\nName:        fluent-boards\nTransport:   Streamable HTTP\n\nURL:         %s\n\nHeader:\n  Key:       Authorization\n  Value:     Basic %s\n\nClick Save.",
                    $endpoint,
                    $basicPlaceholder
                );
                $instructions = sprintf(
                    __('Open OpenAI Codex settings, connect to a custom MCP, choose Streamable HTTP, then use a WordPress Application Password from %s.', 'fluent-boards'),
                    $appPasswordsUrl
                );
                break;

            case 'cursor':
                $snippet = wp_json_encode([
                    'mcpServers' => [
                        'fluent-boards' => [
                            'url'     => $endpoint,
                            'type'    => 'http',
                            'headers' => [
                                'Authorization' => 'Basic ' . $basicPlaceholder,
                            ],
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $instructions = __('Paste this into Cursor Settings > MCP, then restart Cursor.', 'fluent-boards');
                break;

            case 'generic':
                $snippet = sprintf(
                    "URL:   %s\nAuth:  Authorization: Basic %s\n\n# Quick test\ncurl -s -u '%s:%s' \\\n  -X POST %s \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'",
                    $endpoint,
                    $basicPlaceholder,
                    $usernamePlaceholder,
                    $passwordPlaceholder,
                    $endpoint
                );
                $instructions = __('Use the URL and Basic Auth header with any HTTP MCP client. The endpoint speaks MCP over JSON-RPC.', 'fluent-boards');
                break;

            case 'claude-desktop':
                $env = [
                    'WP_API_URL'      => $endpoint,
                    'WP_API_USERNAME' => $usernamePlaceholder,
                    'WP_API_PASSWORD' => $passwordPlaceholder,
                ];

                if ($isLocalDev) {
                    $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
                }

                $snippet = wp_json_encode([
                    'mcpServers' => [
                        'fluent-boards' => [
                            'command' => 'npx',
                            'args'    => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                            'env'     => $env,
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                $instructions = __('Paste into the Claude Desktop config file, then restart Claude Desktop.', 'fluent-boards');
                if ($isLocalDev) {
                    $instructions .= ' ' . __('Local dev mode is on, so NODE_TLS_REJECT_UNAUTHORIZED is included for self-signed SSL.', 'fluent-boards');
                }
                break;

            case 'claude-code':
            default:
                $snippet = sprintf(
                    "claude mcp add \\\n  --transport http \\\n  fluent-boards %s \\\n  --header \"Authorization: Basic %s\"",
                    $endpoint,
                    $basicPlaceholder
                );
                $instructions = __('Fill in your username and application password above, then paste the command into your terminal.', 'fluent-boards');
                $client = 'claude-code';
                break;
        }

        return $this->sendSuccess([
            'client'            => $client,
            'snippet'           => $snippet,
            'instructions'      => $instructions,
            'endpoint'          => $endpoint,
            'app_passwords_url' => $appPasswordsUrl,
            'is_local_dev'      => $isLocalDev,
        ]);
    }

    private function resolveLocalDevOverride(Request $request)
    {
        $forceLocalDev = $request->get('local_dev');
        if ($forceLocalDev === 'yes' || $forceLocalDev === '1' || $forceLocalDev === 'true') {
            return true;
        }

        if ($forceLocalDev === 'no' || $forceLocalDev === '0' || $forceLocalDev === 'false') {
            return false;
        }

        return self::detectLocalDevEnvironment();
    }

    private static function detectLocalDevEnvironment()
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower((string) $host);

        $isDev = false;
        foreach (['.test', '.lab', '.local', '.localhost', '.docker', '.dev'] as $tld) {
            if (substr($host, -strlen($tld)) === $tld) {
                $isDev = true;
                break;
            }
        }

        if (!$isDev && ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1')) {
            $isDev = true;
        }

        if (!$isDev && filter_var($host, FILTER_VALIDATE_IP)) {
            $isDev = !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return (bool) apply_filters('fluent_boards/mcp_is_local_dev', $isDev, $host);
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
