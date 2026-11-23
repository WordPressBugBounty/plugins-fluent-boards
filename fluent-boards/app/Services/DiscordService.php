<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\PermissionManager;
use RuntimeException;
use Throwable;

/**
 * Private Discord (DM) task notifications.
 *
 * Members link their Discord account through OAuth2 (Discord has no polling API for DMs).
 * The bot adds them to the site's Discord server, because bots can only DM people who
 * share a server with them, and stores the DM channel used for delivery.
 */
class DiscordService extends NotificationDigestService
{
    const API = 'https://discord.com/api/v10';
    const CALLBACK_ACTION = 'fluent_boards_discord_callback';

    const PREFERENCES = '_fbs_discord_preferences';
    const BOARD_PREFERENCES = '_fbs_discord_board_preferences';
    const DIGEST_HOOK = 'fluent_boards/discord_send_digest';
    const RECOVERY_HOOK = 'fluent_boards/discord_recover_digest';
    const RECONCILE_HOOK = 'fluent_boards/discord_reconcile_digests';
    const DIGEST_TYPE = 'discord_digest';
    // Rows a digest job has claimed; `key` is "<batch>:<event>". They stay until their message is sent.
    const BATCH_TYPE = 'discord_digest_batch';
    // A claimed batch whose job dies is picked up again after this long.
    const RECOVERY_SECONDS = 900;
    // Discord enforces message nonce uniqueness for a few minutes. Keep retries inside two.
    const NONCE_RETRY_SECONDS = 120;
    // Set from a 429; no digest for the member is sent before it.
    const WAIT_PREFIX = 'fbs_discord_wait_';
    const BOT_WAIT_PREFIX = 'fbs_discord_bot_wait_';
    const DIGEST_MINUTES = [0, 1, 2, 5, 10, 15];
    const DEFAULT_DIGEST_MINUTES = 2;
    const MAX_RETRIES = 3;
    const EVENTS = Constant::CHAT_NOTIFICATION_EVENTS;

    const CREDENTIALS = '_fluent_boards_discord_creds';
    const SECRET_FIELDS = ['bot_token', 'client_secret'];
    const CONNECTION = '_fbs_discord_connection';
    const PENDING = '_fbs_discord_pending';
    const TOKEN_PREFIX = 'fbs_discord_link_';

    // Discord JSON error codes that mean the DM channel can no longer be used.
    const CANNOT_DM = 50007;
    const UNKNOWN_CHANNEL = 10003;
    const INVALID_FORM_BODY = 50035;

    private $retryAfter = 60;

    public function settings()
    {
        $settings = get_option(self::CREDENTIALS, []);
        $settings = is_array($settings) ? Helper::decryptFields($settings, self::SECRET_FIELDS) : [];
        return $settings + ['enabled' => false, 'digest_minutes' => self::DEFAULT_DIGEST_MINUTES];
    }

    protected function digestDelay()
    {
        return $this->settings()['digest_minutes'] * MINUTE_IN_SECONDS;
    }

    private function normalizeDigestMinutes($minutes)
    {
        $minutes = is_numeric($minutes) ? (int) $minutes : -1;
        return in_array($minutes, self::DIGEST_MINUTES, true) ? $minutes : self::DEFAULT_DIGEST_MINUTES;
    }

    private function isReady(?array $settings = null)
    {
        $settings = $settings ?? $this->settings();
        return !empty($settings['enabled']) && !empty($settings['bot_token']) && !empty($settings['client_secret'])
            && !empty($settings['application_id']) && !empty($settings['guild_id']);
    }

    /** The exact URL to add under OAuth2 > Redirects in the Discord developer portal. */
    public function redirectUri()
    {
        return admin_url('admin-post.php?action=' . self::CALLBACK_ACTION);
    }

