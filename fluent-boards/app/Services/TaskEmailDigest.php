<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Hooks\Handlers\ScheduleHandler;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Libs\Mailer;
use Throwable;

/** Email groups a recipient's events by task, anchored at the first event in each window. */
class TaskEmailDigest extends NotificationDigestService
{
    const SOURCE_TYPE = 'task_email_source';
    const DIGEST_TYPE = 'task_email_event';
    const BATCH_TYPE = 'task_email_batch';
    const DIGEST_HOOK = 'fluent_boards/deliver_task_email_events';
    const RECOVERY_HOOK = 'fluent_boards/retry_task_email_events';
    const RECOVERY_SWEEP = 'fluent_boards/recover_overdue_email_digests';
    private const LEGACY_TYPES = ['task_email_digest', 'task_email_digest_fanout',
        'task_email_digest_uncertain', 'task_email_digest_claim_failed'];
    const WINDOW = 120;
    const MAX_RETRIES = 1;
    const RECOVERY_SECONDS = 300;

    /** Enqueue the source event while its original actor, board and change are known. */
    public static function enqueue($type, $objectId, $recipients, $boardId, array $change = [])
    {
        $hook = 'fluent_boards/one_time_schedule_send_email_for_' . $type;
        $args = [absint($objectId), $recipients, get_current_user_id(), time(), absint($boardId), $change];
        try {
            $args[] = self::recordSource($type, ...$args);
        } catch (Throwable $error) {
            // The edit already succeeded. Keep its arguments in the scheduled job for another write attempt.
            error_log(sprintf('FluentBoards deferred recording %s notification for #%d.', $type, $objectId));
        }
        try {
            if (as_enqueue_async_action($hook, $args, 'fluent-boards')) {
                return;
            }
        } catch (Throwable $error) {
            // WP-Cron can still accept the source event if the scheduler is unavailable.
        }
        if (!wp_schedule_single_event(time(), $hook, $args) && !wp_next_scheduled($hook, $args)) {
            error_log(sprintf('FluentBoards could not queue %s notification for #%d.', $type, $objectId));
        }
    }

    /** The source row ID orders edits recorded in the same second, before workers can reorder them. */
    public static function recordSource($type, $objectId, $recipients, $actorId, $time, $boardId, $change)
    {
        $isComment = in_array($type, ['comment', 'mention'], true);
        $taskId = $isComment ? Comment::where('id', $objectId)->value('task_id') : $objectId;
        $source = Meta::create([
            'object_type' => self::SOURCE_TYPE,
            'object_id'   => absint($taskId),
            'key'         => sprintf('%020d', $time),
            'value'       => [
                'recipients' => $recipients,
                'event' => ['id' => absint($objectId), 'type' => $type, 'actor' => absint($actorId),
                    'time' => (int) $time, 'board' => absint($boardId), 'change' => $change],
            ],
        ]);
        return (int) $source->id;
    }

    /** Consume the source and insert its recipient rows in one transaction, once across all workers. */
    public function expandSource($sourceId)
    {
        $ready = App::getInstance('db')->transaction(function () use ($sourceId) {
            $source = Meta::where('object_type', self::SOURCE_TYPE)->where('id', absint($sourceId))->lockForUpdate()->first();
            if (!$source) {
                return null;
            }
            $event = $source->value['event'];
            $task = Task::find($source->object_id);
            $emails = [];
            if ($task && (!$event['board'] || $event['board'] === (int) $task->board_id)) {
                $event['board'] = (int) $task->board_id;
                $event['task'] = (int) $task->id;
                $event['sequence'] = (int) $source->id;
                $emails = (new ScheduleHandler())->sourceRecipientEmails($event, $source->value['recipients']);
                $this->record(array_keys($emails), [$event]);
            }
            $source->delete();
            return [$emails, $event];
        });
        if (!$ready) {
            return;
        }
        list($emails, $event) = $ready;
        $isComment = in_array($event['type'], ['comment', 'mention'], true);
        $context = ['type' => $event['type'], ($isComment ? 'comment_id' : 'task_id') => $event['id']];
        foreach ($emails as $email) {
            Mailer::notifyReady($context + ['recipient' => $email]);
        }
        $this->scheduleRecipients(array_keys($emails));
    }

    private function scheduleRecipients(array $recipientIds)
    {
        foreach ($recipientIds as $id) {
            if (!$this->hasPendingDigest($id)) {
                $this->scheduleDigest($id, 0, $this->pendingDelay($id));
            }
        }
    }

