<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Hooks\Handlers\AutomationHandler;
use FluentBoards\App\Models\Automation;
use FluentBoards\App\Models\AutomationAnalytics;
use FluentBoards\App\Services\Constant;

class AutomationProcessor
{
    protected static $running = [];

    /**
     * Runs started in this request, keyed "automation:task" => trigger name. Stops an automation
     * with several triggers from running twice when one user action fires two of them
     * (e.g. "moved to Done" and "completed").
     */
    protected static $startedInRequest = [];

    protected static $depth = 0;

    protected $maxDepth = 10;

    /** Longest allowed wait step: 52 weeks. */
    const MAX_DELAY_SECONDS = 31449600;

    /** Shortest wait: Action Scheduler and WP-Cron cannot promise anything tighter. */
    const MIN_DELAY_SECONDS = 300;

    protected $registry;

    protected $service;

    public function __construct(?AutomationRegistry $registry = null)
    {
        $this->registry = $registry ?: AutomationRegistry::getInstance();
        $this->service = new AutomationService();
    }

    public function handleTrigger(BaseTrigger $trigger, AutomationContext $context)
    {
        $automations = $this->service->getActiveAutomationsForTrigger($trigger->getName(), (int) $context->get('board_id'));

        foreach ($automations as $automation) {
            if (!$this->anyTriggerMatches($trigger, $context, $automation)) {
                continue;
            }

            $requestKey = $automation->id . ':' . $context->getTaskId();
            $startedBy = static::$startedInRequest[$requestKey] ?? '';
            if ($startedBy && $startedBy !== $trigger->getName()) {
                continue;
            }
            static::$startedInRequest[$requestKey] = $trigger->getName();

            $this->start($automation, $context);
        }
    }

    /** True when any of the automation's triggers with this name accepts the event. */
    protected function anyTriggerMatches(BaseTrigger $trigger, AutomationContext $context, Automation $automation)
    {
        foreach ($automation->getTriggerEntries() as $entry) {
            if (sanitize_key($entry['name']) !== $trigger->getName()) {
                continue;
            }

            if ($trigger->matches($context, (array) $entry['settings'])) {
                return true;
            }
        }

        return false;
    }