    /** Return only public information, never the bot token or client secret. */
    public function displaySettings()
    {
        $settings = $this->settings();
        return [
            'configured' => !empty($settings['bot_token']) && !empty($settings['client_secret']),
            'enabled' => !empty($settings['enabled']),
            'application_id' => $settings['application_id'] ?? '',
            'guild_id' => $settings['guild_id'] ?? '',
            'bot_name' => $settings['bot_name'] ?? '',
            'guild_name' => $settings['guild_name'] ?? '',
            'digest_minutes' => $settings['digest_minutes'],
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    /** Validate every credential with Discord before replacing a working setup. */
    public function saveSettings(array $data)
    {
        $settings = $this->settings();
        $enabled = in_array($data['enabled'] ?? false, [true, 1, '1', 'true'], true);
        $field = function ($key) use ($data) {
            return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : '';
        };
        $digestMinutes = array_key_exists('digest_minutes', $data) && $data['digest_minutes'] !== null && $data['digest_minutes'] !== ''
            ? $this->normalizeDigestMinutes($data['digest_minutes']) : $settings['digest_minutes'];

        // Turning off a configured app keeps its credentials.
        if (!$enabled && !empty($settings['bot_token'])) {
            $this->withConnectionLock(0, function () use ($digestMinutes) {
                $this->storeSettings($this->settings(), false, $digestMinutes);
            });
            do_action('fluent_boards/discord_settings_updated');
            return $this->displaySettings();
        }

        $applicationId = $field('application_id') ?: ($settings['application_id'] ?? '');
        $guildId = $field('guild_id') ?: ($settings['guild_id'] ?? '');
        $botToken = $field('bot_token') ?: ($settings['bot_token'] ?? '');
        $clientSecret = $field('client_secret') ?: ($settings['client_secret'] ?? '');

        if (!preg_match('/^\d{15,22}$/D', $applicationId)) {
            throw new RuntimeException(__('Enter the Application ID from the Discord developer portal.', 'fluent-boards'));
        }
        if (!preg_match('/^\d{15,22}$/D', $guildId)) {
            throw new RuntimeException(__('Enter the Server ID of your Discord server.', 'fluent-boards'));
        }
        if (strlen($botToken) > 200 || !preg_match('/^[A-Za-z0-9._-]{50,}$/D', $botToken)) {
            throw new RuntimeException(__('Enter a valid bot token from the Bot page of your Discord application.', 'fluent-boards'));
        }
        if (strlen($clientSecret) > 128 || !preg_match('/^[A-Za-z0-9_-]{16,}$/D', $clientSecret)) {
            throw new RuntimeException(__('Enter the client secret from the OAuth2 page of your Discord application.', 'fluent-boards'));
        }

        $application = $this->request('GET', '/oauth2/applications/@me', [], 'Bot ' . $botToken);
        if ((string) ($application['id'] ?? '') !== $applicationId) {
            throw new RuntimeException(__('The bot token belongs to a different Discord application.', 'fluent-boards'));
        }
        $bot = $this->request('GET', '/users/@me', [], 'Bot ' . $botToken);
        try {
            $guild = $this->request('GET', '/guilds/' . $guildId, [], 'Bot ' . $botToken);
        } catch (RuntimeException $exception) {
            if (!in_array($exception->getCode(), [403, 404], true)) {
                throw $exception;
            }
            throw new RuntimeException(__('The bot cannot access this Discord server. Check the Server ID and make sure the bot has been invited.', 'fluent-boards'));
        }
        // A client credentials grant is the only way to check the secret without a member.
        $this->request('POST', '/oauth2/token', ['grant_type' => 'client_credentials', 'scope' => 'identify'],
            'Basic ' . base64_encode($applicationId . ':' . $clientSecret), true);

        $credentials = [
            'application_id' => $applicationId,
            'guild_id' => $guildId,
            'bot_token' => $botToken,
            'client_secret' => $clientSecret,
            'bot_name' => sanitize_text_field($bot['username'] ?? ''),
            'guild_name' => sanitize_text_field($guild['name'] ?? ''),
        ];
        $this->withConnectionLock(0, function () use ($settings, $credentials, $enabled, $digestMinutes) {
            $current = $this->settings();
            if ($current !== $settings) {
                throw new RuntimeException(__('Discord settings changed while saving. Reload the settings and try again.', 'fluent-boards'), 409);
            }
            $sameApp = ($current['application_id'] ?? '') === $credentials['application_id'];
            $credentials['generation'] = $sameApp && !empty($current['generation'])
                ? $current['generation'] : wp_generate_password(32, false, false);
            $this->storeSettings($credentials, $enabled, $digestMinutes);
        });
        do_action('fluent_boards/discord_settings_updated');
        return $this->displaySettings();
    }

    /** Publish the credentials, connection generation and delivery preferences in one database write. */
    private function storeSettings(array $settings, $enabled, $digestMinutes)
    {
        $settings['enabled'] = $enabled;
        $settings['digest_minutes'] = $digestMinutes;
        $encrypted = Helper::encryptFields($settings, self::SECRET_FIELDS);
        update_option(self::CREDENTIALS, $encrypted, false);
        if (get_option(self::CREDENTIALS) !== $encrypted) {
            throw new RuntimeException(__('Could not save the Discord settings. Please try again.', 'fluent-boards'));
        }
    }

    public function profile($userId)
    {
        $settings = $this->displaySettings();
        $connection = $this->connection($userId);
        return [
            'enabled' => $settings['enabled'] && $settings['configured'],
            'configured' => $settings['configured'],
            'guild_name' => $settings['guild_name'],
            'guild_id' => $settings['guild_id'],
            'connected' => (bool) $connection,
            'account' => $connection['account'] ?? '',
            'notifications_enabled' => !empty($connection['enabled']),
            'last_error' => $connection['last_error'] ?? '',
            'preferences' => $this->eventPreferences($userId),
        ];
    }

    /**
     * Discord user data is per site: on multisite every site has its own bot, generation and
     * board IDs. Keys get the site's table prefix, like user options.
     */
    private function metaKey($key)
    {
        global $wpdb;
        return $wpdb->get_blog_prefix() . $key;
    }

    private function getMeta($userId, $key)
    {
        return get_user_meta(absint($userId), $this->metaKey($key), true);
    }

    /**
     * Save and report whether the value was stored. update_user_meta() unslashes (a backslash in
     * a Discord name would be lost) and returns false for an unchanged value, so slash first and
     * read back instead of trusting its return value.
     */
    private function setMeta($userId, $key, $value)
    {
        $userId = absint($userId);
        update_user_meta($userId, $this->metaKey($key), wp_slash($value));
        wp_cache_delete($userId, 'user_meta');
        $stored = metadata_exists('user', $userId, $this->metaKey($key)) ? get_user_meta($userId, $this->metaKey($key), true) : null;
        return $stored === $value;
    }

    private function deleteMeta($userId, $key)
    {
        delete_user_meta(absint($userId), $this->metaKey($key));
    }

    private function connection($userId)
    {
        $stored = $this->getMeta($userId, self::CONNECTION);
        $settings = $this->settings();
        if (!is_array($stored) || empty($stored['channel_id']) || empty($stored['link_id']) || empty($settings['generation'])
            || ($stored['generation'] ?? '') !== $settings['generation']) {
            return [];
        }
        return $stored;
    }

    /** Store only the delivery switch and last error, unless the link changed meanwhile. */
    private function saveConnectionState($userId, array $original, $enabled, $lastError)
    {
        wp_cache_delete(absint($userId), 'user_meta');
        if ($this->connection($userId) !== $original) {
            return false;
        }
        if ($original['enabled'] === (bool) $enabled && $original['last_error'] === (string) $lastError) {
            return true;
        }
        $updatedConnection = $original;
        $updatedConnection['enabled'] = (bool) $enabled;
        $updatedConnection['last_error'] = (string) $lastError;
        // update_user_meta() can insert after a disconnect, even with a previous value.
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND HEX(meta_value) = HEX(%s)",
            maybe_serialize($updatedConnection), $userId, $this->metaKey(self::CONNECTION), maybe_serialize($original)
        ));
        wp_cache_delete(absint($userId), 'user_meta');
        return $updated > 0;
    }

    /** Serialize site configuration and member link writes. Discord HTTP requests run outside the lock. */
    private function withConnectionLock($userId, callable $callback)
    {
        $release = $this->acquireLock('connection', 5);
        if (!$release) {
            throw new RuntimeException(__('Another Discord connection change is in progress. Please try again.', 'fluent-boards'));
        }
        try {
            // Credentials are a non-autoloaded option and may be cached from before the lock.
            wp_cache_delete(self::CREDENTIALS, 'options');
            if ($userId) {
                wp_cache_delete(absint($userId), 'user_meta');
            }
            return $callback();
        } finally {
            $release();
        }
    }

