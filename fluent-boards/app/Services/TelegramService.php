<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Services\PermissionManager;
use Closure;
use RuntimeException;
use ActionScheduler;
use ActionScheduler_AsyncRequest_QueueRunner;
use Throwable;

/** Private Telegram account linking and independent task notifications. */
class TelegramService extends NotificationDigestService
{
    /**
     * Whether core owns Telegram. A pre-move Pro build keeps ownership (and its own routes)
     * until it defines FLUENT_BOARDS_TELEGRAM_CORE_COMPAT. Gates the core Telegram routes and the
     * per-board preferences UI flag (TelegramHandler mirrors the same check).
     */
    public static function coreOwnsTelegram()
    {
        return !defined('FLUENT_BOARDS_PRO_VERSION')
            || defined('FLUENT_BOARDS_TELEGRAM_CORE_COMPAT')
            || !class_exists('FluentBoardsPro\\App\\Services\\TelegramService');
    }

    const PREFERENCES = '_fbs_telegram_preferences';
    const BOARD_PREFERENCES = '_fbs_telegram_board_preferences';
    const NOTIFICATION_HOOK = 'fluent_boards/telegram_deliver_notification';
    const DIGEST_HOOK = 'fluent_boards/telegram_send_digest';
    const RECOVERY_HOOK = 'fluent_boards/telegram_recover_digest';
    const DIGEST_TYPE = 'telegram_digest';
    const BATCH_TYPE = 'telegram_digest_batch';
    // A claimed batch whose job dies is picked up again after this long.
    const RECOVERY_SECONDS = 900;
    // Set from a 429; no digest for the member is sent before it.
    const WAIT_PREFIX = 'fbs_telegram_wait_';
    const DIGEST_MINUTES = [0, 1, 2, 5, 10, 15];
    const DEFAULT_DIGEST_MINUTES = 2;
    const MAX_RETRIES = 3;
    const DIGEST_MESSAGE_BYTES = 4000;
    const EVENTS = Constant::CHAT_NOTIFICATION_EVENTS;

    const SETTINGS = 'telegram_settings';
    const SHARED_SETTINGS = '_fluent_telegram_creds';
    const SECRET_FIELDS = ['token'];
    const SHARED_CONNECTION = '_fluent_telegram_connection';
    const CONNECTION = '_fbs_telegram_connection';
    const PENDING = '_fluent_telegram_pending';
    const TOKEN_PREFIX = 'fluent_telegram_link_';
    const OFFSET = '_fluent_telegram_offset';
    const LOCK = '_fluent_telegram_poll_lock';
    private $retryAfter = 60;

    /** Wake the existing queue after a due notification is persisted, including REST requests. */
    public function notificationQueued($actionId)
    {
        if (self::$notificationWakeScheduled || !class_exists(ActionScheduler_AsyncRequest_QueueRunner::class)) {
            return;
        }
        try {
            $action = ActionScheduler::store()->fetch_action(absint($actionId));
            $scheduledAt = $action->get_schedule()->get_date();
            if ($action->get_group() !== 'fluent-boards' || $action->get_hook() !== self::NOTIFICATION_HOOK
                || ($scheduledAt && $scheduledAt->getTimestamp() > time())) {
                return;
            }
            $settings = $this->settings();
            if (empty($settings['enabled']) || empty($settings['token'])) {
                return;
            }
            self::$notificationWakeScheduled = true;
            add_action('shutdown', [$this, 'wakeNotificationQueue'], 9);
        } catch (Throwable $exception) {
            // The persisted job remains available to the normal scheduler.
            error_log('FluentBoards could not prepare the notification queue wake-up');
        }
    }

    public function settings($freshCredentials = false)
    {
        $local = (array) fluent_boards_get_option(self::SETTINGS, []);
        $options = [
            'enabled' => !empty($local['enabled']),
            'digest_minutes' => $this->normalizeDigestMinutes($local['digest_minutes'] ?? self::DEFAULT_DIGEST_MINUTES),
        ];
        $shared = $this->sharedCredentials($freshCredentials);
        // Import the previous Boards-only bot once, without overwriting another product's bot.
        if ($shared === false && !empty($local['token'])) {
            $shared = array_intersect_key($local, array_flip(['token', 'bot_id', 'username', 'generation']));
            add_option(self::SHARED_SETTINGS, Helper::encryptFields($shared, self::SECRET_FIELDS), '', false);
            $shared = $this->sharedCredentials($freshCredentials);
        }
        if (is_array($shared) && !empty($shared['token']) && !empty($local['token'])) {
            fluent_boards_update_option(self::SETTINGS, $options);
        }
        $merged = array_merge((array) $shared, $options);
        // Keep the saved switch so an administrator's choice survives a lost key, but only
        // report Telegram as usable while the bot token can actually be read.
        $merged['saved_enabled'] = $options['enabled'];
        $merged['enabled'] = $options['enabled'] && !empty($merged['token']);
        $merged['credentials_unreadable'] = is_array($shared) && empty($shared['token']);
        return $merged;
    }

