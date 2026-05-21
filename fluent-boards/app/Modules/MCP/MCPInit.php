<?php

namespace FluentBoards\App\Modules\MCP;

/**
 * Bootstrap for Fluent Boards' Model Context Protocol integration.
 *
 * Mirrors FluentCRM's WordPress-native architecture: abilities are registered
 * through the WordPress Abilities API and exposed by the WP MCP Adapter at a
 * dedicated Fluent Boards MCP endpoint.
 */
class MCPInit
{
    public function init()
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
        add_action('mcp_adapter_init', [$this, 'registerCustomServer']);
    }

    public function registerCategory()
    {
        wp_register_ability_category('fluent-boards', [
            'label'       => __('FluentBoards', 'fluent-boards'),
            'description' => __('Board, stage, task, and comment abilities for Fluent Boards.', 'fluent-boards'),
        ]);
    }

    public function registerAbilities()
    {
        AbilitiesRegistrar::register();

        /**
         * Fires after Fluent Boards has registered its core MCP abilities.
         *
         * Extensions can hook here and register additional abilities under the
         * same `fluent-boards/` namespace.
         *
         * @since 1.0.0
         */
        do_action('fluent_boards/mcp_loaded');
    }

    /**
     * Register the dedicated Fluent Boards MCP server.
     *
     * @param object $adapter WP MCP Adapter instance.
     */
    public function registerCustomServer($adapter)
    {
        if (!$adapter || !is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $abilityNames = array_keys(AbilitiesRegistrar::getDefinitions());
        $abilityNames = apply_filters('fluent_boards/mcp_ability_names', $abilityNames);

        $namespace = apply_filters('fluent_boards/mcp_server_namespace', 'fluent-boards');
        $route     = apply_filters('fluent_boards/mcp_server_route', 'mcp');

        $adapter->create_server(
            'fluent-boards',
            $namespace,
            $route,
            __('Fluent Boards MCP Server', 'fluent-boards'),
            __('AI agent tools for Fluent Boards projects, stages, tasks, and comments.', 'fluent-boards'),
            defined('FLUENT_BOARDS_PLUGIN_VERSION') ? FLUENT_BOARDS_PLUGIN_VERSION : '1.0.0',
            ['\WP\MCP\Transport\HttpTransport'],
            '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
            '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            array_values(array_unique(array_filter((array) $abilityNames)))
        );
    }

    public static function getEndpointUrl()
    {
        $namespace = apply_filters('fluent_boards/mcp_server_namespace', 'fluent-boards');
        $route     = apply_filters('fluent_boards/mcp_server_route', 'mcp');

        return get_rest_url(null, trailingslashit($namespace) . $route);
    }

    public static function getToolsCount()
    {
        $names = apply_filters('fluent_boards/mcp_ability_names', array_keys(AbilitiesRegistrar::getDefinitions()));

        if (!is_array($names)) {
            return count(AbilitiesRegistrar::getDefinitions());
        }

        return count(array_unique($names));
    }

    /**
     * Register FluentBoards in FluentKit's MCP products screen.
     *
     * @param array $products Existing MCP products.
     * @param array $adapter  FluentKit adapter status.
     * @return array
     */
    public static function registerToolkitProduct($products, $adapter = [])
    {
        $enabled = fluent_boards_get_option('mcp_enabled', 'yes') === 'yes';
        $adapterAvailable = !empty($adapter['available']);

        if (!$adapterAvailable) {
            $status = 'adapter_required';
        } elseif (!$enabled) {
            $status = 'disabled';
        } else {
            $status = 'ready';
        }

        $products[] = [
            'slug'         => 'fluent-boards',
            'title'        => __('FluentBoards', 'fluent-boards'),
            'endpoint_url' => self::getEndpointUrl(),
            'tools_count'  => self::getToolsCount(),
            'status'       => $status,
            'enabled'      => $enabled ? 'yes' : 'no',
        ];

        return $products;
    }
}