    /** Issue a short-lived, single-use state secret and return the Discord authorize URL. */
    public function beginConnection($userId, $expectedGuildId = null)
    {
        $settings = $this->settings();
        if (!$this->isReady($settings)) {
            throw new RuntimeException(__('Ask an administrator to set up Discord in Settings first.', 'fluent-boards'));
        }
        if ($expectedGuildId !== null && $expectedGuildId !== $settings['guild_id']) {
            throw new RuntimeException(__('The Discord server changed. Reload your account settings and try again.', 'fluent-boards'));
        }
        $state = wp_generate_password(40, false, false);
        $hash = hash('sha256', $state);
        $tokenKey = self::TOKEN_PREFIX . $hash;
        $pending = ['user_id' => absint($userId), 'generation' => $settings['generation'], 'guild_id' => $settings['guild_id']];
        $this->withConnectionLock($userId, function () use ($userId, $hash, $tokenKey, $pending) {
            $this->cancelPending($userId);
            set_transient($tokenKey, $pending, 10 * MINUTE_IN_SECONDS);
            if (get_transient($tokenKey) !== $pending || !$this->setMeta($userId, self::PENDING, $hash)) {
                delete_transient($tokenKey);
                delete_user_meta(absint($userId), $this->metaKey(self::PENDING), $hash);
                throw new RuntimeException(__('Could not start the Discord connection. Please try again.', 'fluent-boards'));
            }
        });

        return ['url' => 'https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id' => $settings['application_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'identify guilds.join',
            'state' => $state,
            'prompt' => 'consent',
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    private function cancelPending($userId)
    {
        $hash = $this->getMeta($userId, self::PENDING);
        if (is_string($hash) && $hash) {
            delete_transient(self::TOKEN_PREFIX . $hash);
        }
        $this->deleteMeta($userId, self::PENDING);
    }

    /**
     * Finish the OAuth2 flow for the signed-in user. Returns a short result code for the
     * account page; the member's OAuth token is used once and never stored.
     */
    public function completeConnection($userId, $state, $code, $error = '')
    {
        $userId = absint($userId);
        $settings = $this->settings();
        $hash = is_string($state) && preg_match('/^[A-Za-z0-9]{40}$/D', $state) ? hash('sha256', $state) : '';
        $pending = $hash ? get_transient(self::TOKEN_PREFIX . $hash) : false;
        // The state must belong to this browser's user, so nobody can link their Discord to another account.
        if (!$userId || !is_array($pending) || absint($pending['user_id'] ?? 0) !== $userId
            || $this->getMeta($userId, self::PENDING) !== $hash || !delete_transient(self::TOKEN_PREFIX . $hash)) {
            return 'expired';
        }
        if ($error) {
            return 'denied';
        }
        if (!$this->isReady($settings) || ($pending['guild_id'] ?? '') !== $settings['guild_id']
            || ($pending['generation'] ?? '') !== ($settings['generation'] ?? '')
            || !PermissionManager::isFluentBoardsUser($userId)) {
            return 'expired';
        }
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9]{10,100}$/D', $code)) {
            return 'failed';
        }

        try {
            $token = $this->request('POST', '/oauth2/token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ], 'Basic ' . base64_encode($settings['application_id'] . ':' . $settings['client_secret']), true);
            if (empty($token['access_token'])) {
                return 'failed';
            }
            $user = $this->request('GET', '/users/@me', [], 'Bearer ' . $token['access_token']);
            if (empty($user['id']) || !preg_match('/^\d{15,22}$/D', (string) $user['id'])) {
                return 'failed';
            }
        } catch (RuntimeException $exception) {
            return 'failed';
        }

        // Bots can DM only members of a shared server. 201 = added, 204 = already a member.
        try {
            $this->request('PUT', '/guilds/' . $pending['guild_id'] . '/members/' . $user['id'],
                ['access_token' => $token['access_token']]);
        } catch (RuntimeException $exception) {
            return $exception->getCode() === 403 ? 'join_failed' : 'failed';
        }
        try {
            $channel = $this->request('POST', '/users/@me/channels', ['recipient_id' => (string) $user['id']]);
        } catch (RuntimeException $exception) {
            return 'failed';
        }
        if (empty($channel['id'])) {
            return 'failed';
        }

        $connection = [
            'link_id' => wp_generate_uuid4(),
            'discord_user_id' => (string) $user['id'],
            'channel_id' => (string) $channel['id'],
            'account' => sanitize_text_field($user['global_name'] ?? '') ?: sanitize_text_field($user['username'] ?? ''),
            'generation' => $settings['generation'],
            'enabled' => true,
            'last_error' => '',
        ];
        try {
            $result = $this->withConnectionLock($userId, function () use ($userId, $hash, $connection, $pending) {
                // A new attempt or disconnect during the Discord requests supersedes this callback.
                if ($this->getMeta($userId, self::PENDING) !== $hash) {
                    return 'expired';
                }
                $this->deleteMeta($userId, self::PENDING);
                $current = $this->settings();
                if (!$this->isReady($current) || $current['guild_id'] !== $pending['guild_id']
                    || ($current['generation'] ?? '') !== $connection['generation']) {
                    return 'expired';
                }
                return $this->setMeta($userId, self::CONNECTION, $connection) ? 'connected' : 'failed';
            });
        } catch (RuntimeException $exception) {
            return 'failed';
        }
        if ($result !== 'connected') {
            return $result;
        }
        try {
            $this->request('POST', '/channels/' . $connection['channel_id'] . '/messages', [
                'content' => __('Discord is connected. Notifications from Fluent Boards will appear here.', 'fluent-boards'),
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (RuntimeException $exception) {
            // Keep the link so the member can fix their privacy settings and send a test.
            $current = $this->connection($userId);
            if (($current['link_id'] ?? '') === $connection['link_id']) {
                $this->saveConnectionState($userId, $current, $current['enabled'], $exception->getMessage());
            }
        }
        do_action('fluent_boards/discord_account_connected', $userId);
        return 'connected';
    }

    /** Where the browser goes after the callback; the account page reads the result code. */
    public function accountUrl($userId, $result)
    {
        return fluent_boards_page_url() . 'member/' . absint($userId) . '/account?fbs_discord=' . rawurlencode($result);
    }

    public function disconnect($userId)
    {
        $this->withConnectionLock($userId, function () use ($userId) {
            $this->cancelPending($userId);
            $this->deleteMeta($userId, self::CONNECTION);
            wp_cache_delete(absint($userId), 'user_meta');
            if ($this->getMeta($userId, self::CONNECTION) !== '' || $this->getMeta($userId, self::PENDING) !== '') {
                throw new RuntimeException(__('Could not disconnect Discord. Please try again.', 'fluent-boards'));
            }
        });
        do_action('fluent_boards/discord_account_disconnected', $userId);
        return $this->profile($userId);
    }

    public function updatePreference($userId, $enabled)
    {
        $connection = $this->connection($userId);
        if (!$connection) {
            throw new RuntimeException(__('Connect your Discord account first.', 'fluent-boards'));
        }
        $enabled = in_array($enabled, [true, 1, '1', 'true'], true);
        if (!$this->saveConnectionState($userId, $connection, $enabled, $connection['last_error'])) {
            throw new RuntimeException(__('Could not save the Discord notification preferences. Please try again.', 'fluent-boards'));
        }
        do_action('fluent_boards/discord_preference_updated', $userId);
        return $this->profile($userId);
    }

    public function sendTest($userId)
    {
        $connection = $this->connection($userId);
        if (!$connection || !$this->isReady()) {
            throw new RuntimeException(__('Connect your Discord account and enable the integration first.', 'fluent-boards'));
        }
        try {
            $this->request('POST', '/channels/' . $connection['channel_id'] . '/messages', [
                'content' => __('Discord is connected. Notifications from Fluent Boards will appear here.', 'fluent-boards'),
                'allowed_mentions' => ['parse' => []],
            ]);
        } catch (RuntimeException $exception) {
            $this->saveConnectionState($userId, $connection, $connection['enabled'], $exception->getMessage());
            throw $exception;
        }
        $this->saveConnectionState($userId, $connection, $connection['enabled'], '');
    }

    /** Event preferences are separate from email and Telegram. A board override wins over the global set. */
    public function eventPreferences($userId, $boardId = 0)
    {
        $override = $boardId ? $this->boardOverride($userId, $boardId) : null;
        return $override !== null ? $this->normalizeEvents($override) : $this->normalizeEvents($this->getMeta($userId, self::PREFERENCES));
    }

    /** Missing events default to enabled so new event types reach existing members. */
    private function normalizeEvents($saved)
    {
        $saved = is_array($saved) ? $saved : [];
        $preferences = [];
        foreach (self::EVENTS as $event) {
            $preferences[$event] = !array_key_exists($event, $saved) || in_array($saved[$event], [true, 1, '1', 'true'], true);
        }
        return $preferences;
    }

    private function boardOverride($userId, $boardId)
    {
        $boardId = absint($boardId);
        $override = $this->getMeta($userId, self::BOARD_PREFERENCES . '_' . $boardId);
        if (is_array($override)) {
            // An empty array explicitly clears an override from the old shared map.
            return $override ?: null;
        }
        $all = $this->getMeta($userId, self::BOARD_PREFERENCES);
        return is_array($all) && isset($all[$boardId]) && is_array($all[$boardId]) ? $all[$boardId] : null;
    }

    public function boardPreferences($userId, $boardId)
    {
        $profile = $this->profile($userId);
        return [
            'enabled' => $profile['enabled'],
            'connected' => $profile['connected'],
            'notifications_enabled' => $profile['notifications_enabled'],
            'same_as_global' => $this->boardOverride($userId, $boardId) === null,
            'preferences' => $this->eventPreferences($userId, $boardId),
            'global_preferences' => $profile['preferences'],
        ];
    }

    /** Save or clear one board's override; clearing makes the board follow the global preferences again. */
    public function updateBoardPreferences($userId, $boardId, $sameAsGlobal, $data)
    {
        $userId = absint($userId);
        $boardId = absint($boardId);
        if (!$boardId) {
            throw new RuntimeException(__('Invalid board.', 'fluent-boards'));
        }
        $preferences = [];
        if (!in_array($sameAsGlobal, [true, 1, '1', 'true'], true)) {
            if (!is_array($data)) {
                throw new RuntimeException(__('Invalid notification preferences.', 'fluent-boards'));
            }
            $preferences = $this->eventPreferences($userId, $boardId);
            foreach (self::EVENTS as $event) {
                if (array_key_exists($event, $data)) {
                    $preferences[$event] = in_array($data[$event], [true, 1, '1', 'true'], true);
                }
            }
        }
        if (!$this->setMeta($userId, self::BOARD_PREFERENCES . '_' . $boardId, $preferences)) {
            throw new RuntimeException(__('Could not save the board notification preferences. Please try again.', 'fluent-boards'));
        }
        do_action('fluent_boards/discord_preference_updated', $userId);
        return $this->boardPreferences($userId, $boardId);
    }

    public function updateEventPreferences($userId, $data)
    {
        if (!is_array($data)) {
            throw new RuntimeException(__('Invalid notification preferences.', 'fluent-boards'));
        }
        $preferences = $this->eventPreferences($userId);
        foreach (self::EVENTS as $event) {
            if (array_key_exists($event, $data)) {
                $preferences[$event] = in_array($data[$event], [true, 1, '1', 'true'], true);
            }
        }
        // An unchanged save counts as saved; a write that kept other values does not.
        $this->setMeta($userId, self::PREFERENCES, $preferences);
        if ($this->normalizeEvents($this->getMeta($userId, self::PREFERENCES)) !== $preferences) {
            throw new RuntimeException(__('Could not save the Discord notification preferences. Please try again.', 'fluent-boards'));
        }
        do_action('fluent_boards/discord_preference_updated', absint($userId));
        return $this->profile($userId);
    }

    /** Queue one background delivery per eligible user, independently of email and Telegram. */
    public function queueNotification($event, Task $task, $recipientIds = null, $commentId = 0)
    {
        if (!in_array($event, self::EVENTS, true) || !$this->isReady()) {
            return;
        }
        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        $boardId = (int) ($task->board_id ?: ($parent ? $parent->board_id : 0));
        $recipientIds = $recipientIds === null ? $task->watchers()->pluck('users.ID')->all() : (array) $recipientIds;
        $actorId = get_current_user_id();
        $isAssignment = in_array($event, ['add_assignee', 'remove_assignee'], true);
        if (!$isAssignment) {
            $recipientIds = array_diff(array_map('absint', $recipientIds), [$actorId]);
        }
        $recipientIds = PermissionManager::getUserIdsWithBoardAccess($boardId, $recipientIds);
        // Personal notification pauses (all boards or this board) apply to every channel.
        $recipientIds = (new NotificationService())->filterUnmutedUserIds($boardId, $recipientIds);
        foreach ($recipientIds as $userId) {
            try {
                $connection = $this->connection($userId);
                if (empty($connection['enabled'])) {
                    continue;
                }
                $preferences = $this->eventPreferences($userId, $boardId);
                $historyOnly = $userId === $actorId || !$preferences[$event];
                // Both assignment directions establish the net change, even when only one can notify.
                if ($historyOnly && (!$isAssignment || (!$preferences['add_assignee'] && !$preferences['remove_assignee']))) {
                    continue;
                }
                $this->addToDigest($userId, $event, $task->id, $commentId, $boardId, $connection, $actorId, $historyOnly);
            } catch (Throwable $exception) {
                // The task or comment is already saved. Continue with the other recipients.
                error_log(sprintf('FluentBoards could not queue Discord event %s for task %d and user %d', $event, $task->id, $userId));
            }
        }
    }

    /**
     * Record one activity for a member and make sure their digest job is scheduled. Each
     * activity is its own row, so concurrent requests never overwrite each other.
     */
    private function addToDigest($userId, $event, $taskId, $commentId, $boardId, array $connection, $actorId, $historyOnly)
    {
        Meta::create([
            'object_type' => self::DIGEST_TYPE,
            'object_id' => absint($userId),
            'key' => $event,
            'value' => [
                'task_id' => absint($taskId),
                'comment_id' => absint($commentId),
                'board_id' => absint($boardId),
                'actor_id' => absint($actorId),
                'history_only' => $historyOnly,
                'generation' => $connection['generation'],
                'link_id' => $connection['link_id'],
            ],
        ]);
        // The window is fixed from the first activity, so busy tasks never hold DMs back indefinitely.
        if (!$this->hasPendingDigest($userId)) {
            $this->scheduleDigest($userId, 0, $this->digestDelay());
        }
    }

    private function batchItems($userId, $batch)
    {
        $rows = Meta::where('object_type', self::BATCH_TYPE)->where('object_id', absint($userId))
            ->where('key', 'LIKE', $batch . ':%')->orderBy('id', 'ASC')->get();
        $items = [];
        foreach ($rows as $row) {
            $value = is_array($row->value) ? $row->value : [];
            // An attempted message appends its nonce and first attempt time to every row in one write.
            $parts = explode(':', $row->key);
            $items[] = array_merge($value, [
                'event' => $parts[1], 'row_id' => (int) $row->id,
                'nonce' => $parts[2] ?? '', 'attempted_at' => (int) ($parts[3] ?? 0),
            ]);
        }
        return $items;
    }

    /** Change the entire chunk together so a worker crash cannot split its delivery identity. */
    private function markMessage(array $items, $nonce, $clear = false)
    {
        global $wpdb;
        $ids = array_map('intval', array_column($items, 'row_id'));
        $table = $wpdb->prefix . (new Meta())->getTable();
        $key = $clear ? "SUBSTR(`key`, 1, INSTR(`key`, %s) - 1)" : "CONCAT(`key`, %s)";
        $marker = ':' . $nonce . ':';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET `key` = {$key} WHERE object_type = %s AND id IN (" . implode(',', $ids) . ')',
            $clear ? $marker : $marker . time(), self::BATCH_TYPE
        ));
        if ($updated !== count($ids)) {
            throw new RuntimeException(__('Could not save the Discord delivery attempt.', 'fluent-boards'));
        }
    }

    /** Give unattempted messages a fresh delivery budget when a batch exhausts its retries. */
    protected function finishExhaustedBatch($userId, $batch)
    {
        global $wpdb;
        $table = $wpdb->prefix . (new Meta())->getTable();
        // Only unattempted rows have the bare "<batch>:<event>" key. Keep nonce-marked rows out of future digests.
        $released = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET object_type = %s, `key` = SUBSTR(`key`, INSTR(`key`, ':') + 1)
             WHERE object_id = %d AND object_type = %s AND `key` LIKE %s AND `key` NOT LIKE %s",
            self::DIGEST_TYPE, $userId, self::BATCH_TYPE, $batch . ':%', '%:%:%'
        ));
        if ($released === false) {
            throw new RuntimeException(__('Could not claim the Discord digest.', 'fluent-boards'));
        }
        $this->acknowledge($this->batchItems($userId, $batch));
    }

    /** Read both deadlines directly so another worker's 429 is visible between chunks. */
    protected function waitSeconds($userId)
    {
        global $wpdb;
        $botKey = self::BOT_WAIT_PREFIX . hash('sha256', $this->settings()['bot_token'] ?? '');
        $memberKey = $this->memberWaitKey($userId);
        $deadline = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(CAST(option_value AS UNSIGNED)) FROM `{$wpdb->base_prefix}options` WHERE option_name IN (%s, %s)",
            $memberKey, $botKey
        ));
        return max(0, (int) $deadline - time());
    }