    /** Read only the credential row when a delivery worker needs to observe a bot rotation. */
    private function sharedCredentials($fresh)
    {
        if (!$fresh) {
            return $this->decryptShared(get_option(self::SHARED_SETTINGS, false));
        }

        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SHARED_SETTINGS
        ));
        return $value === null ? false : $this->decryptShared(maybe_unserialize($value));
    }

    private function decryptShared($shared)
    {
        return is_array($shared) ? Helper::decryptFields($shared, self::SECRET_FIELDS) : $shared;
    }

    private function normalizeDigestMinutes($minutes)
    {
        $minutes = is_numeric($minutes) ? (int) $minutes : -1;
        return in_array($minutes, self::DIGEST_MINUTES, true) ? $minutes : self::DEFAULT_DIGEST_MINUTES;
    }

    /** Return only public bot information, never its credential. */
    public function displaySettings()
    {
        $settings = $this->settings();
        return [
            'configured' => !empty($settings['token']),
            'credentials_unreadable' => !empty($settings['credentials_unreadable']),
            'digest_minutes' => $settings['digest_minutes'],
            // The administrator's saved switch; 'available' is whether Telegram can work right now.
            'enabled' => !empty($settings['saved_enabled']),
            'available' => !empty($settings['enabled']),
            'username' => $settings['username'] ?? '',
        ];
    }

    /** Validate credentials before replacing a working bot; existing webhooks are never removed. */
    public function saveSettings(array $data)
    {
        $settings = $this->settings();
        $enabled = in_array($data['enabled'] ?? false, [true, 1, '1', 'true'], true);
        $options = ['enabled' => $enabled, 'digest_minutes' => $this->normalizeDigestMinutes($data['digest_minutes'] ?? $settings['digest_minutes'])];
        $token = isset($data['token']) && is_string($data['token']) ? trim($data['token']) : '';
        if (!$token && !empty($settings['token']) && !$enabled) {
            $settings['enabled'] = false;
            fluent_boards_update_option(self::SETTINGS, $options);
            do_action('fluent_boards/telegram_settings_updated');
            return $this->displaySettings();
        }
        $token = $token ?: ($settings['token'] ?? '');
        if (strlen($token) > 256 || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new RuntimeException(__('Enter a valid bot token from BotFather.', 'fluent-boards'));
        }
        $bot = $this->request('getMe', [], $token);
        $webhook = $this->request('getWebhookInfo', [], $token);
        if (!empty($webhook['url'])) {
            throw new RuntimeException(__('This bot is connected to another service. Create a dedicated bot for Fluent Boards in BotFather.', 'fluent-boards'));
        }
        if (empty($bot['is_bot']) || empty($bot['username']) || empty($bot['id'])) {
            throw new RuntimeException(__('Telegram did not return a valid bot.', 'fluent-boards'));
        }
        // Changing bots invalidates old user links and outstanding connection requests.
        $sameBot = (string) ($settings['bot_id'] ?? '') === (string) $bot['id'];
        update_option(self::SHARED_SETTINGS, Helper::encryptFields([
            'token' => $token,
            'bot_id' => (string) $bot['id'],
            'username' => sanitize_text_field($bot['username']),
            'generation' => $sameBot && !empty($settings['generation']) ? $settings['generation'] : wp_generate_password(32, false, false),
        ], self::SECRET_FIELDS), false);
        fluent_boards_update_option(self::SETTINGS, $options);
        if (!$sameBot) {
            if (!empty($settings['generation'])) {
                delete_option(self::OFFSET . '_' . $settings['generation']);
            }
        }
        do_action('fluent_boards/telegram_settings_updated');
        return $this->displaySettings();
    }

    /** Expose connection state and whether automatic linking checks should continue. */
    public function profile($userId)
    {
        $settings = $this->displaySettings();
        $connection = $this->connection($userId);
        $pendingHash = get_user_meta(absint($userId), self::PENDING, true);
        $pending = is_string($pendingHash) && $pendingHash ? get_transient(self::TOKEN_PREFIX . $pendingHash) : false;
        return array_merge($settings, [
            'pending' => !$connection && !empty($settings['available']) && is_array($pending)
                && ($pending['generation'] ?? '') === ($this->settings()['generation'] ?? ''),
            // Member controls follow availability, not the saved preference.
            'enabled' => !empty($settings['available']),
            'connected' => (bool) $connection,
            'account' => $connection['account'] ?? '',
            'notifications_enabled' => !empty($connection['enabled']),
            'last_error' => $connection['last_error'] ?? '',
            'preferences' => $this->eventPreferences($userId),
        ]);
    }

    private function connection($userId, $settings = null)
    {
        $userId = absint($userId);
        $local = get_user_meta($userId, self::CONNECTION, true);
        $shared = get_user_meta($userId, self::SHARED_CONNECTION, true);
        $settings = $settings ?? $this->settings();
        if (!$shared && is_array($local) && !empty($local['chat_id'])
            && ($local['generation'] ?? '') === ($settings['generation'] ?? '')) {
            $shared = array_intersect_key($local, array_flip(['chat_id', 'generation', 'account']));
            $shared['telegram_user_id'] = $shared['chat_id'];
            add_user_meta($userId, self::SHARED_CONNECTION, $shared, true);
            $shared = get_user_meta($userId, self::SHARED_CONNECTION, true);
        }
        if (is_array($local) && !empty($local['chat_id']) && $shared) {
            $local = ['enabled' => $local['enabled'] ?? true, 'last_error' => $local['last_error'] ?? ''];
            update_user_meta($userId, self::CONNECTION, $local);
        }
        if (!is_array($shared) || empty($settings['generation'])
            || ($shared['generation'] ?? '') !== $settings['generation']) {
            return [];
        }
        return array_merge($shared, [
            'enabled' => is_array($local) ? ($local['enabled'] ?? true) : true,
            'last_error' => is_array($local) ? ($local['last_error'] ?? '') : '',
        ]);
    }

    /** Issue a short-lived, single-use secret; the browser never submits a chat ID. */
    public function beginConnection($userId)
    {
        $settings = $this->settings();
        if (empty($settings['enabled'])) {
            throw new RuntimeException(__('Ask an administrator to enable Telegram in Settings first.', 'fluent-boards'));
        }
        $this->cancelPending($userId);
        $token = wp_generate_password(40, false, false);
        $hash = hash('sha256', $token);
        set_transient(self::TOKEN_PREFIX . $hash, [
            'user_id' => absint($userId),
            'generation' => $settings['generation'],
        ], 10 * MINUTE_IN_SECONDS);
        update_user_meta($userId, self::PENDING, $hash);
        return ['url' => 'https://t.me/' . rawurlencode($settings['username']) . '?start=' . $token];
    }

    private function cancelPending($userId)
    {
        $hash = get_user_meta($userId, self::PENDING, true);
        if (is_string($hash) && $hash) {
            delete_transient(self::TOKEN_PREFIX . $hash);
        }
        delete_user_meta($userId, self::PENDING);
    }

    /** Consume updates centrally so simultaneous profile connections cannot steal each other's updates. */
    public function checkConnection($userId)
    {
        $settings = $this->settings();
        if (empty($settings['enabled']) || !get_user_meta($userId, self::PENDING, true)) {
            return $this->profile($userId);
        }
        $lockTime = (int) get_option(self::LOCK, 0);
        if ($lockTime && $lockTime < time() - 60) {
            delete_option(self::LOCK);
        }
        if (!add_option(self::LOCK, time(), '', false)) {
            return $this->profile($userId);
        }
        $offsetKey = self::OFFSET . '_' . $settings['generation'];
        try {
            $updates = $this->request('getUpdates', [
                'offset' => (int) get_option($offsetKey, 0),
                'limit' => 100,
                'timeout' => 0,
                'allowed_updates' => ['message'],
            ], $settings['token']);
            foreach ($updates as $update) {
                $this->acceptConnection($update['message'] ?? [], $settings);
                // Shared consumers may handle their own updates before the offset advances.
                do_action('fluent_telegram/update_received', $update);
                update_option($offsetKey, (int) $update['update_id'] + 1, false);
            }
        } finally {
            delete_option(self::LOCK);
        }
        $hash = get_user_meta($userId, self::PENDING, true);
        if ($hash && !get_transient(self::TOKEN_PREFIX . $hash)) {
            delete_user_meta($userId, self::PENDING);
            throw new RuntimeException(__('Connection link expired. Click Connect Telegram to try again.', 'fluent-boards'));
        }
        return $this->profile($userId);
    }

    /** Only a private chat carrying the current connection secret can link an account. */
    private function acceptConnection(array $message, array $settings)
    {
        if (($message['chat']['type'] ?? '') !== 'private'
            || empty($message['from']['id'])
            || (string) $message['from']['id'] !== (string) ($message['chat']['id'] ?? '')
            || !preg_match('/^\/start(?:@[A-Za-z0-9_]+)? ([A-Za-z0-9]{40})$/D', $message['text'] ?? '', $matches)) {
            return;
        }
        $hash = hash('sha256', $matches[1]);
        $pending = get_transient(self::TOKEN_PREFIX . $hash);
        if (!$pending || ($pending['generation'] ?? '') !== $settings['generation']) {
            return;
        }
        $userId = absint($pending['user_id']);
        if (get_user_meta($userId, self::PENDING, true) !== $hash || !PermissionManager::isFluentBoardsUser($userId)) {
            return;
        }
        update_user_meta($userId, self::SHARED_CONNECTION, [
            'chat_id' => (string) $message['chat']['id'],
            'telegram_user_id' => (string) $message['from']['id'],
            'generation' => $settings['generation'],
            'account' => sanitize_text_field($message['from']['username'] ?? $message['from']['first_name'] ?? ''),
        ]);
        update_user_meta($userId, self::CONNECTION, [
            'enabled' => true,
            'last_error' => '',
        ]);
        $this->cancelPending($userId);
        do_action('fluent_boards/telegram_account_connected', $userId);
    }

    public function disconnect($userId)
    {
        $this->cancelPending($userId);
        delete_user_meta($userId, self::SHARED_CONNECTION);
        delete_user_meta($userId, self::CONNECTION);
        do_action('fluent_boards/telegram_account_disconnected', $userId);
        return $this->profile($userId);
    }

    public function updatePreference($userId, $enabled)
    {
        $connection = $this->connection($userId);
        if (!$connection) {
            throw new RuntimeException(__('Connect your Telegram account first.', 'fluent-boards'));
        }
        $connection['enabled'] = in_array($enabled, [true, 1, '1', 'true'], true);
        update_user_meta($userId, self::CONNECTION, ['enabled' => $connection['enabled'], 'last_error' => $connection['last_error']]);
        do_action('fluent_boards/telegram_preference_updated', $userId);
        return $this->profile($userId);
    }

    public function sendTest($userId)
    {
        $connection = $this->connection($userId);
        if (!$connection || empty($this->settings()['enabled'])) {
            throw new RuntimeException(__('Connect your Telegram account and enable the integration first.', 'fluent-boards'));
        }
        $originalConnection = $connection;
        $this->request('sendMessage', [
            'chat_id' => $connection['chat_id'],
            'text' => __('Telegram is connected. Notifications from Fluent Boards will appear here.', 'fluent-boards'),
        ]);
        $connection['last_error'] = '';
        if ($this->connection($userId) === $originalConnection) {
            update_user_meta($userId, self::CONNECTION, ['enabled' => $connection['enabled'], 'last_error' => $connection['last_error']]);
        }
    }

    /** Event preferences are separate from email settings and survive disconnecting a bot. A board override wins over the global set. */
    public function eventPreferences($userId, $boardId = 0)
    {
        $override = $boardId ? $this->boardOverride($userId, $boardId) : null;
        return $override !== null ? $this->normalizeEvents($override) : $this->normalizeEvents(get_user_meta(absint($userId), self::PREFERENCES, true));
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

    /** Return the saved board override, or null when the board follows the global preferences. */
    private function boardOverride($userId, $boardId)
    {
        $all = get_user_meta(absint($userId), self::BOARD_PREFERENCES, true);
        $boardId = absint($boardId);
        return is_array($all) && isset($all[$boardId]) && is_array($all[$boardId]) ? $all[$boardId] : null;
    }

    public function boardPreferences($userId, $boardId)
    {
        $profile = $this->profile($userId);
        return [
            'enabled' => $profile['enabled'],
            'configured' => $profile['configured'],
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
        $all = get_user_meta($userId, self::BOARD_PREFERENCES, true);
        $all = is_array($all) ? $all : [];
        $preferences = null;
        if (in_array($sameAsGlobal, [true, 1, '1', 'true'], true)) {
            unset($all[$boardId]);
        } else {
            if (!is_array($data)) {
                throw new RuntimeException(__('Invalid notification preferences.', 'fluent-boards'));
            }
            $preferences = $this->eventPreferences($userId, $boardId);
            foreach (self::EVENTS as $event) {
                if (array_key_exists($event, $data)) {
                    $preferences[$event] = in_array($data[$event], [true, 1, '1', 'true'], true);
                }
            }
            $all[$boardId] = $preferences;
        }
        if ($all) {
            update_user_meta($userId, self::BOARD_PREFERENCES, $all);
        } else {
            delete_user_meta($userId, self::BOARD_PREFERENCES);
        }
        // The meta APIs also return false for an unchanged value or a missing key, so check what was stored instead.
        $stored = $this->boardOverride($userId, $boardId);
        $persisted = $preferences === null ? $stored === null : $stored !== null && $this->normalizeEvents($stored) === $preferences;
        if (!$persisted) {
            throw new RuntimeException(__('Could not save the board notification preferences. Please try again.', 'fluent-boards'));
        }
        do_action('fluent_boards/telegram_preference_updated', $userId);
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
        update_user_meta(absint($userId), self::PREFERENCES, $preferences);
        do_action('fluent_boards/telegram_preference_updated', absint($userId));
        return $this->profile($userId);
    }

    /** Queue a single background delivery per eligible user, independently of email settings. */
    public function queueNotification($event, Task $task, $recipientIds = null, $commentId = 0, $change = [], array $excludedIds = [])
    {
        $settings = $this->settings();
        if (!in_array($event, self::EVENTS, true) || empty($settings['enabled'])) {
            return;
        }
        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        $boardId = (int) ($task->board_id ?: ($parent ? $parent->board_id : 0));
        // Watch membership is shared, but channel preferences are independent.
        if ($recipientIds === null) {
            $recipientIds = $task->watchers()->pluck('users.ID')->all();
            if ($parent && (int) $parent->board_id === $boardId) {
                $recipientIds = array_merge($recipientIds, $parent->watchers()->pluck('users.ID')->all());
            }
        }
        $recipientIds = array_diff(array_unique(array_filter(array_map('absint', $recipientIds))), [get_current_user_id()], $excludedIds);
        $recipientIds = PermissionManager::getUserIdsWithBoardAccess($boardId, $recipientIds);
        // Personal notification pauses (all boards or this board) apply to every channel.
        $recipientIds = (new NotificationService())->filterUnmutedUserIds($boardId, $recipientIds);
        foreach ($recipientIds as $userId) {
            $connection = $this->connection($userId, $settings);
            if (empty($connection['enabled']) || !$this->eventPreferences($userId, $boardId)[$event]) {
                continue;
            }
            if (!$settings['digest_minutes']) {
                as_enqueue_async_action(self::NOTIFICATION_HOOK,
                    [$event, (int) $task->id, absint($commentId), $boardId, (int) $userId, $connection['generation'], 0],
                    'fluent-boards');
                continue;
            }
            if ($change instanceof Closure) {
                $change = $change();
            }
            $this->addToDigest($userId, $event, $task->id, $commentId, $boardId, $connection, $change, $settings['digest_minutes']);
        }
    }

    /**
     * Record one activity for a member and make sure their digest job is scheduled. Each
     * activity is its own row, so concurrent requests never overwrite each other.
     */
    private function addToDigest($userId, $event, $taskId, $commentId, $boardId, array $connection, array $change, $digestMinutes)
    {
        Meta::create([
            'object_type' => self::DIGEST_TYPE,
            'object_id' => absint($userId),
            'key' => '',
            'value' => [
                'event' => $event,
                'actor_id' => get_current_user_id(),
                'change' => $change,
                'task_id' => absint($taskId),
                'comment_id' => absint($commentId),
                'board_id' => absint($boardId),
                'generation' => $connection['generation'],
                'chat_id' => $connection['chat_id'],
            ],
        ]);
        // The window is fixed from the first activity, so busy tasks never hold DMs back indefinitely.
        if (!$this->hasPendingDigest($userId)) {
            $this->scheduleDigest($userId, 0, $digestMinutes * MINUTE_IN_SECONDS);
        }
    }

    protected function digestDelay()
    {
        return $this->settings()['digest_minutes'] * MINUTE_IN_SECONDS;
    }

    protected function waitSeconds($userId)
    {
        return max(0, (int) get_transient(self::WAIT_PREFIX . $userId) - time());
    }

    protected function finishExhaustedBatch($userId, $batch)
    {
        $this->acknowledge($this->batchItems($userId, $batch));
    }

    /** Previously claimed Telegram batches use a bare ID. The shared queue appends a colon. */
    private function batchRows($userId, $batch)
    {
        return Meta::where('object_type', self::BATCH_TYPE)->where('object_id', absint($userId))
            ->whereIn('key', [$batch, $batch . ':']);
    }

    private function batchItems($userId, $batch)
    {
        $rows = $this->batchRows($userId, $batch)->orderBy('id', 'ASC')->get();
        $items = [];
        foreach ($rows as $row) {
            $value = is_array($row->value) ? $row->value : [];
            $items[] = array_merge($value, ['row_id' => (int) $row->id]);
        }
        return $items;
    }

    /** Refresh request-local caches so a long-running worker observes unlinks and bot rotations. */
    private function digestConnection($userId, &$token = null)
    {
        wp_cache_delete($userId, 'user_meta');
        $settings = $this->settings(true);
        $token = $settings['token'] ?? '';
        return $token && !empty($settings['enabled']) ? $this->connection($userId, $settings) : [];
    }

    /** Acknowledge each delivered message separately so retries only collect remaining rows. */
    protected function sendBatch($userId, $attempt, $batch, &$retrying)
    {
        $items = $this->batchItems($userId, $batch);
        if (!$items) {
            return;
        }
        $connection = $this->digestConnection($userId);
        if (empty($connection['enabled'])) {
            $this->acknowledge($items);
            return;
        }

        $groups = [];
        foreach ($items as $item) {
            if ($item['generation'] !== $connection['generation'] || $item['chat_id'] !== $connection['chat_id']) {
                $this->acknowledge([$item]);
                continue;
            }
            $groups[$item['task_id']][] = $item;
        }

        $cards = [];
        foreach ($groups as $taskItems) {
            $section = $this->digestSection($userId, $taskItems);
            if (!$section) {
                $this->acknowledge($taskItems);
                continue;
            }
            $cards[$section['root']->id][] = $section;
        }

        $lastError = '';
        foreach ($cards as $sections) {
            foreach ($this->cardPages($sections) as $page) {
                $page = $this->refreshDigestPage($userId, $page);
                if (!$page) {
                    continue;
                }
                $rows = array_merge(...array_column($page, 'rows'));
                try {
                    if (!$this->sendCard($userId, $connection, $page)) {
                        $this->acknowledge($items);
                        return;
                    }
                } catch (RuntimeException $exception) {
                    $lastError = $exception->getMessage();
                    $retrying = !$this->handleCardFailure($userId, $batch, $attempt, $rows, $exception);
                    break 2;
                }
                $this->acknowledge($rows);
            }
        }
        if ($lastError !== ($connection['last_error'] ?? '') && $this->digestConnection($userId) === $connection) {
            update_user_meta($userId, self::CONNECTION, ['enabled' => $connection['enabled'], 'last_error' => $lastError]);
        }
    }

    /** Keep the failed page in its batch and return untouched pages to the fresh queue. */
    private function handleCardFailure($userId, $batch, $attempt, array $rows, RuntimeException $exception)
    {
        $code = $exception->getCode();
        if ($code === 429) {
            set_transient(self::WAIT_PREFIX . $userId, time() + $this->retryAfter, $this->retryAfter);
        }
        $this->batchRows($userId, $batch)
            ->whereNotIn('id', array_column($rows, 'row_id'))->update(['object_type' => self::DIGEST_TYPE, 'key' => '']);
        if ($attempt < self::MAX_RETRIES && (!$code || $code === 429 || $code >= 500)) {
            return $this->scheduleDigest($userId, $attempt + 1, max($this->retryAfter, 60 * pow(3, $attempt)), $batch);
        }
        $this->acknowledge($rows);
        return true;
    }

    /** Keep a parent and its subtasks together, splitting only at section boundaries. */
    private function cardPages(array $sections)
    {
        $page = [];
        usort($sections, static function ($a, $b) {
            return (int) $a['subtask'] <=> (int) $b['subtask'];
        });
        foreach ($sections as $section) {
            $candidate = array_merge($page, [$section]);
            if ($page && mb_strlen(wp_strip_all_tags($this->cardText($candidate)), 'UTF-8') > self::DIGEST_MESSAGE_BYTES) {
                yield $page;
                $page = [];
            }
            $page[] = $section;
        }
        yield $page;
    }

    /** Rebuild each section from current permissions, preferences and task state before sending. */
    private function refreshDigestPage($userId, array $page)
    {
        wp_cache_delete($userId, 'user_meta');
        $eligible = [];
        $discarded = [];
        foreach ($page as $planned) {
            $rows = array_column($planned['rows'], null, 'row_id');
            $section = $this->digestSection($userId, $planned['rows']);
            if ($section && (int) $section['root']->id === (int) $planned['root']->id) {
                $section['rows'] = $section['activities'];
                $eligible[] = $section;
                $rows = array_diff_key($rows, array_column($section['rows'], null, 'row_id'));
            }
            $discarded = array_merge($discarded, array_values($rows));
        }
        // Retire skipped rows before the HTTP call, including when its eligible siblings need a retry.
        $this->acknowledge($discarded);
        return $eligible;
    }

    private function sendCard($userId, array $connection, array $sections)
    {
        $first = $sections[0];
        $url = fluent_boards_page_url() . 'boards/' . (int) $first['board']->id . '/tasks/' . (int) $first['root']->id;
        $payload = [
            'chat_id' => $connection['chat_id'],
            'parse_mode' => 'HTML',
            'text' => $this->cardText($sections),
            'reply_markup' => ['inline_keyboard' => [[['text' => __('Open task', 'fluent-boards'), 'url' => esc_url_raw($url)]]]],
        ];

        $current = $this->digestConnection($userId, $token);
        if (empty($current['enabled']) || $current['chat_id'] !== $connection['chat_id']
            || $current['generation'] !== $connection['generation']) {
            return false;
        }
        $this->request('sendMessage', $payload, $token);
        return true;
    }

    private function cardText(array $sections)
    {
        $root = $sections[0]['root'];
        $board = $sections[0]['board'];
        $actors = array_merge(...array_column($sections, 'activities'));
        $lines = ['<b>' . esc_html__('Updates', 'fluent-boards') . '</b>'];
        /* translators: %1$s is a list of members, %2$s is the task title. */
        $lines[] = sprintf(esc_html__('%1$s updated %2$s', 'fluent-boards'),
            '<b>' . esc_html($this->actorNames($actors)) . '</b>', '<b>' . esc_html($this->plain($root->title, 140)) . '</b>');
        $also = [];
        foreach ($sections as $section) {
            $lines = array_merge($lines, $section['lines']);
            $also = array_merge($also, $section['also']);
        }
        if ($also) {
            $lines[] = '<b>' . esc_html__('Also:', 'fluent-boards') . '</b> ' . esc_html(implode(', ', array_unique($also)));
        }
        $footer = [$this->plain($board->title, 120)];
        if ($root->stage) {
            $footer[] = $this->plain($root->stage->title, 100);
        }
        if ($root->due_at) {
            /* translators: %s is the current task due date. */
            $footer[] = sprintf(__('Due %s', 'fluent-boards'), $this->digestDate($root->due_at));
        }
        $lines[] = esc_html(implode(' · ', $footer));
        return implode("\n", $lines);
    }

    private function digestDate($date)
    {
        return get_date_from_gmt($date, get_option('date_format') . ', ' . get_option('time_format'));
    }

    /** Recheck every activity and summarize repeated event types under one task link. */
    private function digestSection($userId, array $items)
    {
        $task = $this->accessibleTask($items[0]['task_id'], $userId, $board, $parent);
        if (!$task || ($task->parent_id && !$parent)) {
            return '';
        }
        $preferences = $this->eventPreferences($userId, $board->id);
        $activities = [];
        $firstAssignment = null;
        foreach ($items as $item) {
            if ((int) $item['board_id'] !== (int) $board->id) {
                continue;
            }
            $event = $item['event'];
            // An opposite final assignment leaves the member where they started.
            if (in_array($event, ['add_assignee', 'remove_assignee'], true)) {
                $firstAssignment = $firstAssignment ?? $event;
                if ($event !== $firstAssignment) {
                    continue;
                }
            }
            if (!empty($preferences[$event]) && $this->eventStillApplies($event, $item['comment_id'], $userId, $task, $parent)) {
                $activities[$event][] = $item;
            }
        }
        // Previously queued start-date edits may predate the removal hook.
        // Collapse a set followed by a clear to its net effect, retaining other edits.
        $dates = $activities['due_date_update'] ?? [];
        $startChanges = array_filter($dates, static function ($row) {
            return ($row['change']['field'] ?? '') === 'started_at';
        });
        if ($startChanges && (string) reset($startChanges)['change']['before'] === (string) $task->started_at) {
            $activities['due_date_update'] = array_diff_key($dates, $startChanges);
            if (!$activities['due_date_update']) {
                unset($activities['due_date_update']);
            }
        }
        if (!$activities) {
            return '';
        }
        if ($parent && ($parent->archived_at || (int) $parent->board_id !== (int) $board->id)) {
            return [];
        }
        $lines = [];
        $also = [];
        if ($parent) {
            $lines[] = '<b>' . esc_html__('Subtask:', 'fluent-boards') . '</b> ' . esc_html($this->plain($task->title, 140));
        }
        $labels = ['stage' => __('Stage:', 'fluent-boards'), 'due_at' => __('Due:', 'fluent-boards'), 'started_at' => __('Start:', 'fluent-boards')];
        // Keep stage and date transitions first regardless of the order of edits.
        $activities = array_merge(array_intersect_key(['stage_change' => [], 'due_date_update' => []], $activities), $activities);
        foreach ($activities as $event => $events) {
            if ($event === 'priority_change') {
                $also[] = __('priority', 'fluent-boards');
                continue;
            }
            $fields = [];
            foreach ($events as $row) {
                $change = $row['change'] ?? [];
                if (isset($labels[$change['field'] ?? ''])) {
                    $fields[$change['field']][] = $change;
                }
            }
            if (!$fields) {
                $lines[] = esc_html($this->activityLine($event, $events, $task));
                continue;
            }
            foreach ($fields as $field => $changes) {
                $before = $this->changeValue($field, $changes[0]['before']);
                $after = $this->changeValue($field, $field === 'started_at' ? $task->started_at : end($changes)['after']);
                if ($field === 'stage' && $changes[0]['before'] === null) {
                    $lines[] = '<b>' . esc_html($labels[$field]) . '</b> ' . $after;
                    continue;
                }
                $old = $field !== 'stage' && $changes[0]['before'] ? '<s>' . $before . '</s>' : $before;
                $lines[] = '<b>' . esc_html($labels[$field]) . '</b> ' . $old . ' → ' . $after;
            }
        }
        return ['root' => $parent ?: $task, 'board' => $board, 'subtask' => (bool) $parent, 'activities' => array_merge(...array_values($activities)),
            'lines' => $lines, 'also' => $also, 'rows' => $items];
    }

    private function changeValue($field, $value)
    {
        if (!$value) {
            return esc_html__('None', 'fluent-boards');
        }
        return esc_html($field === 'stage' ? $this->plain($value, 100) : $this->digestDate($value));
    }

    private function plain($text, $length)
    {
        return wp_html_excerpt(wp_strip_all_tags((string) $text), $length, '...');
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
        if (count($names) === 2) {
            /* translators: %1$s and %2$s are the two members who made changes. */
            return sprintf(__('%1$s and %2$s', 'fluent-boards'), $names[0], $names[1]);
        }
        if (count($names) <= 3) {
            return implode(', ', $names);
        }
        /* translators: %1$s is a list of three names, %2$d is how many other people */
        return sprintf(__('%1$s and %2$d more', 'fluent-boards'), implode(', ', array_slice($names, 0, 3)), count($names) - 3);
    }

    /** Select comment watchers or the parent author; mentions use their explicitly selected recipients. */
    public function queueCommentNotification(Comment $comment, $mentionedIds = null)
    {
        if (!in_array($comment->type, ['comment', 'reply'], true)) {
            return;
        }
        $task = Task::find($comment->task_id);
        if (!$task) {
            return;
        }
        if ($mentionedIds !== null) {
            $this->queueNotification('mention', $task, $mentionedIds, $comment->id);
            return;
        }

        // Null selects the same watchers as task changes, including parent watchers.
        $recipients = null;
        if ($comment->type === 'reply') {
            $parent = Comment::find($comment->parent_id);
            $recipients = $parent && (int) $parent->task_id === (int) $task->id ? [$parent->created_by] : [];
        }
        $mentioned = array_map('absint', (array) ($comment->settings['mentioned_id'] ?? []));
        $this->queueNotification('comment', $task, $recipients, $comment->id, [], $mentioned);
    }

    /** Recheck access, task state and Telegram preferences before delivery or retry. */
    public function deliverNotification($event, $taskId, $commentId, $boardId, $userId, $generation, $attempt = 0)
    {
        if (!in_array($event, self::EVENTS, true)) {
            return;
        }
        $connection = $this->connection($userId);
        if (empty($this->settings()['enabled']) || empty($connection['enabled'])
            || $connection['generation'] !== $generation || !$this->eventPreferences($userId, $boardId)[$event]) {
            return;
        }
        $task = $this->accessibleTask($taskId, $userId, $board, $parent);
        if (!$task || (int) $board->id !== (int) $boardId || !$this->eventStillApplies($event, $commentId, $userId, $task, $parent)) {
            return;
        }
        $messages = $this->eventMessages();
        $text = '<h3>' . esc_html($messages[$event]) . '</h3><p><b>' . esc_html(wp_html_excerpt(wp_strip_all_tags($task->title), 1000, '...')) . '</b><br>' . esc_html(wp_html_excerpt(wp_strip_all_tags($board->title), 1000, '...'));
        if ($event === 'stage_change' && $task->stage) {
            $text .= '<br>' . esc_html(wp_html_excerpt(wp_strip_all_tags($task->stage->title), 1000, '...'));
        }
        if ($task->due_at) {
            /* translators: %s is the task due date in the WordPress site timezone. */
            $text .= '<br>' . esc_html(sprintf(__('Due: %s', 'fluent-boards'), get_date_from_gmt($task->due_at, get_option('date_format') . ' ' . get_option('time_format'))));
        }
        $url = fluent_boards_page_url() . 'boards/' . (int) $boardId . '/tasks/' . (int) ($task->parent_id ?: $task->id);
        $text .= '</p><p><a href="' . esc_url($url) . '">' . esc_html__('Open task', 'fluent-boards') . '</a></p>';
        $originalConnection = $connection;
        try {
            $this->request('sendRichMessage', ['chat_id' => $connection['chat_id'], 'rich_message' => ['html' => $text]]);
            $connection['last_error'] = '';
        } catch (RuntimeException $exception) {
            $connection['last_error'] = $exception->getMessage();
            if ($attempt < 3 && ($exception->getCode() === 429 || $exception->getCode() >= 500 || !$exception->getCode())) {
                wp_schedule_single_event(time() + max($this->retryAfter, 60 * pow(3, $attempt)), self::NOTIFICATION_HOOK,
                    [$event, (int) $taskId, (int) $commentId, (int) $boardId, (int) $userId, $generation, $attempt + 1]);
            }
        }
        if ($this->connection($userId) === $originalConnection) {
            update_user_meta($userId, self::CONNECTION, ['enabled' => $connection['enabled'], 'last_error' => $connection['last_error']]);
        }
    }

    private function accessibleTask($taskId, $userId, &$board, &$parent)
    {
        $task = Task::find(absint($taskId));
        if (!$task) {
            return;
        }
        $parent = $task->parent_id ? Task::find($task->parent_id) : null;
        $board = $task->board_id ? $task->board : ($parent ? $parent->board : null);
        if (!$board || $board->archived_at
            || !PermissionManager::getUserIdsWithBoardAccess($board->id, [$userId])) {
            return;
        }
        // Recheck the pause so queued jobs and retries stop once the user pauses.
        if ((new NotificationService())->isNotificationMuted($userId, $board->id)) {
            return;
        }
        return $task;
    }

    private function eventStillApplies($event, $commentId, $userId, $task, $parent)
    {
        if ($event !== 'task_archived' && ($task->archived_at || ($parent && $parent->archived_at))) {
            return false;
        }
        if ($event === 'task_archived' && !$task->archived_at) {
            return false;
        }
        if ($event === 'add_assignee' && !$task->assignees()->where('users.ID', absint($userId))->exists()) {
            return false;
        }
        if ($event === 'remove_assignee' && $task->assignees()->where('users.ID', absint($userId))->exists()) {
            return false;
        }
        if (in_array($event, ['comment', 'mention'], true)) {
            $comment = Comment::withoutGlobalScopes()->find(absint($commentId));
            if (!$comment || (int) $comment->task_id !== (int) $task->id || !in_array($comment->type, ['comment', 'reply'], true)) {
                return false;
            }
        }
        return true;
    }

    private function eventMessages()
    {
        return [
            'comment' => __('A new comment was added to a task.', 'fluent-boards'),
            'mention' => __('You were mentioned in a task comment.', 'fluent-boards'),
            'add_assignee' => __('You have been assigned a task.', 'fluent-boards'),
            'remove_assignee' => __('You were removed from a task.', 'fluent-boards'),
            'stage_change' => __('A task moved to another stage.', 'fluent-boards'),
            'due_date_update' => __('Task dates were updated.', 'fluent-boards'),
            'priority_change' => __('Task priority changed.', 'fluent-boards'),
            'description_update' => __('Task description changed.', 'fluent-boards'),
            'task_archived' => __('A task was archived.', 'fluent-boards'),
        ];
    }

    /** Fixed API host, bounded timeout, and generic errors prevent credentials leaking into responses. */
    private function request($method, array $body, $token = null)
    {
        $token = $token ?? ($this->settings()['token'] ?? '');
        $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/' . $method, [
            'timeout' => 12,
            'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode((object) $body),
        ]);
        if (is_wp_error($response)) {
            throw new RuntimeException(__('Could not reach Telegram. Please try again.', 'fluent-boards'));
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['ok'])) {
            $code = (int) ($data['error_code'] ?? wp_remote_retrieve_response_code($response));
            $this->retryAfter = max(60, min(DAY_IN_SECONDS, absint($data['parameters']['retry_after'] ?? 60)));
            if ($code === 401) {
                throw new RuntimeException(__('The Telegram bot token is invalid. Ask an administrator to update it.', 'fluent-boards'), $code);
            }
            if ($code === 403) {
                throw new RuntimeException(__('Telegram could not deliver the message. Unblock the bot and reconnect your account.', 'fluent-boards'), $code);
            }
            if ($code === 409) {
                throw new RuntimeException(__('This bot is used by another service. Use a dedicated bot for Fluent Boards.', 'fluent-boards'), $code);
            }
            throw new RuntimeException(__('Telegram could not complete the request. Please try again.', 'fluent-boards'), $code);
        }
        return $data['result'];
    }
}
