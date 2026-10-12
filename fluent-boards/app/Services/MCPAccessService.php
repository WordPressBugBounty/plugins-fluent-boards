<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Modules\MCP\MCPInit;

/**
 * Who may use MCP, and the application passwords created for it.
 *
 * Admins may always use MCP while it is enabled; board members may use it
 * when the `mcp_member_access` setting is on. Credential ownership is stored
 * as a site-scoped user option, so a multisite network keeps it separate
 * per site. Feature-created
 * application passwords carry APP_ID and may only authenticate the Fluent
 * Boards MCP endpoint of the site that created them.
 */
class MCPAccessService
{
    const CREDENTIALS_OPTION = 'fbs_mcp_credentials';
    const APP_ID = '6b1f0c52-7d4e-4f0a-9a43-fb5c0b4d2e71';

    /** Password item that authenticated the current request, captured before any later deletion. */
    private static $authenticatedItem = null;

    /** Per-request canUseMcp() results, keyed by user id. */
    private static $canUseCache = [];

    /**
     * Wire MCP enforcement. Runs even when MCP is globally disabled so
     * feature-owned credentials stay restricted.
     */
    public static function registerHooks()
    {
        add_filter('wp_register_ability_args', [self::class, 'gateAbilityArgs'], 10, 2);
        add_filter('rest_pre_dispatch', [self::class, 'gateRestRequest'], 10, 3);
        add_action('wp_authenticate_application_password_errors', [self::class, 'blockXmlRpcLogin'], 10, 3);
        add_action('application_password_did_authenticate', [self::class, 'captureAuthenticatedItem'], 10, 2);
    }

    public static function isGloballyEnabled()
    {
        return fluent_boards_get_option('mcp_enabled', 'yes') === 'yes';
    }

    /** Whether board members (not only admins) may use MCP. Off by default. */
    public static function isMemberAccessEnabled()
    {
        return fluent_boards_get_option('mcp_member_access', 'no') === 'yes';
    }

    /**
     * Admins are always allowed; board members only when member access is on.
     */
    public static function isApproved($userId)
    {
        $userId = (int) $userId;
        if (!$userId) {
            return false;
        }

        if (PermissionManager::isAdmin($userId, false)) {
            return true;
        }

        return self::isMemberAccessEnabled() && PermissionManager::isFluentBoardsUser($userId);
    }

    public static function canUseMcp($userId = null)
    {
        $userId = $userId ? (int) $userId : get_current_user_id();

        // Endpoint gate and each ability's permission check ask the same question within one request.
        if (!array_key_exists($userId, self::$canUseCache)) {
            self::$canUseCache[$userId] = $userId > 0
                && self::isGloballyEnabled()
                && PermissionManager::hasAppAccess($userId)
                && self::isApproved($userId);
        }

        return self::$canUseCache[$userId];
    }

    /**
     * State the profile panel needs to decide whether creation is possible.
     */
    public static function getProfileState($userId)
    {
        $user = get_userdata($userId);
        $appPasswordsAvailable = $user
            && class_exists('\WP_Application_Passwords')
            && wp_is_application_passwords_available_for_user($user)
            && user_can($user, 'create_app_password', $userId);

        return [
            'enabled'                 => self::isGloballyEnabled(),
            'adapter_available'       => function_exists('wp_register_ability') && did_action('mcp_adapter_init') > 0,
            'network_enforced'        => self::isNetworkEnforced(),
            'approved'                => self::isApproved($userId),
            'member_access'           => self::isMemberAccessEnabled(),
            'is_local_dev'            => self::isLocalDev(),
            'app_passwords_available' => (bool) $appPasswordsAvailable,
            'endpoint'                => MCPInit::getEndpointUrl(),
            'username'                => $user ? $user->user_login : '',
            'connections'             => self::listConnections($userId),
        ];
    }

    /**
     * Feature-owned connections without password hashes.
     */
    public static function listConnections($userId)
    {
        if (!class_exists('\WP_Application_Passwords')) {
            return [];
        }

        $owned = self::getOwnedUuids($userId);
        $connections = [];

        foreach (\WP_Application_Passwords::get_user_application_passwords($userId) as $item) {
            if (!in_array($item['uuid'], $owned, true)) {
                continue;
            }

            $connections[] = [
                'uuid'      => $item['uuid'],
                'name'      => $item['name'],
                'created'   => $item['created'] ? gmdate('Y-m-d H:i:s', $item['created']) : null,
                'last_used' => $item['last_used'] ? gmdate('Y-m-d H:i:s', $item['last_used']) : null,
            ];
        }

        return $connections;
    }