    public function start(Automation $automation, AutomationContext $context)
    {
        return $this->guardedById($automation->id, $context->getTaskId(), function () use ($automation, $context) {
            $run = null;

            try {
                $run = AutomationAnalytics::create([
                    'automation_id' => $automation->id,
                    'board_id' => $context->getBoardId(),
                    'task_id' => $context->getTaskId(),
                    'trigger_name' => $context->getTriggerName(),
                    'status' => Constant::AUTOMATION_RUN_RUNNING,
                    'current_step' => 0,
                    'step_path' => '',
                    'trigger_payload' => $context->toPayload(),
                    'step_results' => [],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);

                AutomationHandler::maybePruneRuns();

                $this->execute($automation, $run, $context, [0]);
            } catch (\Throwable $e) {
                if ($run) {
                    $this->fail($automation, $run, $e->getMessage());
                } else {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- surfaced for site debugging only
                    error_log('FluentBoards automation #' . $automation->id . ' could not start: ' . $e->getMessage());
                }
            }

            return $run;
        });
    }

    public function resume($runId)
    {
        $run = AutomationAnalytics::find(absint($runId));
        if (!$run || $run->status !== Constant::AUTOMATION_RUN_WAITING) {
            return;
        }

        $this->guardedById($run->automation_id, $run->task_id, function () use ($run) {
            // Match the position too: another worker may already have reached a later delay.
            $claimed = AutomationAnalytics::where('id', $run->id)
                ->where('status', Constant::AUTOMATION_RUN_WAITING)
                ->where('step_path', $run->step_path)
                ->where('current_step', $run->current_step)
                ->update(['status' => Constant::AUTOMATION_RUN_RUNNING, 'updated_at' => current_time('mysql')]);

            if (!$claimed) {
                return;
            }

            $automation = null;
            try {
                $automation = Automation::find($run->automation_id);
                $this->resumeClaimed($run, $automation);
            } catch (\Throwable $e) {
                $this->fail($automation, $run, $e->getMessage());
            }
        });
    }

    private function resumeClaimed(AutomationAnalytics $run, ?Automation $automation)
    {
        if (!$automation || !$automation->isActive()) {
            $this->cancel($automation, $run, __('Automation is no longer active.', 'fluent-boards'));
            return;
        }

        $payload = (array) $run->trigger_payload;
        $context = AutomationContext::fromPayload($payload);

        // The task was deleted while the run waited; nothing left to act on.
        if (!empty($payload['task_id']) && !$context->getTask()) {
            $this->cancel($automation, $run, __('Task no longer exists.', 'fluent-boards'));
            return;
        }

        // Runs that started waiting before step paths existed resume from their top-level index.
        $path = AutomationFlow::parsePath($run->step_path) ?: [(int) $run->current_step];

        $results = $run->step_results ?: [];
        $delayIndex = null;
        for ($index = count($results) - 1; $index >= 0; $index--) {
            if (($results[$index]['type'] ?? '') === 'delay'
                && ($results[$index]['status'] ?? '') === Constant::AUTOMATION_RUN_WAITING) {
                $delayIndex = $index;
                break;
            }
        }

        // The saved position is only valid while the step just before it is still the delay this
        // run waited on. Otherwise steps were removed or reordered, and resuming could skip or
        // repeat work.
        $frames = $this->framesFromPath((array) $automation->steps, $path);
        $delayResult = $delayIndex === null ? [] : (array) $results[$delayIndex];
        $delayStepId = sanitize_key($delayResult['step_id'] ?? '');
        $expectedNext = $delayResult['result']['next_step_id'] ?? null; // null on runs saved before it existed
        if ($frames === null || !$delayStepId || !$this->resumesAfterDelay($frames, $delayStepId)
            || ($expectedNext !== null && sanitize_key($expectedNext) !== $this->nextStepId($frames))) {
            $this->cancel($automation, $run, __('Automation changed while this run was waiting.', 'fluent-boards'));
            return;
        }

        $results[$delayIndex]['status'] = Constant::AUTOMATION_RUN_SUCCESS;
        $run->step_results = $results;
        $run->status = Constant::AUTOMATION_RUN_RUNNING;
        $run->updated_at = current_time('mysql');
        $run->save();

        $this->execute($automation, $run, $context, $path);
    }

    /**
     * Run $callback with the request-scoped guards held for this automation and task, so an action
     * cannot start the same automation again for the same task (directly or through other
     * automations) and nesting stays under $maxDepth. Returns null when the guard blocks the run.
     */
    protected function guardedById($automationId, $taskId, callable $callback)
    {
        if (static::$depth >= $this->maxDepth) {
            return null;
        }

        $guardKey = $automationId . ':' . (int) $taskId;
        if (isset(static::$running[$guardKey])) {
            return null;
        }

        static::$running[$guardKey] = true;
        static::$depth++;

        try {
            return $callback();
        } finally {
            // Always release the guards, or later events in this request skip the automation.
            unset(static::$running[$guardKey]);
            static::$depth = max(0, static::$depth - 1);
        }
    }

    /**
     * The router path a task takes: the first path, top to bottom, whose rules match. A path with
     * unfinished rules never matches (AutomationConditions fails closed). No match: else.
     */
    protected function routerBranch(AutomationContext $context, array $step)
    {
        foreach ((array) ($step['settings']['paths'] ?? []) as $path) {
            $id = is_array($path) ? sanitize_key($path['id'] ?? '') : '';
            if ($id && AutomationConditions::passes($context, (array) ($path['settings'] ?? []))) {
                return $id;
            }
        }

        return AutomationFlow::ROUTER_ELSE;
    }

    /** Id of the step at the top frame's position, or '' at the end of that list. */
    protected function nextStepId(array $frames)
    {
        $frame = $frames[count($frames) - 1];
        $step = $frame['steps'][$frame['index']] ?? null;

        return is_array($step) ? sanitize_key($step['id'] ?? '') : '';
    }

    /** True when the step right before the resume position is the delay with this id. */
    protected function resumesAfterDelay(array $frames, $delayStepId)
    {
        $frame = $frames[count($frames) - 1];
        $previous = $frame['steps'][$frame['index'] - 1] ?? null;

        return is_array($previous)
            && sanitize_key($previous['type'] ?? '') === 'delay'
            && sanitize_key($previous['id'] ?? '') === $delayStepId;
    }

    /**
     * Walk the step tree from $path. Each frame is one step list and the index of the step
     * to run next in it; a condition pushes the frame of the branch it picked.
     */
    protected function execute(Automation $automation, AutomationAnalytics $run, AutomationContext $context, array $path)
    {
        $frames = $this->framesFromPath((array) $automation->steps, $path);
        if ($frames === null) {
            $this->cancel($automation, $run, __('Automation changed while this run was waiting.', 'fluent-boards'));
            return;
        }

        $results = $run->step_results ?: [];

        while ($frames) {
            $top = count($frames) - 1;

            // End of a list: leave the branch and continue after its condition.
            if ($frames[$top]['index'] >= count($frames[$top]['steps'])) {
                array_pop($frames);
                if ($frames) {
                    $frames[count($frames) - 1]['index']++;
                }
                continue;
            }

            $step = (array) $frames[$top]['steps'][$frames[$top]['index']];
            $type = sanitize_key($step['type'] ?? '');

            $run->current_step = count($results);
            $run->step_path = AutomationFlow::pathToString($this->pathFromFrames($frames));
            $run->updated_at = current_time('mysql');
            $run->save();

            if ($type === 'delay') {
                $frames[$top]['index']++;
                // Remember what runs next so a resume can tell whether the flow was edited meanwhile.
                $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_WAITING, '', [
                    'next_step_id' => $this->nextStepId($frames),
                ]);
                $run->step_results = $results;
                $this->delay($run, $step, $this->pathFromFrames($frames));
                return;
            }

            if ($type === 'condition' || $type === 'router') {
                $branch = $type === 'condition'
                    ? (AutomationConditions::passes($context, (array) ($step['settings'] ?? [])) ? 'yes' : 'no')
                    : $this->routerBranch($context, $step);
                $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_SUCCESS, '', ['branch' => $branch]);
                $run->step_results = $results;

                $frames[] = [
                    'steps' => array_values((array) ($step['branches'][$branch] ?? [])),
                    'index' => 0,
                    'branch' => $branch,
                ];
                continue;
            }

            if ($type === 'action') {
                $action = $this->registry->getAction($step['key'] ?? '');
                if (!$action) {
                    $message = __('Automation action is not registered.', 'fluent-boards');
                    $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_FAILED, $message);
                    $run->step_results = $results;
                    $run->save();

                    throw new \Exception($message);
                }

                try {
                    $actionResult = $action->handle($context, $step['settings'] ?? []);
                } catch (\Throwable $e) {
                    $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_FAILED, $e->getMessage());
                    $run->step_results = $results;
                    $run->save();

                    throw $e;
                }

