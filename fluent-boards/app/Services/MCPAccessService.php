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
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
        add_filter('fluent_kit/mcp_hub_product_user_can_connect', [self::class, 'vouchForHubUser'], 10, 3);
    }

    /** Fluent Boards' id on FluentHub: its MCP server id and product slug. */
    const HUB_SLUG = 'fluent-boards';

    /**
     * Let the people who may use Fluent Boards MCP sign in through FluentHub's one URL.
     *
     * FluentHub admits site administrators to every plugin and has no role setting. Anyone
     * else connects only through the plugin that admits them, so this filter is the only
     * way a board member or a Fluent Boards admin who is not a site administrator can use
     * the one URL. It answers for the Fluent Boards tools only, with the rule of an
     * application password: Fluent Boards MCP is on,
     * and the user is a Fluent Boards admin, or a board member while member access is on.
     * Such a user reaches Fluent Boards through the hub and no other plugin.
     *
     * @param bool          $allowed What FluentHub or an earlier callback decided.
     * @param string        $slug    Product slug the question is about.
     * @param \WP_User|int $user
     * @return bool
     */
    public static function vouchForHubUser($allowed, $slug, $user = null)
    {
        // Another product's question, or already allowed: never change the answer.
        if ($slug !== self::HUB_SLUG || $allowed === true) {
            return $allowed;
        }

        $userId = is_object($user) && isset($user->ID) ? (int) $user->ID : (int) $user;

        // The same two conditions as canUseMcp(), asked about this user. canUseMcp() also
        // needs someone to be signed in on the request, which FluentHub's question does
        // not promise.
        return $userId > 0 && self::isGloballyEnabled() && self::isApproved($userId);
    }

    /**
     * FluentHub's one-URL connection, as the MCP screens show it.
     *
     * Read from the fluent_kit/mcp_hub_state filter (fluent-toolkit
     * docs/mcp-product-connect.md), never from a FluentToolkit class. Returns null when
     * FluentHub is absent or answers with a contract version this code does not know:
     * the screens then look as they do without FluentHub.
     *
     * @param array|null $state For tests: a state in place of the filter's answer.
     * @return array|null
     */
    public static function hubState($state = null)
    {
        if ($state === null) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
            $state = apply_filters('fluent_kit/mcp_hub_state', null, self::HUB_SLUG);
        }

        if (!is_array($state) || (int) ($state['version'] ?? 0) !== 1
            || empty($state['hub']) || empty($state['sign_in']) || empty($state['product'])
        ) {
            return null;
        }

        // Printed as given: the wording and the connection name are FluentHub's.
        $clients = [];
        foreach ((array) ($state['clients'] ?? []) as $client) {
            if (!is_array($client) || empty($client['key']) || empty($client['snippet'])) {
                continue;
            }

            $clients[] = [
                'key'     => sanitize_key($client['key']),
                'label'   => sanitize_text_field((string) ($client['label'] ?? $client['key'])),
                'note'    => sanitize_text_field((string) ($client['note'] ?? '')),
                'snippet' => (string) $client['snippet'],
            ];
        }

        $products = [];
        foreach ((array) ($state['products'] ?? []) as $product) {
            if (!is_array($product) || empty($product['slug'])) {
                continue;
            }

            $products[] = [
                'slug'        => sanitize_key($product['slug']),
                'name'        => sanitize_text_field((string) ($product['name'] ?? $product['slug'])),
                'served'      => !empty($product['served']),
                'can_enable'  => !empty($product['can_enable']),
                'tools_count' => (int) ($product['tools_count'] ?? 0),
            ];
        }

        return [
            'ready'            => !empty($state['ready']) && !empty($clients),
            'endpoint_url'     => esc_url_raw((string) ($state['hub']['endpoint_url'] ?? '')),
            'hub_reason'       => sanitize_key((string) ($state['hub']['reason'] ?? '')),
            'sign_in_reason'   => sanitize_key((string) ($state['sign_in']['reason'] ?? '')),
            'included'         => !empty($state['product']['included']),
            'user_can_connect' => !empty($state['user_can_connect']),
            'can_manage'       => !empty($state['can_manage']),
            'manage_url'       => esc_url_raw((string) ($state['manage_url'] ?? '')),
            'profile_url'      => esc_url_raw((string) ($state['profile_url'] ?? '')),
            'clients'          => $clients,
            'products'         => $products,
        ];
    }

    /**
     * Turn on the FluentHub MCP server ("hub"), or the MCP of another Fluent plugin on the
     * site (its slug), through FluentHub's own call. That call checks that the user may
     * manage the site and runs the plugin's own switch. It can only turn things on.
     *
     * Call it only from a POST route that checked a nonce and the admin policy.
     *
     * @param string     $target "hub" or a plugin slug from the hub state's products.
     * @param array|null $result For tests: a result in place of the filter's answer.
     * @return array status (done|forbidden|unavailable), message, and hub (the fresh state) when done.
     */
    public static function enableHub($target, $result = null)
    {
        $target = sanitize_key((string) $target);
        if ($target === '') {
            $target = 'hub';
        }

        if ($result === null) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentHub's hook
            $result = apply_filters('fluent_kit/mcp_hub_enable', null, self::HUB_SLUG, $target);
        }

        // null: FluentHub is not active, or is a release without this call.
        if (!is_array($result)) {
            return [
                'status'  => 'unavailable',
                'message' => __('FluentHub is not active on this site.', 'fluent-boards'),
            ];
        }

        $status = (string) ($result['status'] ?? '');
        $reason = (string) ($result['reason'] ?? '');

        if ($status === 'done') {
            return [
                'status'  => 'done',
                'message' => $target === 'hub'
                    ? __('The FluentHub MCP server is on.', 'fluent-boards')
                    : __('On. Your AI app sees it the next time it loads its tools.', 'fluent-boards'),
                'hub'     => self::hubState(isset($result['state']) && is_array($result['state']) ? $result['state'] : null),
            ];
        }

        if ($status === 'forbidden') {
            $refusal = $reason === 'product_refused' ? sanitize_text_field((string) ($result['message'] ?? '')) : '';

            return [
                'status'  => 'forbidden',
                'message' => $refusal !== '' ? $refusal : __('Only a site administrator can turn this on.', 'fluent-boards'),
            ];
        }

        $messages = [
            'adapter_missing'  => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-boards'),
            'adapter_outdated' => __('FluentHub needs a newer MCP adapter before this can be turned on.', 'fluent-boards'),
            'unknown_product'  => __('FluentHub does not know this plugin.', 'fluent-boards'),
            'not_active'       => __('This plugin is not active on the site.', 'fluent-boards'),
            'no_handler'       => __('This plugin cannot be turned on from here. Use its own MCP settings.', 'fluent-boards'),
        ];

        return [
            'status'  => 'unavailable',
            'message' => $messages[$reason] ?? __('This could not be turned on. Open FluentHub to check its MCP settings.', 'fluent-boards'),
        ];
    }

    /**
     * The name an AI app gives this site's Fluent Boards connection in the
     * application-password snippets: a short product code plus the site address.
     *
     * An app refuses a second connection with a name it already has, so one fixed name
     * collides for the owner of two sites. An app also builds each tool's name as
     * mcp__{name}__{tool}, which must fit in 64 characters, so the address is cut to
     * what the longest tool name leaves.
     *
     * @param int|null $longestToolName For tests: the length of the longest tool name.
     * @return string
     */
    public static function connectionName($longestToolName = null)
    {
        if ($longestToolName === null) {
            $longestToolName = 0;
            $names = (array) apply_filters('fluent_boards/mcp_ability_names', array_keys(\FluentBoards\App\Modules\MCP\AbilitiesRegistrar::getDefinitions()));
            foreach ($names as $name) {
                $longestToolName = max($longestToolName, strlen((string) $name));
            }
        }

        $prefix = 'fbs';
        // 64, less "mcp__", "__", the tool name, the prefix and its dash.
        $room = 64 - 5 - 2 - (int) $longestToolName - strlen($prefix) - 1;

        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $path = trim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        $address = trim((string) preg_replace('/[^a-z0-9]+/', '-', preg_replace('/^www\./', '', $host) . ($path !== '' ? '-' . strtolower($path) : '')), '-');

        if ($room < 1 || $address === '') {
            return $prefix;
        }

        if (strlen($address) <= $room) {
            return $prefix . '-' . $address;
        }

        // Cut: two sites whose addresses start alike would get one name. Keep a short hash
        // of the whole address at the end, so example.com/site-one and example.com/site-two
        // stay apart.
        $hash = substr(md5($address), 0, 4);
        $readable = $room > 6 ? rtrim(substr($address, 0, $room - 5), '-') : '';

        return $readable !== '' ? $prefix . '-' . $readable . '-' . $hash : $prefix . '-' . substr($hash, 0, min(4, $room));
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
            // The name of this connection in the setup code, different on every site.
            'connection_name'         => self::connectionName(),
            // FluentHub's one-URL connection, or null. It describes the signed-in user, so
            // it is sent only when a person looks at their own account.
            'hub'                     => (int) $userId === get_current_user_id() ? self::hubState() : null,
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
                return self::abilityDeniedError();
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

    /**
     * The denial an AI agent reads as a tool result: the JSON error envelope of the MCP
     * tools, in plain English. deniedError() is the HTTP answer a person sees.
     */
    private static function abilityDeniedError()
    {
        return \FluentBoards\App\Modules\MCP\Helpers\MCPHelper::error(
            'forbidden',
            'Your WordPress user may not use the Fluent Boards tools on this site. They are open to Fluent Boards admins, and to board members when a site admin turns on member access in the Fluent Boards MCP settings.'
        );
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