    private function record(array $recipients, array $events)
    {
        $rows = [];
        foreach ($events as $event) {
            foreach ($recipients as $id) {
                $rows[] = [
                    'object_type' => self::DIGEST_TYPE,
                    'object_id'   => (int) $id,
                    'key'         => sprintf('%020d', $event['time']),
                    'value'       => maybe_serialize($event),
                ];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            Meta::insert($chunk);
        }
    }

    protected function digestDelay()
    {
        return self::WINDOW;
    }

    protected function waitSeconds($userId)
    {
        return 0;
    }

    protected function pendingDelay($userId)
    {
        $first = Meta::where('object_type', self::DIGEST_TYPE)->where('object_id', $userId)->min('key');
        return max(5, (int) $first + self::WINDOW - time());
    }

    /** Partition events in occurrence order into half-open windows. */
    private function windows($rows)
    {
        $windows = [];
        $deadlines = [];
        foreach ($rows as $row) {
            $event = $row->value;
            $pair = $event['board'] . ':' . $event['task'];
            if (!isset($deadlines[$pair]) || $event['time'] >= $deadlines[$pair]) {
                $deadlines[$pair] = $event['time'] + self::WINDOW;
            }
            $windows[$pair . ':' . $deadlines[$pair]][] = $row;
        }
        return $windows;
    }

    /** Leave younger task windows open when another task is ready for the same recipient. */
    protected function claimDigest($userId, $batch)
    {
        $cutoff = time();
        $pending = Meta::where('object_type', self::DIGEST_TYPE)->where('object_id', $userId);
        $tasks = [];
        foreach ((clone $pending)->where('key', '<=', sprintf('%020d', $cutoff - self::WINDOW))->get(['value']) as $row) {
            $tasks[$row->value['task']] = true;
        }
        if (!$tasks) {
            return 0;
        }

        // Finish earlier source jobs for these tasks before reading their recipient windows.
        // Only claim windows already closed at the cutoff. Later edits belong to a future window.
        $sources = Meta::where('object_type', self::SOURCE_TYPE)->whereIn('object_id', array_keys($tasks))
            ->where('key', '<', sprintf('%020d', $cutoff))->orderBy('id')->get(['id', 'object_id']);
        foreach ($sources as $source) {
            if (isset($tasks[$source->object_id]) && !$this->tryExpandSource($source->id)) {
                unset($tasks[$source->object_id]);
            }
        }
        return App::getInstance('db')->transaction(function () use ($pending, $batch, $tasks, $cutoff) {
            $rows = (clone $pending)->orderBy('key')->orderBy('id')->lockForUpdate()->get();
            $claimed = 0;
            foreach ($this->windows($rows) as $windowKey => $window) {
                if (isset($tasks[$window[0]->value['task']]) && $window[0]->value['time'] + self::WINDOW <= $cutoff) {
                    $claimed += (clone $pending)->whereIn('id', array_column($window, 'id'))->toBase()
                        ->update(['object_type' => self::BATCH_TYPE, 'key' => $batch . ':' . $windowKey]);
                }
            }
            return $claimed;
        });
    }

    private function batchRows($userId, $batch)
    {
        return Meta::where('object_type', self::BATCH_TYPE)->where('object_id', $userId)
            ->where('key', 'like', $batch . ':%');
    }

    protected function sendBatch($userId, $attempt, $batch, &$retrying)
    {
        $rows = $this->batchRows($userId, $batch)->orderBy('key')->orderBy('id')->get();
        // Preserve event order when source jobs ran out of sequence.
        $rows = $rows->sortBy(function ($row) {
            return [$row->value['time'], $row->value['sequence'] ?? (int) $row->id];
        });
        foreach ($rows->groupBy('key') as $window) {
            $first = $window->first()->value;
            $task = Task::find($first['task']);
            if ($task && (int) $task->board_id === $first['board']
                && !(new ScheduleHandler())->sendTaskEmailDigest($task, $userId, $window->pluck('value')->all())) {
                $retrying = true;
                continue;
            }
            $this->batchRows($userId, $batch)->where('key', $window->first()->key)->delete();
        }
    }

    protected function finishExhaustedBatch($userId, $batch)
    {
        $count = $this->batchRows($userId, $batch)->delete();
        if ($count) {
            error_log(sprintf('FluentBoards discarded %d exhausted email digest events for user #%d.', $count, $userId));
        }
    }

    private function tryExpandSource($id)
    {
        try {
            $this->expandSource($id);
            return true;
        } catch (Throwable $error) {
            error_log(sprintf('FluentBoards could not expand email source #%d.', $id));
            return false;
        }
    }

    public static function ensureRecoverySweep()
    {
        if (!wp_next_scheduled(self::RECOVERY_SWEEP)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::RECOVERY_SWEEP);
        }
    }

    /** Drain rows written by the earlier email queue, including its terminal failure states. */
    public function recoverOverdue()
    {
        Meta::whereIn('object_type', self::LEGACY_TYPES)->select(['id'])->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $this->importLegacy($row->id);
            }
        });
        Meta::where('object_type', self::SOURCE_TYPE)->select(['id'])->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $this->tryExpandSource($row->id);
            }
        });
        $this->reconcileDigests();
    }

    public function importLegacy($id)
    {
        $recipients = App::getInstance('db')->transaction(function () use ($id) {
            $row = Meta::where('id', absint($id))->whereIn('object_type', self::LEGACY_TYPES)->lockForUpdate()->first();
            if (!$row) {
                return [];
            }
            $recipients = [];
            $data = $row->value;
            if ($row->object_type === 'task_email_digest_fanout' || ($row->object_type === 'task_email_digest' && empty($data['transport_started_at']))) {
                $events = $data['events'] ?? [$data['event']];
                foreach ($events as &$event) {
                    $event['board'] = (int) $data['board'];
                    $event['task'] = (int) $row->object_id;
                }
                unset($event);
                $recipients = $data['recipients'] ?? [(int) $row->key];
                $this->record($recipients, $events);
            } else {
                error_log(sprintf('FluentBoards discarded legacy failed or unconfirmed email digest #%d.', $row->id));
            }
            $row->delete();
            return $recipients;
        });
        $this->scheduleRecipients($recipients);
    }
}