    /**
     * Create a connection for the user. The plaintext password is returned once.
     *
     * @return array|\WP_Error
     */
    public static function createConnection($userId, $name)
    {
        $state = self::getProfileState($userId);

        if (!$state['enabled']) {
            return new \WP_Error('mcp_disabled', __('MCP is disabled on this site.', 'fluent-boards'), ['status' => 403]);
        }
        if (!$state['approved']) {
            return new \WP_Error('mcp_not_approved', __('MCP is only available to administrators on this site.', 'fluent-boards'), ['status' => 403]);
        }
        if (!$state['adapter_available']) {
            return new \WP_Error('mcp_adapter_unavailable', __('The MCP adapter is not active on this site.', 'fluent-boards'), ['status' => 403]);
        }
        if (!$state['network_enforced']) {
            return new \WP_Error('mcp_network_scope', __('On multisite, Fluent Boards must be network-activated before MCP keys can be created.', 'fluent-boards'), ['status' => 403]);
        }
        if (!$state['app_passwords_available']) {
            return new \WP_Error('app_passwords_unavailable', __('Application passwords are not available for your account.', 'fluent-boards'), ['status' => 403]);
        }

        $name = sanitize_text_field($name);
        if ($name === '') {
            return new \WP_Error('invalid_name', __('Connection name is required.', 'fluent-boards'), ['status' => 422]);
        }

        $created = self::withUserLock($userId, function () use ($userId, $name) {
            $created = \WP_Application_Passwords::create_new_application_password($userId, [
                'name'   => $name,
                'app_id' => self::APP_ID,
            ]);

            if (is_wp_error($created)) {
                return $created;
            }

            $uuid = $created[1]['uuid'];
            $owned = self::getOwnedUuids($userId);
            $owned[] = $uuid;
            self::saveOwnedUuids($userId, array_values(array_unique($owned)));

            if (!in_array($uuid, self::getOwnedUuids($userId), true)) {
                \WP_Application_Passwords::delete_application_password($userId, $uuid);
                return new \WP_Error('credential_not_saved', __('Could not save the connection. Please try again.', 'fluent-boards'), ['status' => 500]);
            }

            // A denial may have landed while the key was being created; never leave it usable.
            if (!self::isApproved($userId)) {
                self::deleteOwned($userId, $uuid);
                return new \WP_Error('mcp_not_approved', __('MCP is only available to administrators on this site.', 'fluent-boards'), ['status' => 403]);
            }

            return $created;
        });

        if (is_wp_error($created)) {
            return $created;
        }

        list($password, $item) = $created;

        return [
            'password'   => \WP_Application_Passwords::chunk_password($password),
            'username'   => $state['username'],
            'endpoint'   => $state['endpoint'],
            'connection' => [
                'uuid'      => $item['uuid'],
                'name'      => $item['name'],
                'created'   => gmdate('Y-m-d H:i:s', $item['created']),
                'last_used' => null,
            ],
        ];
    }

    /**
     * Delete one feature-owned connection. Unrelated passwords are never touched.
     *
     * @return true|\WP_Error
     */
    public static function revokeConnection($userId, $uuid)
    {
        return self::withUserLock($userId, function () use ($userId, $uuid) {
            return self::deleteOwned($userId, $uuid);
        });
    }

    private static function deleteOwned($userId, $uuid)
    {
        $owned = self::getOwnedUuids($userId);
        if (!in_array($uuid, $owned, true)) {
            return new \WP_Error('not_found', __('Connection not found.', 'fluent-boards'), ['status' => 404]);
        }

        $deleted = \WP_Application_Passwords::delete_application_password($userId, $uuid);
        if (is_wp_error($deleted) && $deleted->get_error_code() !== 'application_password_not_found') {
            return $deleted;
        }

        self::saveOwnedUuids($userId, array_values(array_diff(self::getOwnedUuids($userId), [$uuid])));

        return true;
    }

    /**
     * Wrap every fluent-boards/* ability permission check, including extension abilities.
     */
    public static function gateAbilityArgs($args, $name)
    {
        if (strpos((string) $name, 'fluent-boards/') !== 0 || empty($args['permission_callback'])) {
            return $args;
        }

        $callback = $args['permission_callback'];
        $args['permission_callback'] = function (...$params) use ($callback) {
            if (!self::canUseMcp()) {
                return self::deniedError();
            }

            return call_user_func_array($callback, $params);
        };

        return $args;
    }

