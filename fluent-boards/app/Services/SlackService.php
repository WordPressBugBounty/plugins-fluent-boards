<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use RuntimeException;

/**
 * Private Slack (DM) task notifications.
 *
 * Members authorize their Slack identity in the workspace where the site bot is installed.
 */
class SlackService extends NotificationDigestService
{
    const API = 'https://slack.com/api/';
    const CALLBACK_ACTION = 'fluent_boards_slack_callback';

    const PREFERENCES = '_fbs_slack_preferences';
    const BOARD_PREFERENCES = '_fbs_slack_board_preferences';
    const DIGEST_HOOK = 'fluent_boards/slack_send_digest';
    const RECOVERY_HOOK = 'fluent_boards/slack_recover_digest';
    const RECONCILE_HOOK = 'fluent_boards/slack_reconcile_digests';
    const DIGEST_TYPE = 'slack_digest';
    // Rows a digest job has claimed; `key` is "<batch>:<event>". They stay until their message is sent.
    const BATCH_TYPE = 'slack_digest_batch';
    // A claimed batch whose job dies is picked up again after this long.
    const RECOVERY_SECONDS = 900;
    // Set from a 429; no digest for the member is sent before it.
    const WAIT_PREFIX = 'fbs_slack_wait_';
    const POST_WAIT_PREFIX = 'fbs_slack_post_wait_';
    const DEFERRED_BY_COOLDOWN = -1;
    const DIGEST_MINUTES = [0, 1, 2, 5, 10, 15];
    const DEFAULT_DIGEST_MINUTES = 2;
    const MAX_RETRIES = 3;
    // Processing an exhausted chunk schedules one more recovery for the remaining chunks.
    protected const RECOVERY_ATTEMPTS_AFTER_RETRIES = 2;
    const EVENTS = Constant::CHAT_NOTIFICATION_EVENTS;

    const SETTINGS = 'slack_settings';
    const SETTINGS_ROW_ID = '_fbs_slack_settings_row_id';
    const CREDENTIALS = '_fluent_boards_slack_creds';
    const SECRET_FIELDS = ['bot_token', 'client_secret'];
    const CONNECTION = '_fbs_slack_connection';
    const PENDING = '_fbs_slack_pending';
    const TOKEN_PREFIX = 'fbs_slack_link_';

    private $retryAfter = 60;

    private function settings()
    {
        $credentials = get_option(self::CREDENTIALS, []);
        $credentials = is_array($credentials) ? Helper::decryptFields($credentials, self::SECRET_FIELDS) : [];
        // A never-configured site has no credentials or delivery settings to load.
        $local = $credentials ? $this->deliverySettings() : [];
        return array_merge(is_array($credentials) ? $credentials : [], [
            'enabled' => !empty($local['enabled']),
            'digest_minutes' => $this->normalizeDigestMinutes($local['digest_minutes'] ?? self::DEFAULT_DIGEST_MINUTES),
        ]);
    }

    /** Cache the row ID so workers read the current settings by primary key. */
    private function deliverySettings()
    {
        $rowId = (int) get_option(self::SETTINGS_ROW_ID, 0);
        if ($rowId) {
            $row = Meta::find($rowId);
            if ($row && $row->object_type === 'option' && $row->key === self::SETTINGS) {
                return (array) $row->value;
            }
        }

        // Discover the row on older installations or after it has been replaced.
        $row = Meta::where('object_type', 'option')->where('key', self::SETTINGS)->first();
        if (!$row) {
            return [];
        }
        update_option(self::SETTINGS_ROW_ID, (int) $row->id, false);
        return (array) $row->value;
    }

    private function normalizeDigestMinutes($minutes)
    {
        $minutes = is_numeric($minutes) ? (int) $minutes : -1;
        return in_array($minutes, self::DIGEST_MINUTES, true) ? $minutes : self::DEFAULT_DIGEST_MINUTES;
    }

    private function isReady(array $settings)
    {
        return !empty($settings['enabled']) && !empty($settings['bot_token']) && !empty($settings['client_secret'])
            && !empty($settings['client_id']) && !empty($settings['team_id']);
    }