    private function memberWaitKey($userId)
    {
        return self::WAIT_PREFIX . get_current_blog_id() . '_' . $userId;
    }

    /** Overlapping 429 responses can extend a deadline, never shorten it. */
    private function extendWait($key)
    {
        global $wpdb;
        $deadline = time() + $this->retryAfter;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$wpdb->base_prefix}options` (option_name, option_value, autoload) VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), %d)",
            $key, $deadline, $deadline
        ));
    }

    protected function sendBatch($userId, $attempt, $batch, &$retrying)
    {
        $items = $this->batchItems($userId, $batch);
        if (!$items) {
            return;
        }
        // A long-running worker may have cached this user before a disconnect or relink.
        wp_cache_delete(absint($userId), 'user_meta');
        $connection = $this->connection($userId);
        if (!$this->isReady() || empty($connection['enabled'])) {
            $this->acknowledge($items);
            return;
        }

        $groups = [];
        $dropped = [];
        foreach ($items as $item) {
            if (!in_array($item['event'] ?? '', self::EVENTS, true)
                || ($item['generation'] ?? '') !== $connection['generation']
                || ($item['link_id'] ?? '') !== $connection['link_id']) {
                $dropped[] = $item;
                continue;
            }
            $groups[absint($item['task_id'] ?? 0)][] = $item;
        }

        $this->acknowledge($dropped);

        $lastError = '';
        // Retry each attempted message on its own, even if access or chunk sizes have changed.
        $attempted = [];
        $unsent = [];
        foreach ($groups as $taskId => $taskItems) {
            $nonce = $taskItems[0]['nonce'];
            if ($nonce !== '') {
                $attempted[$nonce][] = $taskId;
            } else {
                $unsent[] = $taskId;
            }
        }
        $queue = array_merge(array_values($attempted), array_chunk($unsent, 10));
        while ($queue) {
            $taskIds = array_shift($queue);
            // Disconnects and relinks can complete while a previous message is in flight.
            wp_cache_delete($userId, 'user_meta');
            wp_cache_delete(self::CREDENTIALS, 'options');
            $current = $this->connection($userId);
            if (empty($current['enabled']) || !$this->isReady()
                || $current['generation'] !== $connection['generation'] || $current['link_id'] !== $connection['link_id']
                || $current['channel_id'] !== $connection['channel_id']) {
                $this->acknowledge($items);
                return;
            }
            // Build each message from current board access, pauses, preferences and task state.
            $embeds = [];
            foreach ($taskIds as $taskId) {
                $embed = $this->buildTaskEmbed($userId, $taskId, $groups[$taskId]);
                if ($embed) {
                    $embeds[$taskId] = $embed;
                } else {
                    $this->acknowledge($groups[$taskId]);
                }
            }
            if (!$embeds) {
                continue;
            }
            $delay = $this->waitSeconds($userId);
            if ($delay > 0) {
                // No post was attempted. Keep the same retry budget.
                if ($this->scheduleDigest($userId, $attempt, $delay, $batch)) {
                    $this->cancelRecovery($userId, $attempt + 1, $batch);
                }
                $retrying = true;
                return;
            }
            $firstItem = $groups[$taskIds[0]][0];
            $nonce = $firstItem['nonce'];
            $replaying = $nonce !== '';
            $chunks = $this->chunkEmbeds($embeds);
            if ($replaying && (time() >= $firstItem['attempted_at'] + self::NONCE_RETRY_SECONDS || count($chunks) > 1)) {
                // Discord may already have accepted it. Never repost under a new nonce or after expiry.
                foreach (array_keys($embeds) as $taskId) {
                    $this->acknowledge($groups[$taskId]);
                }
                $lastError = __('Delivery of a previous Discord message could not be confirmed.', 'fluent-boards');
                continue;
            }
            $chunk = array_shift($chunks);
            $queue = array_merge($chunks, $queue);
            $chunkItems = [];
            foreach ($chunk as $taskId) {
                $chunkItems = array_merge($chunkItems, $groups[$taskId]);
            }
            if (!$replaying) {
                $nonce = bin2hex(random_bytes(12));
                $this->markMessage($chunkItems, $nonce);
            }
            try {
                $this->postEmbeds($connection['channel_id'], array_intersect_key($embeds, array_flip($chunk)), $nonce);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
                $code = $exception->getCode();
                if ($code === 429 || ($code >= 500 && $code < 600) || !$code) {
                    // This message and later ones stay in the batch for its own retry.
                    if ($code === 429) {
                        // Only a first-attempt rejection proves the message was never accepted.
                        if (!$replaying) {
                            $this->markMessage($chunkItems, $nonce, true);
                        }
                        $this->extendWait($this->memberWaitKey($userId));
                    }
                    if ($attempt < self::MAX_RETRIES) {
                        if ($this->scheduleDigest($userId, $attempt + 1, max($this->retryAfter, 60 * pow(3, $attempt)), $batch)) {
                            $this->cancelRecovery($userId, $attempt + 1, $batch);
                        }
                        $retrying = true;
                    } else {
                        if ($code !== 429) {
                            $this->acknowledge($chunkItems);
                            continue;
                        }
                        $this->finishExhaustedBatch($userId, $batch);
                    }
                    break;
                }
                if (!in_array($code, [self::INVALID_FORM_BODY, 413], true)) {
                    $this->acknowledge($items);
                    break;
                }
                // Discord rejected this message. Send its tasks one by one so a single bad
                // embed does not take the others with it, then carry on with later messages.
                if (!$replaying && count($chunk) > 1) {
                    $this->markMessage($chunkItems, $nonce, true);
                    $queue = array_merge(array_map(function ($index) {
                        return [$index];
                    }, $chunk), $queue);
                } else {
                    $this->acknowledge($chunkItems);
                }
                continue;
            }
            $this->acknowledge($chunkItems);
        }
        $this->saveConnectionState($userId, $connection, $connection['enabled'], $lastError);
    }

    private function postEmbeds($channelId, array $embeds, $nonce)
    {
        $this->request('POST', '/channels/' . $channelId . '/messages', [
            'allowed_mentions' => ['parse' => []],
            'embeds' => array_values($embeds),
            'nonce' => $nonce,
            'enforce_nonce' => true,
        ]);
    }

    /**
     * Split embeds into messages within Discord's limits (10 embeds, 6000 characters). Discord
     * shows only the first embed for each URL in a message, and subtasks link to their parent,
     * so embeds that share a URL always go in different messages.
     */
    private function chunkEmbeds(array $embeds)
    {
        $chunks = [];
        foreach ($embeds as $index => $embed) {
            $length = mb_strlen($embed['title'] . $embed['description'] . implode('', array_column($embed['fields'], 'name'))
                . implode('', array_column($embed['fields'], 'value')));
            $placed = false;
            foreach ($chunks as &$chunk) {
                if (count($chunk['items']) < 10 && $chunk['size'] + $length <= 5500 && !isset($chunk['urls'][$embed['url']])) {
                    $chunk['items'][] = $index;
                    $chunk['size'] += $length;
                    $chunk['urls'][$embed['url']] = true;
                    $placed = true;
                    break;
                }
            }
            unset($chunk);
            if (!$placed) {
                $chunks[] = ['items' => [$index], 'size' => $length, 'urls' => [$embed['url'] => true]];
            }
        }
        return array_column($chunks, 'items');
    }

    /** Recheck each activity for one task and describe what is still true, or return null. */
    private function buildTaskEmbed($userId, $taskId, array $items)
    {
        $task = Task::find(absint($taskId));
        if (!$task) {
            return null;
        }
        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        $board = $task->board_id ? $task->board : ($parent ? $parent->board : null);
        if (!$board || $board->archived_at || !PermissionManager::getUserIdsWithBoardAccess($board->id, [$userId])
            || (new NotificationService())->isNotificationMuted($userId, $board->id)) {
            return null;
        }
        $preferences = $this->eventPreferences($userId, $board->id);
        $archived = $task->archived_at || ($parent && $parent->archived_at);

        $byEvent = [];
        $firstAssignment = null;
        $lastAssignment = null;
        foreach ($items as $item) {
            $event = $item['event'];
            if ((int) ($item['board_id'] ?? 0) !== (int) $board->id) {
                continue;
            }
            if ($event === 'task_archived' ? !$task->archived_at : $archived) {
                continue;
            }
            if (in_array($event, ['add_assignee', 'remove_assignee'], true)) {
                $firstAssignment = $firstAssignment ?? $event;
                $lastAssignment = $item;
                continue;
            }
            if (empty($preferences[$event])) {
                continue;
            }
            if (in_array($event, ['comment', 'mention'], true)) {
                $comment = Comment::withoutGlobalScopes()->find(absint($item['comment_id'] ?? 0));
                if (!$comment || (int) $comment->task_id !== (int) $task->id || !in_array($comment->type, ['comment', 'reply'], true)) {
                    continue;
                }
            }
            $byEvent[$event][] = $item;
        }

        // Report assignment by net outcome. The first change tells where the member started
        // (an add means they were not assigned); the task tells where they are now.
        if ($lastAssignment && empty($lastAssignment['history_only']) && $preferences[$lastAssignment['event']]) {
            $wasAssignee = $firstAssignment === 'remove_assignee';
            $isAssignee = $task->assignees()->where('users.ID', absint($userId))->exists();
            $event = $isAssignee ? 'add_assignee' : 'remove_assignee';
            if ($wasAssignee !== $isAssignee && $event === $lastAssignment['event']) {
                $byEvent = [$event => [$lastAssignment]] + $byEvent;
            }
        }
        if (!$byEvent) {
            return null;
        }

        $lines = [];
        foreach ($byEvent as $event => $eventItems) {
            $lines[] = '- ' . $this->activityLine($event, $eventItems);
        }

        $fields = [['name' => __('Board', 'fluent-boards'), 'value' => $this->plain($board->title, 200), 'inline' => true]];
        if ($task->stage) {
            $fields[] = ['name' => __('Stage', 'fluent-boards'), 'value' => $this->plain($task->stage->title, 200), 'inline' => true];
        }
        if ($task->due_at) {
            $fields[] = [
                'name' => __('Due', 'fluent-boards'),
                'value' => $this->plain(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($task->due_at)), 200),
                'inline' => true,
            ];
        }
        $url = fluent_boards_page_url() . 'boards/' . (int) $board->id . '/tasks/' . (int) ($task->parent_id ?: $task->id);
        return [
            'title' => $this->plain($task->title, 256),
            'url' => esc_url_raw($url),
            'description' => $this->cut(implode("\n", $lines), 1500),
            'color' => 0x335CFF,
            'fields' => $fields,
        ];
    }

    /** One sentence per kind of activity; repeated activity is counted, not repeated. */
    private function activityLine($event, array $items)
    {
        $last = $this->actorName(end($items)['actor_id'] ?? 0);
        $count = count($items);
        switch ($event) {
            case 'add_assignee':
                /* translators: %s is the name of the person who assigned the task */
                return sprintf(__('%s assigned you to this task.', 'fluent-boards'), $last);
            case 'remove_assignee':
                /* translators: %s is the name of the person who removed the assignee */
                return sprintf(__('%s removed you from this task.', 'fluent-boards'), $last);
            case 'stage_change':
                /* translators: %s is the person who changed the task stage */
                return sprintf(__('%s changed the task stage.', 'fluent-boards'), $last);
            case 'due_date_update':
                /* translators: %s is the name of the person who changed the dates */
                return sprintf(__('%s updated the task dates.', 'fluent-boards'), $last);
            case 'description_update':
                /* translators: %s is the name of the person who edited the description */
                return sprintf(__('%s updated the task description.', 'fluent-boards'), $last);
            case 'priority_change':
                /* translators: %s is the name of the person who changed the priority */
                return sprintf(__('%s updated the task priority.', 'fluent-boards'), $last);
            case 'task_archived':
                /* translators: %s is the name of the person who archived the task */
                return sprintf(__('%s archived this task.', 'fluent-boards'), $last);
            case 'mention':
                $names = $this->actorNames($items);
                if ($count === 1) {
                    /* translators: %s is the name of the person who mentioned the member */
                    return sprintf(__('%s mentioned you in a comment.', 'fluent-boards'), $names);
                }
                /* translators: %1$s is a list of names, %2$d is the number of comments */
                return sprintf(__('%1$s mentioned you in %2$d comments.', 'fluent-boards'), $names, $count);
            default:
                $names = $this->actorNames($items);
                if ($count === 1) {
                    /* translators: %s is the name of the person who commented */
                    return sprintf(__('%s added a comment.', 'fluent-boards'), $names);
                }
                /* translators: %1$d is the number of comments, %2$s is a list of names */
                return sprintf(__('%1$d new comments from %2$s.', 'fluent-boards'), $count, $names);
        }
    }

    private function actorName($actorId)
    {
        $user = $actorId ? get_userdata(absint($actorId)) : null;
        return $user && $user->display_name !== '' ? $this->plain($user->display_name, 80) : __('Someone', 'fluent-boards');
    }

    /** Up to three distinct names, then a count of the rest. */
    private function actorNames(array $items)
    {
        $names = array_values(array_unique(array_map(function ($item) {
            return $this->actorName($item['actor_id'] ?? 0);
        }, $items)));
        if (count($names) <= 3) {
            return implode(', ', $names);
        }
        /* translators: %1$s is a list of three names, %2$d is how many other people */
        return sprintf(__('%1$s and %2$d more', 'fluent-boards'), implode(', ', array_slice($names, 0, 3)), count($names) - 3);
    }

    /** Comment watchers or the parent author; mentions use their explicitly selected recipients. */
    public function queueCommentNotification(Comment $comment, $mentionedIds = null)
    {
        if (!in_array($comment->type, ['comment', 'reply'], true) || !$this->isReady()) {
            return;
        }
        $task = Task::find($comment->task_id);
        if (!$task) {
            return;
        }
        $recipients = $mentionedIds;
        if ($mentionedIds === null) {
            if ($comment->type === 'reply') {
                $parent = Comment::withoutGlobalScopes()->find($comment->parent_id, ['task_id', 'created_by']);
                $recipients = $parent && (int) $parent->task_id === (int) $task->id ? [$parent->created_by] : [];
            } else {
                $recipients = $task->watchers()->pluck('users.ID')->all();
            }
            // Mentioned users get the mention message instead, as with email.
            $settings = $comment->settings;
            $mentioned = is_array($settings) && !empty($settings['mentioned_id']) ? array_map('absint', (array) $settings['mentioned_id']) : [];
            $recipients = array_diff(array_map('absint', $recipients), $mentioned);
            if (!$recipients) {
                return;
            }
        }
        $this->queueNotification($mentionedIds === null ? 'comment' : 'mention', $task, $recipients, $comment->id);
    }

    /**
     * Strip HTML and escape Discord markdown so titles cannot format or link, then shorten.
     * The limit applies to the escaped text, which is what Discord counts.
     */
    private function plain($text, $length)
    {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/([\\\\*_~`|>#\[\]()<:-])/', '\\\\$1', $text);
        $text = $this->cut($text, $length);
        return $text !== '' ? $text : '-';
    }

    /**
     * Shorten to at most $length characters including the "..." marker, without splitting a
     * character or leaving half of a markdown escape. mb_substr and mb_strlen have WordPress
     * fallbacks when mbstring is missing; mb_strimwidth does not.
     */
    private function cut($text, $length)
    {
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        $text = mb_substr($text, 0, $length - 3, 'UTF-8');
        // An odd run of trailing backslashes is an escape cut in half.
        if (preg_match('/(\\\\+)$/', $text, $match) && strlen($match[1]) % 2) {
            $text = substr($text, 0, -1);
        }
        return $text . '...';
    }

    /** Fixed API host, bounded timeout, and generic errors keep credentials out of responses. */
    private function request($method, $path, array $body = [], $auth = null, $form = false)
    {
        $auth = $auth ?? 'Bot ' . ($this->settings()['bot_token'] ?? '');
        $args = [
            'method' => $method,
            'timeout' => 12,
            'redirection' => 0,
            'headers' => [
                'Authorization' => $auth,
                'User-Agent' => 'DiscordBot (https://fluentboards.com, 1.0)',
            ],
        ];
        if ($method !== 'GET') {
            $args['headers']['Content-Type'] = $form ? 'application/x-www-form-urlencoded' : 'application/json';
            $args['body'] = $form ? http_build_query($body, '', '&') : wp_json_encode((object) $body);
        }
        $response = wp_remote_request(self::API . $path, $args);
        if (is_wp_error($response)) {
            throw new RuntimeException(__('Could not reach Discord. Please try again.', 'fluent-boards'));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = $raw === '' ? [] : json_decode($raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($data) ? $data : [];
        }
        $data = is_array($data) ? $data : [];
        if ($status === 429) {
            $retryAfter = $data['retry_after'] ?? wp_remote_retrieve_header($response, 'retry-after');
            $this->retryAfter = is_numeric($retryAfter) ? max(1, (int) ceil((float) $retryAfter)) : 60;
            $global = !empty($data['global']) || wp_remote_retrieve_header($response, 'x-ratelimit-global') === 'true'
                || wp_remote_retrieve_header($response, 'x-ratelimit-scope') === 'global';
            if ($global && strpos($auth, 'Bot ') === 0) {
                $this->extendWait(self::BOT_WAIT_PREFIX . hash('sha256', substr($auth, 4)));
            }
            throw new RuntimeException(__('Discord is rate-limiting requests. Please try again later.', 'fluent-boards'), 429);
        }
        if ($path === '/oauth2/token' && ($body['grant_type'] ?? '') === 'client_credentials'
            && ($status === 401 || ($status === 400 && ($data['error'] ?? '') === 'invalid_client'))) {
            throw new RuntimeException(__('Discord rejected the client secret. Copy it again from the OAuth2 page.', 'fluent-boards'), $status);
        }
        $errorCode = (int) ($data['code'] ?? 0);
        if ($status === 400 && $errorCode === self::INVALID_FORM_BODY) {
            throw new RuntimeException(__('Discord rejected the message format.', 'fluent-boards'), self::INVALID_FORM_BODY);
        }
        if ($errorCode === self::CANNOT_DM) {
            throw new RuntimeException(__('Discord could not deliver the message. Allow direct messages from server members, or reconnect Discord.', 'fluent-boards'), 403);
        }
        if ($errorCode === self::UNKNOWN_CHANNEL) {
            throw new RuntimeException(__('This Discord conversation no longer exists. Reconnect Discord.', 'fluent-boards'), 404);
        }
        if ($status === 401) {
            throw new RuntimeException(__('The Discord credentials are invalid. Ask an administrator to update them.', 'fluent-boards'), 401);
        }
        if ($status === 403) {
            throw new RuntimeException(__('The Discord bot is missing a permission. Ask an administrator to check the bot.', 'fluent-boards'), 403);
        }
        throw new RuntimeException(__('Discord could not complete the request. Please try again.', 'fluent-boards'), $status);
    }
}