    /**
     * Gate the MCP transport and confine feature-owned credentials to it.
     */
    public static function gateRestRequest($result, $server, $request)
    {
        if ($result !== null) {
            return $result;
        }

        $isMcpRoute = self::isMcpRoute($request->get_route());

        $item = self::$authenticatedItem;
        if ($item && isset($item['app_id']) && $item['app_id'] === self::APP_ID) {
            // Fails closed if the credential was revoked after authentication.
            $ownedHere = in_array($item['uuid'], self::getOwnedUuids(get_current_user_id()), true)
                && \WP_Application_Passwords::get_user_application_password(get_current_user_id(), $item['uuid']);
            if (!$isMcpRoute || !$ownedHere) {
                return new \WP_Error('fluent_boards_mcp_credential_scope', __('This credential can only be used with the Fluent Boards MCP endpoint.', 'fluent-boards'), ['status' => 403]);
            }
        }

        if ($isMcpRoute && !self::canUseMcp()) {
            return self::deniedError();
        }

        return $result;
    }

    public static function captureAuthenticatedItem($user, $item)
    {
        self::$authenticatedItem = is_array($item) ? $item : null;
    }

    /**
     * Feature credentials never authenticate XML-RPC.
     */
    public static function blockXmlRpcLogin($error, $user, $item)
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST && isset($item['app_id']) && $item['app_id'] === self::APP_ID) {
            $error->add('fluent_boards_mcp_credential_scope', __('This credential can only be used with the Fluent Boards MCP endpoint.', 'fluent-boards'));
        }
    }

    private static function isMcpRoute($route)
    {
        $namespace = apply_filters('fluent_boards/mcp_server_namespace', 'fluent-boards');
        $mcpRoute = '/' . trim($namespace, '/') . '/' . trim(apply_filters('fluent_boards/mcp_server_route', 'mcp'), '/');
        // WordPress matches REST routes case-insensitively.
        $mcpRoute = strtolower($mcpRoute);
        $route = strtolower(untrailingslashit((string) $route));

        return $route === $mcpRoute || strpos($route, $mcpRoute . '/') === 0;
    }

    /**
     * Feature keys are network-wide WordPress credentials, so on multisite
     * the scope gate must run on every site.
     */
    private static function isNetworkEnforced()
    {
        if (!is_multisite()) {
            return true;
        }

        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network(plugin_basename(FLUENT_BOARDS_PLUGIN_PATH . 'fluent-boards.php'));
    }

    /**
     * Serialize credential writes per user; WordPress rewrites the whole password array.
     */
    private static function withUserLock($userId, callable $callback)
    {
        global $wpdb;

        $lock = 'fbs_mcp_keys_' . (int) $userId; // Password array is network-wide.
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock))) {
            return new \WP_Error('locked', __('Another MCP key change is in progress. Please try again.', 'fluent-boards'), ['status' => 409]);
        }

        try {
            wp_cache_delete($userId, 'user_meta');
            return $callback();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function saveOwnedUuids($userId, array $uuids)
    {
        update_user_option($userId, self::CREDENTIALS_OPTION, $uuids);
        wp_cache_delete($userId, 'user_meta');
    }

    private static function getOwnedUuids($userId)
    {
        $owned = get_user_option(self::CREDENTIALS_OPTION, $userId);

        return is_array($owned) ? array_values(array_filter($owned, 'is_string')) : [];
    }

    private static function deniedError()
    {
        return new \WP_Error('fluent_boards_mcp_access_denied', __('MCP is only available to administrators on this site.', 'fluent-boards'), ['status' => 403]);
    }

    /**
     * Local/dev hosts usually use self-signed SSL, which some MCP bridges reject.
     */
    public static function isLocalDev()
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));

        $isDev = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        foreach (['.test', '.lab', '.local', '.localhost', '.docker', '.dev'] as $tld) {
            if (substr($host, -strlen($tld)) === $tld) {
                $isDev = true;
            }
        }

        if (!$isDev && filter_var($host, FILTER_VALIDATE_IP)) {
            $isDev = !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return (bool) apply_filters('fluent_boards/mcp_is_local_dev', $isDev, $host);
    }
}