    /** The exact URL to add to the Slack app redirect URLs. */
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
            'client_id' => $settings['client_id'] ?? '',
            'team_id' => $settings['team_id'] ?? '',
            'bot_name' => $settings['bot_name'] ?? '',
            'team_name' => $settings['team_name'] ?? '',
            'digest_minutes' => $settings['digest_minutes'],
            'redirect_uri' => $this->redirectUri(),
        ];
    }

    /** Verify the installed bot and identify its workspace before replacing a working setup. */
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
            return $this->commitSettings($settings, false, $digestMinutes);
        }

        $clientId = $field('client_id') ?: ($settings['client_id'] ?? '');
        $botToken = $field('bot_token') ?: ($settings['bot_token'] ?? '');
        $secret = $field('client_secret') ?: ($settings['client_secret'] ?? '');
        if (!preg_match('/^\d+\.\d+$/D', $clientId) || !preg_match('/^[a-zA-Z0-9_-]{16,128}$/D', $secret)) {
            throw new RuntimeException(__('Enter the client ID and client secret from Slack app settings.', 'fluent-boards'));
        }
        if (!preg_match('/^xoxb-[a-zA-Z0-9-]+$/D', $botToken)) {
            throw new RuntimeException(__('Enter the installed Slack app bot token.', 'fluent-boards'));
        }
        $bot = $this->request('auth.test', [], $botToken);
        if (empty($bot['team_id']) || empty($bot['bot_id'])) {
            throw new RuntimeException(__('The token must belong to a bot installed in a Slack workspace.', 'fluent-boards'));
        }
        $sameApp = ($settings['client_id'] ?? '') === $clientId && ($settings['team_id'] ?? '') === $bot['team_id']
            && ($settings['bot_id'] ?? '') === $bot['bot_id'];
        $credentials = [
            'client_id' => $clientId,
            'team_id' => $bot['team_id'],
            'bot_id' => $bot['bot_id'],
            'bot_token' => $botToken,
            'client_secret' => $secret,
            'bot_name' => sanitize_text_field($bot['user'] ?? ''),
            'team_name' => sanitize_text_field($bot['team'] ?? ''),
            'generation' => $sameApp ? $settings['generation'] : wp_generate_password(32, false, false),
        ];
        return $this->commitSettings($settings, $enabled, $digestMinutes, $credentials);
    }

    /** Verify the snapshot again after Slack responds, then serialize only the local writes. */
    private function commitSettings(array $snapshot, $enabled, $digestMinutes, array $credentials = [])
    {
        $release = $this->acquireLock('settings', 5);
        if (!$release) {
            throw new RuntimeException(__('Could not save the Slack settings. Please try again.', 'fluent-boards'));
        }
        try {
            wp_cache_delete(self::CREDENTIALS, 'options');
            wp_cache_delete('notoptions', 'options');
            if ($snapshot !== $this->settings()) {
                throw new RuntimeException(__('Slack settings changed while saving. Reload the settings and try again.', 'fluent-boards'), 409);
            }
            $this->persistSettings($enabled, $digestMinutes, $credentials);
        } finally {
            $release();
        }
        do_action('fluent_boards/slack_settings_updated');
        return $this->displaySettings();
    }

    /** Commit the delivery settings and the credentials that determine link validity together. */
    private function persistSettings($enabled, $digestMinutes, array $credentials = [])
    {
        try {
            $rowId = App::getInstance('db')->transaction(function () use ($enabled, $digestMinutes, $credentials) {
                $row = fluent_boards_update_option(self::SETTINGS, ['enabled' => $enabled, 'digest_minutes' => $digestMinutes]);
                // Keep the row selected by the option helper and remove older duplicate records.
                if ($row->id) {
                    Meta::where('object_type', 'option')->where('key', self::SETTINGS)->where('id', '!=', $row->id)->delete();
                }
                $stored = fluent_boards_get_option(self::SETTINGS, []);
                if (($stored['enabled'] ?? null) !== $enabled || ($stored['digest_minutes'] ?? null) !== $digestMinutes) {
                    throw new RuntimeException(__('Could not save the Slack settings. Please try again.', 'fluent-boards'));
                }
                if ($credentials) {
                    $encrypted = Helper::encryptFields($credentials, self::SECRET_FIELDS);
                    update_option(self::CREDENTIALS, $encrypted, false);
                    if (get_option(self::CREDENTIALS) !== $encrypted) {
                        throw new RuntimeException(__('Could not save the Slack credentials. Please try again.', 'fluent-boards'));
                    }
                }
                return (int) $row->id;
            });
            update_option(self::SETTINGS_ROW_ID, $rowId, false);
        } finally {
            // WordPress updates the option cache independently of the database transaction.
            if ($credentials) {
                wp_cache_delete(self::CREDENTIALS, 'options');
            }
        }
    }

    public function profile($userId)
    {
        $settings = $this->displaySettings();
        $connection = $this->connection($userId);
        return [
            'enabled' => $settings['enabled'] && $settings['configured'],
            'configured' => $settings['configured'],
            'team_name' => $settings['team_name'],
            'connected' => (bool) $connection,
            'account' => $connection['account'] ?? '',
            'notifications_enabled' => !empty($connection['enabled']),
            'last_error' => $connection['last_error'] ?? '',
            'preferences' => $this->eventPreferences($userId),
        ];
    }

    /**
     * Slack user data is per site: on multisite every site has its own bot, generation and
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

    private function setMeta($userId, $key, $value)
    {
        update_user_meta(absint($userId), $this->metaKey($key), wp_slash($value));
        return $this->getMeta($userId, $key) === $value;
    }

    private function deleteMeta($userId, $key)
    {
        delete_user_meta(absint($userId), $this->metaKey($key));
    }

    private function connection($userId, $generation = null)
    {
        $stored = $this->getMeta($userId, self::CONNECTION);
        $generation = $generation ?? $this->settings()['generation'] ?? '';
        return !empty($stored['member_id']) && $generation && $generation === ($stored['generation'] ?? '') ? $stored : [];
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
        $previous = $original;
        $original['enabled'] = (bool) $enabled;
        $original['last_error'] = (string) $lastError;
        // update_user_meta() can insert after a disconnect, even with a previous value.
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND BINARY meta_value = %s",
            maybe_serialize($original), $userId, $this->metaKey(self::CONNECTION), maybe_serialize($previous)
        ));
        wp_cache_delete(absint($userId), 'user_meta');
        return $updated > 0;
    }

    /** Serialize local Slack account and preference changes across requests, releasing the lock before any HTTP call. */
    private function withUserLock($userId, callable $operation)
    {
        $release = $this->acquireLock('user:' . absint($userId), 5);
        if (!$release) {
            throw new RuntimeException(__('Slack connection is busy. Please try again.', 'fluent-boards'));
        }
        try {
            wp_cache_delete(absint($userId), 'user_meta');
            return $operation();
        } finally {
            $release();
        }
    }

    /** Issue a short-lived, single-use state secret and return the Slack authorize URL. */
    public function beginConnection($userId)
    {
        $settings = $this->settings();
        if (!$this->isReady($settings)) {
            throw new RuntimeException(__('Ask an administrator to set up Slack in Settings first.', 'fluent-boards'));
        }
        $state = wp_generate_password(40, false, false);
        $hash = hash('sha256', $state);
        $tokenKey = self::TOKEN_PREFIX . $hash;
        $this->withUserLock($userId, function () use ($userId, $settings, $hash, $tokenKey) {
            $this->cancelPending($userId);
            $pending = ['user_id' => absint($userId), 'generation' => $settings['generation']];
            set_transient($tokenKey, $pending, 10 * MINUTE_IN_SECONDS);
            if (get_transient($tokenKey) !== $pending || !$this->setMeta($userId, self::PENDING, $hash)) {
                delete_transient($tokenKey);
                delete_user_meta(absint($userId), $this->metaKey(self::PENDING), $hash);
                throw new RuntimeException(__('Could not start the Slack connection. Please try again.', 'fluent-boards'));
            }
        });

        return ['url' => 'https://slack.com/openid/connect/authorize?' . http_build_query([
            'client_id' => $settings['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => 'openid profile',
            'team' => $settings['team_id'],
            'state' => $state,
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
        // The state must belong to this browser's user, so nobody can link their Slack to another account.
        if (!$userId || !is_array($pending) || absint($pending['user_id'] ?? 0) !== $userId) {
            return 'expired';
        }
        // Claim once. Keep the claim during the HTTP exchange so a later operation can cancel it.
        $claim = 'claimed:' . $hash;
        $claimed = $this->withUserLock($userId, function () use ($userId, $hash, $claim) {
            if ($this->getMeta($userId, self::PENDING) !== $hash) {
                return false;
            }
            return $this->setMeta($userId, self::PENDING, $claim);
        });
        delete_transient(self::TOKEN_PREFIX . $hash);
        if (!$claimed) {
            return 'expired';
        }
        if ($error) {
            return 'denied';
        }
        if (!$this->isReady($settings) || ($pending['generation'] ?? '') !== ($settings['generation'] ?? '')
            || !PermissionManager::isFluentBoardsUser($userId)) {
            return 'expired';
        }
        if (!is_string($code) || !preg_match('/^[A-Za-z0-9._-]{1,512}$/D', $code)) {
            return 'failed';
        }

        try {
            $token = $this->request('openid.connect.token', [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'client_id' => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
            ], '');
            if (empty($token['access_token'])) {
                return 'failed';
            }
            $user = $this->request('openid.connect.userInfo', [], $token['access_token']);
            if (($user['https://slack.com/team_id'] ?? '') !== $settings['team_id']) {
                return 'wrong_workspace';
            }
            $member = $user['https://slack.com/user_id'] ?? '';
            if (!preg_match('/^[UW][A-Z0-9]+$/D', $member)) {
                return 'failed';
            }
        } catch (RuntimeException $exception) {
            return 'failed';
        }

        $connection = [
            'link_id' => wp_generate_uuid4(),
            'member_id' => $member,
            'account' => sanitize_text_field($user['name'] ?? $member),
            'generation' => $settings['generation'],
            'enabled' => true,
            'last_error' => '',
        ];
        try {
            $this->postMessage($connection['member_id'], __('Slack is connected. Notifications from Fluent Boards will appear here.', 'fluent-boards'), [], $settings);
        } catch (RuntimeException $exception) {
            // Keep the link so the member can fix their privacy settings and send a test.
            $connection['last_error'] = $exception->getMessage();
        }
        $result = $this->withUserLock($userId, function () use ($userId, $connection, $claim) {
            if ($this->getMeta($userId, self::PENDING) !== $claim) {
                return 'expired';
            }
            $this->deleteMeta($userId, self::PENDING);
            return App::getInstance('db')->transaction(function () use ($userId, $connection) {
                // A replacement must commit either before this check or after the link write.
                global $wpdb;
                $current = maybe_unserialize($wpdb->get_var($wpdb->prepare(
                    "SELECT option_value FROM $wpdb->options WHERE option_name = %s FOR UPDATE", self::CREDENTIALS
                )));
                if (($current['generation'] ?? '') !== $connection['generation']) {
                    return 'expired';
                }
                return $this->setMeta($userId, self::CONNECTION, $connection) ? 'connected' : 'failed';
            });
        });
        if ($result !== 'connected') {
            return $result;
        }
        do_action('fluent_boards/slack_account_connected', $userId);
        return 'connected';
    }

    /** Where the browser goes after the callback; the account page reads the result code. */
    public function accountUrl($userId, $result)
    {
        return fluent_boards_page_url() . 'member/' . absint($userId) . '/account?fbs_slack=' . rawurlencode($result);
    }

    public function disconnect($userId)
    {
        $this->withUserLock($userId, function () use ($userId) {
            $this->cancelPending($userId);
            $this->deleteMeta($userId, self::CONNECTION);
            wp_cache_delete(absint($userId), 'user_meta');
            if (metadata_exists('user', $userId, $this->metaKey(self::CONNECTION))
                || metadata_exists('user', $userId, $this->metaKey(self::PENDING))) {
                throw new RuntimeException(__('Could not disconnect Slack. Please try again.', 'fluent-boards'));
            }
        });
        do_action('fluent_boards/slack_account_disconnected', $userId);
        return $this->profile($userId);
    }

    public function updatePreference($userId, $enabled)
    {
        $connection = $this->connection($userId);
        if (!$connection) {
            throw new RuntimeException(__('Connect your Slack account first.', 'fluent-boards'));
        }
        $enabled = in_array($enabled, [true, 1, '1', 'true'], true);
        if (!$this->saveConnectionState($userId, $connection, $enabled, $connection['last_error'])) {
            throw new RuntimeException(__('Could not save the Slack notification preferences. Please try again.', 'fluent-boards'));
        }
        do_action('fluent_boards/slack_preference_updated', $userId);
        return $this->profile($userId);
    }

    public function sendTest($userId)
    {
        $connection = $this->connection($userId);
        $settings = $this->settings();
        if (!$connection || !$this->isReady($settings) || $connection['generation'] !== $settings['generation']) {
            throw new RuntimeException(__('Connect your Slack account and enable the integration first.', 'fluent-boards'));
        }
        try {
            $this->postMessage($connection['member_id'], __('Slack is connected. Notifications from Fluent Boards will appear here.', 'fluent-boards'), [], $settings);
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
        return is_array($override) && $override ? $override : null;
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
        $sameAsGlobal = in_array($sameAsGlobal, [true, 1, '1', 'true'], true);
        if (!$sameAsGlobal && !is_array($data)) {
            throw new RuntimeException(__('Invalid notification preferences.', 'fluent-boards'));
        }
        $this->withUserLock($userId, function () use ($userId, $boardId, $sameAsGlobal, $data) {
            $preferences = $sameAsGlobal ? [] : $this->normalizeEvents(array_replace($this->eventPreferences($userId, $boardId), $data));
            if (!$this->setMeta($userId, self::BOARD_PREFERENCES . '_' . $boardId, $preferences)) {
                throw new RuntimeException(__('Could not save the board notification preferences. Please try again.', 'fluent-boards'));
            }
        });
        do_action('fluent_boards/slack_preference_updated', $userId);
        return $this->boardPreferences($userId, $boardId);
    }

    public function updateEventPreferences($userId, $data)
    {
        if (!is_array($data)) {
            throw new RuntimeException(__('Invalid notification preferences.', 'fluent-boards'));
        }
        $this->withUserLock($userId, function () use ($userId, $data) {
            $preferences = $this->normalizeEvents(array_replace($this->eventPreferences($userId), $data));
            if (!$this->setMeta($userId, self::PREFERENCES, $preferences)) {
                throw new RuntimeException(__('Could not save the Slack notification preferences. Please try again.', 'fluent-boards'));
            }
        });
        do_action('fluent_boards/slack_preference_updated', absint($userId));
        return $this->profile($userId);
    }

    /** Queue one background delivery per eligible user, independently of email and Telegram. */
    public function queueNotification($event, Task $task, $recipientIds = null, $commentId = 0)
    {
        if (!in_array($event, self::EVENTS, true)) {
            return;
        }
        $settings = $this->settings();
        if (!$this->isReady($settings)) {
            return;
        }
        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        $boardId = (int) ($task->board_id ?: ($parent ? $parent->board_id : 0));
        $recipientIds = $recipientIds === null ? $task->watchers()->pluck('users.ID')->all() : (array) $recipientIds;
        $recipientIds = array_diff(array_unique(array_filter(array_map('absint', $recipientIds))), [get_current_user_id()]);
        $recipientIds = PermissionManager::getUserIdsWithBoardAccess($boardId, $recipientIds);
        // Personal notification pauses (all boards or this board) apply to every channel.
        $recipientIds = (new NotificationService())->filterUnmutedUserIds($boardId, $recipientIds);
        foreach ($recipientIds as $userId) {
            $connection = $this->connection($userId, $settings['generation'] ?? '');
            if (empty($connection['enabled']) || !$this->eventPreferences($userId, $boardId)[$event]) {
                continue;
            }
            $this->addToDigest($userId, $event, $task->id, $commentId, $boardId, $connection, get_current_user_id(), $settings['digest_minutes']);
        }
    }

    /**
     * Record one activity for a member and make sure their digest job is scheduled. Each
     * activity is its own row, so concurrent requests never overwrite each other.
     */
    private function addToDigest($userId, $event, $taskId, $commentId, $boardId, array $connection, $actorId, $digestMinutes)
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
                'generation' => $connection['generation'],
                'link_id' => $connection['link_id'],
            ],
        ]);
        // The window is fixed from the first activity, so busy tasks never hold DMs back indefinitely.
        if (!$this->hasPendingDigest($userId)) {
            $this->scheduleDigest($userId, 0, $digestMinutes * MINUTE_IN_SECONDS);
        }
    }

    private function batchItems($userId, $batch)
    {
        $rows = Meta::where('object_type', self::BATCH_TYPE)->where('object_id', absint($userId))
            ->where('key', 'LIKE', $batch . ':%')->orderBy('id', 'ASC')->get();
        $items = [];
        foreach ($rows as $row) {
            $value = is_array($row->value) ? $row->value : [];
            $items[] = array_merge($value, ['event' => substr($row->key, strlen($batch) + 1), 'row_id' => (int) $row->id]);
        }
        return $items;
    }

    protected function digestDelay()
    {
        return $this->settings()['digest_minutes'] * MINUTE_IN_SECONDS;
    }

    protected function waitSeconds($userId)
    {
        $deadline = max((int) get_transient(self::WAIT_PREFIX . $userId), $this->postDeadline($this->settings()));
        return max(0, $deadline - time());
    }

    /** Skip the exhausted chunk while giving later chunks their own retry budget. */
    protected function finishExhaustedBatch($userId, $batch)
    {
        $lastAttempt = self::MAX_RETRIES + self::RECOVERY_ATTEMPTS_AFTER_RETRIES;
        if (!$this->scheduleDigest($userId, $lastAttempt, self::RECOVERY_SECONDS, $batch, self::RECOVERY_HOOK)) {
            return;
        }
        $retrying = false;
        $recoveryAttempt = $this->sendBatch($userId, $lastAttempt - 1, $batch, $retrying);
        if (!$retrying) {
            $this->cancelRecovery($userId, $recoveryAttempt, $batch);
        }
    }

    protected function sendBatch($userId, $attempt, $batch, &$retrying)
    {
        $recoveryAttempt = $attempt + 1;
        $items = $this->batchItems($userId, $batch);
        if (!$items) {
            return $recoveryAttempt;
        }
        // A long-running worker may have cached this user before a disconnect or relink.
        wp_cache_delete(absint($userId), 'user_meta');
        $connection = $this->connection($userId);
        if (!$this->isReady($this->settings()) || empty($connection['enabled'])) {
            $this->acknowledge($items);
            return $recoveryAttempt;
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
        $queue = array_chunk(array_keys($groups), 10);
        while ($queue) {
            $taskIds = array_shift($queue);
            // Disconnects and relinks can complete while a previous message is in flight.
            wp_cache_delete($userId, 'user_meta');
            wp_cache_delete(self::CREDENTIALS, 'options');
            $settings = $this->settings();
            $current = $this->connection($userId);
            if (empty($current['enabled']) || !$this->isReady($settings) || $settings['generation'] !== $connection['generation']
                || $current['generation'] !== $connection['generation'] || $current['link_id'] !== $connection['link_id']
                || $current['member_id'] !== $connection['member_id']) {
                $this->acknowledge($items);
                return $recoveryAttempt;
            }
            // Build each message from current board access, pauses, preferences and task state.
            $summaries = [];
            foreach ($taskIds as $taskId) {
                $summary = $this->buildTaskSummary($userId, $taskId, $groups[$taskId]);
                if ($summary) {
                    $summaries[$taskId] = $summary;
                } else {
                    $this->acknowledge($groups[$taskId]);
                }
            }
            if (!$summaries) {
                continue;
            }
            $chunk = array_keys($summaries);
            $chunkItems = [];
            foreach ($chunk as $taskId) {
                $chunkItems = array_merge($chunkItems, $groups[$taskId]);
            }
            try {
                // An exhausted recovery job skips only the chunk whose worker repeatedly died.
                if ($attempt <= self::MAX_RETRIES) {
                    $this->postTaskSummaries($connection['member_id'], $summaries, $settings);
                }
                $this->acknowledge($chunkItems);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
                $code = $exception->getCode();
                if ($code === self::DEFERRED_BY_COOLDOWN) {
                    if ($this->scheduleDigest($userId, $attempt, $this->retryAfter, $batch)) {
                        $this->cancelRecovery($userId, $recoveryAttempt, $batch);
                    }
                    $retrying = true;
                    return $recoveryAttempt;
                }
                if ($code === 429 || ($code >= 500 && $code < 600) || !$code) {
                    // This message and later ones stay in the batch for its own retry.
                    if ($attempt < self::MAX_RETRIES) {
                        if ($this->scheduleDigest($userId, $attempt + 1, max($this->retryAfter, 60 * pow(3, $attempt)), $batch)) {
                            $this->cancelRecovery($userId, $recoveryAttempt, $batch);
                        }
                        $retrying = true;
                        break;
                    }
                    $this->acknowledge($chunkItems);
                } elseif (!in_array($code, [400, 413], true)) {
                    $this->acknowledge($items);
                    break;
                } elseif (count($chunk) > 1) {
                    // Isolate a rejected task without dropping the rest of the message.
                    $queue = array_merge(array_map(function ($index) {
                        return [$index];
                    }, $chunk), $queue);
                    continue;
                } else {
                    $this->acknowledge($chunkItems);
                }
            }

            // The next chunk starts with a fresh retry budget, including its crash recovery.
            if ($attempt && $queue) {
                $attempt = 0;
                if ($this->scheduleDigest($userId, 1, self::RECOVERY_SECONDS, $batch, self::RECOVERY_HOOK)) {
                    $this->cancelRecovery($userId, $recoveryAttempt, $batch);
                    $recoveryAttempt = 1;
                }
            }
        }
        $this->saveConnectionState($userId, $connection, $connection['enabled'], $lastError);
        return $recoveryAttempt;
    }

    private function postTaskSummaries($memberId, array $summaries, array $settings)
    {
        $blocks = [];
        $fallback = [];
        foreach ($summaries as $summary) {
            $text = implode("\n", [$summary['title'], $summary['description'], implode(' | ', $summary['fields'])]);
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'plain_text', 'text' => $text],
                'accessory' => [
                    'type' => 'button',
                    'text' => ['type' => 'plain_text', 'text' => __('View task', 'fluent-boards')],
                    'url' => $summary['url'],
                ],
            ];
            $fallback[] = $text;
        }
        $this->postMessage($memberId, $this->cut(implode("\n\n", $fallback), 4000), $blocks, $settings);
    }

    private function postDeadlineKey(array $settings)
    {
        return self::POST_WAIT_PREFIX . md5(($settings['client_id'] ?? '') . ':' . ($settings['team_id'] ?? ''));
    }

    /** Read uncached so a running worker observes another worker's most recent 429. */
    private function postDeadline(array $settings)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = %s", $this->postDeadlineKey($settings)
        ));
    }

    private function postMessage($memberId, $text, array $blocks, array $settings)
    {
        $delay = $this->postDeadline($settings) - time();
        if ($delay > 0) {
            $this->retryAfter = $delay;
            throw new RuntimeException(__('Slack requested a delivery delay.', 'fluent-boards'), self::DEFERRED_BY_COOLDOWN);
        }
        $payload = ['channel' => $memberId, 'text' => htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8'), 'mrkdwn' => 'false',
            'parse' => 'none', 'unfurl_links' => 'false', 'unfurl_media' => 'false'];
        if ($blocks) {
            $payload['blocks'] = wp_json_encode($blocks);
        }
        try {
            $this->request('chat.postMessage', $payload, $settings['bot_token']);
        } catch (RuntimeException $exception) {
            if ($exception->getCode() === 429) {
                // Concurrent responses may only extend the app/workspace deadline.
                global $wpdb;
                $deadline = time() + $this->retryAfter;
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->base_prefix}options (option_name, option_value, autoload) VALUES (%s, %d, 'no')
                     ON DUPLICATE KEY UPDATE option_value = GREATEST(CAST(option_value AS UNSIGNED), %d)",
                    $this->postDeadlineKey($settings), $deadline, $deadline
                ));
            }
            throw $exception;
        }
    }

    /** Recheck each activity for one task and describe what is still true, or return null. */
    private function buildTaskSummary($userId, $taskId, array $items)
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
        foreach ($items as $item) {
            $event = $item['event'];
            if ((int) ($item['board_id'] ?? 0) !== (int) $board->id || empty($preferences[$event])) {
                continue;
            }
            if ($event === 'task_archived' ? !$task->archived_at : $archived) {
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
        $firstChange = null;
        foreach ($items as $item) {
            if (in_array($item['event'], ['add_assignee', 'remove_assignee'], true) && (int) ($item['board_id'] ?? 0) === (int) $board->id) {
                $firstChange = $item['event'];
                break;
            }
        }
        if ($firstChange) {
            $wasAssignee = $firstChange === 'remove_assignee';
            $isAssignee = $task->assignees()->where('users.ID', absint($userId))->exists();
            $kept = $isAssignee ? 'add_assignee' : 'remove_assignee';
            $keptItems = $isAssignee !== $wasAssignee ? ($byEvent[$kept] ?? null) : null;
            unset($byEvent['add_assignee'], $byEvent['remove_assignee']);
            if ($keptItems) {
                $byEvent = array_merge([$kept => $keptItems], $byEvent);
            }
        }
        if (!$byEvent) {
            return null;
        }

        $lines = [];
        foreach ($byEvent as $event => $eventItems) {
            $lines[] = '- ' . $this->activityLine($event, $eventItems, $task);
        }

        $fields = [['name' => __('Board', 'fluent-boards'), 'value' => $this->plain($board->title, 200)]];
        if ($task->stage) {
            $fields[] = ['name' => __('Stage', 'fluent-boards'), 'value' => $this->plain($task->stage->title, 200)];
        }
        if ($task->due_at) {
            $fields[] = [
                'name' => __('Due', 'fluent-boards'),
                'value' => $this->plain(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $task->due_at), 200),
            ];
        }
        $url = fluent_boards_page_url() . 'boards/' . (int) $board->id . '/tasks/' . (int) ($task->parent_id ?: $task->id);
        return [
            'title' => $this->plain($task->title, 256),
            'url' => esc_url_raw($url),
            'description' => $this->cut(implode("\n", $lines), 1500),
            'fields' => array_map(function ($field) {
                return $field['name'] . ': ' . $field['value'];
            }, $fields),
        ];
    }

    /** One sentence per kind of activity; repeated activity is counted, not repeated. */
    private function activityLine($event, array $items, $task)
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
                /* translators: %1$s is a person's name, %2$s is the stage name */
                return sprintf(__('%1$s moved this task to %2$s.', 'fluent-boards'), $last, $task->stage ? $this->plain($task->stage->title, 200) : '-');
            case 'due_date_update':
                /* translators: %s is the name of the person who changed the dates */
                return sprintf(__('%s updated the task dates.', 'fluent-boards'), $last);
            case 'priority_change':
                if ((string) $task->priority === '') {
                    /* translators: %s is the name of the person who cleared the priority */
                    return sprintf(__('%s cleared the task priority.', 'fluent-boards'), $last);
                }
                /* translators: %1$s is a person's name, %2$s is the task priority */
                return sprintf(__('%1$s changed the priority to %2$s.', 'fluent-boards'), $last, $this->plain($task->priority, 80));
            case 'description_update':
                /* translators: %s is the name of the person who edited the description */
                return sprintf(__('%s updated the task description.', 'fluent-boards'), $last);
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
        if (!in_array($comment->type, ['comment', 'reply'], true)) {
            return;
        }
        $task = Task::find($comment->task_id);
        if (!$task) {
            return;
        }
        $recipients = $mentionedIds;
        if ($mentionedIds === null) {
            if ($comment->type === 'reply') {
                $parent = Comment::find($comment->parent_id);
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

    private function plain($text, $length)
    {
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
        return $text !== '' ? $this->cut($text, $length) : '-';
    }

    private function cut($text, $length)
    {
        return mb_strlen($text, 'UTF-8') <= $length ? $text : mb_substr($text, 0, $length - 3, 'UTF-8') . '...';
    }

    /** Fixed API host, bounded timeout, and generic errors keep credentials out of responses. */
    private function request($method, array $body, $token)
    {
        $response = wp_remote_post(self::API . $method, [
            'timeout' => 12,
            'redirection' => 0,
            'headers' => $token ? ['Authorization' => 'Bearer ' . $token] : [],
            'body' => $body,
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException(__('Could not reach Slack. Please try again.', 'fluent-boards'));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($status === 200 && !empty($data['ok'])) {
            return $data;
        }
        $error = $data['error'] ?? '';
        if ($status === 429 || $error === 'ratelimited') {
            $this->retryAfter = max(1, (int) wp_remote_retrieve_header($response, 'retry-after'));
            throw new RuntimeException(__('Slack requested a delivery delay.', 'fluent-boards'), 429);
        }
        if (in_array($error, ['invalid_blocks', 'msg_too_long'], true)) {
            throw new RuntimeException(__('Slack rejected the message format.', 'fluent-boards'), 400);
        }
        if (in_array($error, ['invalid_auth', 'token_revoked', 'missing_scope'], true)) {
            throw new RuntimeException(__('Ask an administrator to check the Slack bot token and permissions.', 'fluent-boards'), 403);
        }
        if ($status === 200) {
            $status = (!$data || in_array($error, ['fatal_error', 'internal_error', 'service_unavailable'], true)) ? 503 : 422;
        }
        throw new RuntimeException(__('Slack could not complete the request. Please try again.', 'fluent-boards'), $status);
    }
}
