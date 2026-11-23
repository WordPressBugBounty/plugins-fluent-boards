<?php

namespace FluentBoards\App\Services;

use ActionScheduler;
use ActionScheduler_AsyncRequest_QueueRunner;
use ActionScheduler_Store;
use FluentBoards\App\Models\Meta;
use RuntimeException;
use Throwable;

/** Schedule, claim and recover per-recipient activity batches for notification channels. */
abstract class NotificationDigestService
{
    // After the last delivery retry, a recovery job handles the exhausted batch.
    protected const RECOVERY_ATTEMPTS_AFTER_RETRIES = 1;

    protected static $notificationWakeScheduled = false;

    abstract protected function digestDelay();
    abstract protected function waitSeconds($userId);
    /** Channels that reset the retry budget between chunks return the active recovery attempt. */
    abstract protected function sendBatch($userId, $attempt, $batch, &$retrying);
    abstract protected function finishExhaustedBatch($userId, $batch);

    /** Use a process-owned lock so a worker crash releases its batch immediately. */
    protected function acquireLock($scope, $timeout = 0)
    {
        global $wpdb;
        $scope = $wpdb->get_blog_prefix() . ':' . static::DIGEST_HOOK . ':' . $scope;
        if ((defined('DB_ENGINE') && DB_ENGINE === 'sqlite') || (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite')) {
            $database = defined('DB_PATH') ? DB_PATH : FQDB;
            $directory = $database === ':memory:' ? get_temp_dir() : dirname($database);
            $key = hash('sha256', $database . ':' . $scope);
            $until = microtime(true) + $timeout;
            do {
                $file = $this->trySqliteLock($directory, $key);
                if ($file) {
                    return static function () use ($file) {
                        fclose($file);
                    };
                }
                if (microtime(true) >= $until) {
                    return null;
                }
                usleep(50000);
            } while (true);
        }

        $name = 'fbs_' . md5(DB_NAME . ':' . $scope);
        // Digest callbacks may reenter the same database connection. A named MySQL lock is recursive.
        $query = $timeout ? $wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $timeout)
            : $wpdb->prepare('SELECT IF(IS_FREE_LOCK(%s), GET_LOCK(%s, 0), 0)', $name, $name);
        if ((int) $wpdb->get_var($query) !== 1) {
            return null;
        }
        return static function () use ($wpdb, $name) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        };
    }

    /**
     * The full key identifies the owner. A short-lived guard serializes opening and
     * pruning files in this bucket so nobody can acquire an unlinked file. Delivery
     * holds only the per-scope lock. The next acquisition reclaims idle or crashed slots.
     */
    private function trySqliteLock($directory, $key)
    {
        $bucket = $directory . '/.fbs-digest-locks/' . substr($key, 0, 2);
        if (!wp_mkdir_p($bucket) && !is_dir($bucket)) {
            return null;
        }
        $guard = fopen($bucket . '/.guard', 'c');
        if (!$guard) {
            return null;
        }

        try {
            if (!flock($guard, LOCK_EX)) {
                return null;
            }
            $name = $key . '.lock';
            foreach (scandir($bucket, SCANDIR_SORT_NONE) ?: [] as $entry) {
                if ($entry === $name || substr($entry, -5) !== '.lock') {
                    continue;
                }
                $candidate = $bucket . '/' . $entry;
                $idle = fopen($candidate, 'r+');
                if ($idle) {
                    $available = flock($idle, LOCK_EX | LOCK_NB);
                    fclose($idle);
                    if ($available) {
                        unlink($candidate);
                    }
                }
            }

            $file = fopen($bucket . '/' . $name, 'c');
            if ($file && flock($file, LOCK_EX | LOCK_NB)) {
                return $file;
            }
            if ($file) {
                fclose($file);
            }
            return null;
        } finally {
            fclose($guard);
        }
    }

    /** Send one nonblocking loopback; the existing runner keeps its claims and concurrency checks. */
    public function wakeNotificationQueue()
    {
        remove_action('shutdown', [$this, 'wakeNotificationQueue'], 9);
        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            (new ActionScheduler_AsyncRequest_QueueRunner(ActionScheduler::store()))->maybe_dispatch();
        } catch (Throwable $exception) {
            error_log('FluentBoards could not wake the notification queue');
        }
    }

    /**
     * True only for a digest job that has not started. as_has_scheduled_action() also counts the
     * running job, which has already claimed its rows and would leave new activity unsent.
     */
    protected function hasPendingDigest($userId)
    {
        if (wp_next_scheduled(static::DIGEST_HOOK, [(int) $userId, 0])) {
            return true;
        }
        if (!class_exists('ActionScheduler') || !ActionScheduler::is_initialized()) {
            return false;
        }
        return null !== ActionScheduler::store()->query_action([
            'hook' => static::DIGEST_HOOK,
            'args' => [(int) $userId, 0],
            'group' => 'fluent-boards',
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'orderby' => 'none',
        ]);
    }

    protected function pendingDelay($userId)
    {
        return $this->digestDelay();
    }

    /** Restore missing jobs from the activity rows, including batches left behind by a dead worker. */
    public function reconcileDigests()
    {
        $members = Meta::where('object_type', static::DIGEST_TYPE)->distinct()->pluck('object_id');
        foreach ($members as $userId) {
            if (!$this->hasPendingDigest($userId)) {
                $this->scheduleDigest($userId, 0, $this->pendingDelay($userId));
            }
        }

        // The 12-character batch ID also identifies legacy Telegram rows without a colon suffix.
        $batches = Meta::where('object_type', static::BATCH_TYPE)->distinct()
            ->selectRaw("object_id, SUBSTR(`key`, 1, 12) AS batch")->get();
        foreach ($batches as $row) {
            if (!$this->hasBatchJob($row->object_id, $row->batch)) {
                // The original retry count is unknown. Allow one final attempt under the existing nonce rules.
                $this->scheduleDigest($row->object_id, static::MAX_RETRIES, 0, $row->batch, static::RECOVERY_HOOK);
            }
        }
    }

    private function hasBatchJob($userId, $batch)
    {
        $lastAttempt = static::MAX_RETRIES + static::RECOVERY_ATTEMPTS_AFTER_RETRIES;
        for ($attempt = 0; $attempt <= $lastAttempt; ++$attempt) {
            $args = [(int) $userId, $attempt, $batch];
            foreach ([static::DIGEST_HOOK, static::RECOVERY_HOOK] as $hook) {
                if (wp_next_scheduled($hook, $args) || as_has_scheduled_action($hook, $args, 'fluent-boards')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Fresh jobs have args [user, 0] and collect the member's queue. Batch jobs have args
     * [user, attempt, batch] and only ever send that batch, so retries keep their own budget.
     */
    protected function scheduleDigest($userId, $attempt, $delay, $batch = '', $hook = null)
    {
        $hook = $hook ?? static::DIGEST_HOOK;
        $args = [(int) $userId, (int) $attempt];
        if ($batch !== '') {
            $args[] = (string) $batch;
        }
        $timestamp = time() + $delay;
        $id = $delay > 0
            ? as_schedule_single_action($timestamp, $hook, $args, 'fluent-boards')
            : as_enqueue_async_action($hook, $args, 'fluent-boards');
        if ($id) {
            if (!$delay && $hook === static::DIGEST_HOOK && !static::$notificationWakeScheduled
                && class_exists(ActionScheduler_AsyncRequest_QueueRunner::class)) {
                static::$notificationWakeScheduled = true;
                add_action('shutdown', [$this, 'wakeNotificationQueue'], 9);
            }
            return true;
        }
        if (wp_next_scheduled($hook, $args) || wp_schedule_single_event($timestamp, $hook, $args)) {
            return true;
        }
        error_log('FluentBoards could not schedule a notification digest with Action Scheduler or WP-Cron');
        return false;
    }

    protected function cancelRecovery($userId, $attempt, $batch)
    {
        $args = [(int) $userId, (int) $attempt, (string) $batch];
        as_unschedule_all_actions(static::RECOVERY_HOOK, $args, 'fluent-boards');
        wp_clear_scheduled_hook(static::RECOVERY_HOOK, $args);
    }

    /** Claim the waiting rows in one write so concurrent workers cannot split a member's digest. */
    protected function claimDigest($userId, $batch)
    {
        global $wpdb;
        $table = $wpdb->prefix . (new Meta())->getTable();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE `{$table}` SET object_type = %s, `key` = CONCAT(%s, ':', `key`)
             WHERE object_id = %d AND object_type = %s",
            static::BATCH_TYPE, $batch, $userId, static::DIGEST_TYPE
        ));
        if ($claimed === false) {
            throw new RuntimeException(__('Could not claim the notification digest.', 'fluent-boards'));
        }
        return $claimed;
    }

    /** Remove sent (or dropped) activities from their batch. */
    protected function acknowledge(array $items)
    {
        $ids = array_filter(array_column($items, 'row_id'));
        if ($ids) {
            Meta::where('object_type', static::BATCH_TYPE)->whereIn('id', array_values($ids))->delete();
        }
    }

    /** Deliver a fresh or recovered batch under exclusive ownership. */
    public function sendDigest($userId, $attempt = 0, $batch = '')
    {
        $userId = absint($userId);
        $attempt = absint($attempt);
        $batch = is_string($batch) && preg_match('/^[A-Za-z0-9]{12}$/D', $batch) ? $batch : '';

        $delay = $this->waitSeconds($userId);
        if ($delay > 0) {
            if ($batch !== '' || !$this->hasPendingDigest($userId)) {
                $this->scheduleDigest($userId, $batch !== '' ? $attempt : 0, $delay, $batch);
            }
            return;
        }

        $fresh = $batch === '';
        if ($fresh) {
            $batch = wp_generate_password(12, false, false);
            $attempt = 0;
        }

        $release = $this->acquireLock($userId . ':' . $batch);
        if (!$release) {
            // Keep a wake-up in case the current owner dies after this recovery job returns.
            $this->scheduleDigest($userId, $attempt, static::RECOVERY_SECONDS, $fresh ? '' : $batch,
                $fresh ? static::DIGEST_HOOK : static::RECOVERY_HOOK);
            return;
        }
        try {
            $this->deliverClaimedBatch($userId, $attempt, $batch, $fresh);
        } finally {
            $release();
        }
    }

    private function deliverClaimedBatch($userId, $attempt, $batch, $fresh)
    {
        // Leave the rows for reconciliation if this worker cannot arrange its own recovery.
        if ($attempt <= static::MAX_RETRIES
            && !$this->scheduleDigest($userId, $attempt + 1, static::RECOVERY_SECONDS, $batch, static::RECOVERY_HOOK)) {
            return;
        }
        if ($fresh && !$this->claimDigest($userId, $batch)) {
            $this->cancelRecovery($userId, 1, $batch);
        } elseif ($attempt > static::MAX_RETRIES) {
            $this->finishExhaustedBatch($userId, $batch);
        } else {
            $retrying = false;
            $recoveryAttempt = $this->sendBatch($userId, $attempt, $batch, $retrying);
            if (!$retrying) {
                $this->cancelRecovery($userId, $recoveryAttempt ?? ($attempt + 1), $batch);
            }
        }

        // Activity recorded while this job ran needs a job of its own.
        if (Meta::where('object_type', static::DIGEST_TYPE)->where('object_id', $userId)->exists()
            && !$this->hasPendingDigest($userId)) {
            $this->scheduleDigest($userId, 0, $this->pendingDelay($userId));
        }
    }
}