                $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_SUCCESS, '', $actionResult);
                $run->step_results = $results;
                $frames[$top]['index']++;
                continue;
            }

            $results[] = $this->stepResult($step, Constant::AUTOMATION_RUN_SUCCESS, __('Unsupported automation step ignored.', 'fluent-boards'));
            $run->step_results = $results;
            $frames[$top]['index']++;
        }

        $this->complete($automation, $run, Constant::AUTOMATION_RUN_SUCCESS);
    }

    /**
     * Rebuild the frame stack for a path such as [2, 'yes', 0]. The last index may point one past
     * the end of its list (a delay was the last step there). Returns null when the path no longer
     * fits the saved steps.
     */
    protected function framesFromPath(array $steps, array $path)
    {
        $frames = [['steps' => array_values($steps), 'index' => 0, 'branch' => '']];
        $length = count($path);

        if (!$length || $length % 2 === 0) {
            return null;
        }

        for ($position = 0; $position < $length; $position += 2) {
            $last = count($frames) - 1;
            $list = $frames[$last]['steps'];
            $index = (int) $path[$position];

            if ($position === $length - 1) {
                if ($index < 0 || $index > count($list)) {
                    return null;
                }
                $frames[$last]['index'] = $index;

                return $frames;
            }

            $branch = (string) $path[$position + 1];
            $step = $list[$index] ?? null;
            if (!AutomationFlow::isBranching($step)
                || !in_array($branch, AutomationFlow::branchKeys($step), true)) {
                return null;
            }

            $frames[$last]['index'] = $index;
            $frames[] = [
                'steps' => array_values((array) ($step['branches'][$branch] ?? [])),
                'index' => 0,
                'branch' => $branch,
            ];
        }

        return null;
    }

    protected function pathFromFrames(array $frames)
    {
        $path = [];

        foreach ($frames as $position => $frame) {
            if ($position > 0) {
                $path[] = $frame['branch'];
            }
            $path[] = (int) $frame['index'];
        }

        return $path;
    }

    protected function delay(AutomationAnalytics $run, array $step, array $nextPath)
    {
        $settings = $step['settings'] ?? [];
        $amount = max(1, absint($settings['delay_amount'] ?? 1));
        $unit = sanitize_key($settings['delay_unit'] ?? 'minutes');
        // Cap waits so resume_at stays inside the TIMESTAMP range.
        $seconds = min(max($this->delaySeconds($amount, $unit), self::MIN_DELAY_SECONDS), self::MAX_DELAY_SECONDS);
        $timestamp = time() + $seconds;

        $run->status = Constant::AUTOMATION_RUN_WAITING;
        $run->step_path = AutomationFlow::pathToString($nextPath);
        $run->resume_at = gmdate('Y-m-d H:i:s', $timestamp);
        $run->updated_at = current_time('mysql');

        if (function_exists('as_schedule_single_action')) {
            $run->scheduled_action_id = as_schedule_single_action(
                $timestamp,
                'fluent_boards/automation_resume',
                [$run->id],
                'fluent-boards'
            );
        } else {
            wp_schedule_single_event($timestamp, 'fluent_boards/automation_resume', [$run->id]);
        }

        $run->save();
    }

    protected function delaySeconds($amount, $unit)
    {
        if ($unit === 'weeks') {
            return $amount * WEEK_IN_SECONDS;
        }

        if ($unit === 'days') {
            return $amount * DAY_IN_SECONDS;
        }

        if ($unit === 'hours') {
            return $amount * HOUR_IN_SECONDS;
        }

        return $amount * MINUTE_IN_SECONDS;
    }

    protected function complete(Automation $automation, AutomationAnalytics $run, $status)
    {
        $run->status = $status;
        $run->step_path = '';
        $run->completed_at = current_time('mysql');
        $run->updated_at = current_time('mysql');
        $run->duration_ms = $this->durationMs($run->created_at);
        $run->save();

        $automation->last_ran_at = current_time('mysql');
        if ($status === Constant::AUTOMATION_RUN_SUCCESS) {
            $automation->last_error = '';
        }
        $automation->save();
    }

    protected function cancel($automation, AutomationAnalytics $run, $message)
    {
        // The delay this run waited on is not waiting any more; otherwise step stats keep
        // counting it as "waiting now".
        $results = (array) ($run->step_results ?: []);
        foreach ($results as $index => $result) {
            if (($result['status'] ?? '') === Constant::AUTOMATION_RUN_WAITING) {
                $results[$index]['status'] = Constant::AUTOMATION_RUN_CANCELLED;
            }
        }
        $run->step_results = $results;

        $run->status = Constant::AUTOMATION_RUN_CANCELLED;
        $run->error_message = sanitize_text_field($message);
        $run->completed_at = current_time('mysql');
        $run->updated_at = current_time('mysql');
        $run->save();
    }

    protected function fail($automation, AutomationAnalytics $run, $message)
    {
        $run->status = Constant::AUTOMATION_RUN_FAILED;
        $run->error_message = sanitize_text_field($message);
        $run->completed_at = current_time('mysql');
        $run->updated_at = current_time('mysql');
        $run->duration_ms = $this->durationMs($run->created_at);
        $run->save();

        if ($automation) {
            $automation->last_error = sanitize_text_field($message);
            $automation->save();
        }
    }

    protected function stepResult(array $step, $status, $message = '', $result = null)
    {
        return [
            'step_id' => sanitize_key($step['id'] ?? ''),
            'type' => sanitize_key($step['type'] ?? ''),
            'key' => sanitize_key($step['key'] ?? ''),
            'status' => $status,
            'message' => $message,
            'result' => $result,
            'created_at' => current_time('mysql'),
        ];
    }

    protected function durationMs($createdAt)
    {
        // created_at is site-local time (current_time('mysql')), so parse it in the site timezone.
        // Not mysql2date('U'): that adds the site offset back and returns a "WP timestamp".
        $startedAt = $createdAt ? date_create((string) $createdAt, wp_timezone()) : false;
        if (!$startedAt) {
            return 0;
        }
        $startedAt = $startedAt->getTimestamp();

        return max(0, (time() - $startedAt) * 1000);
    }
}
