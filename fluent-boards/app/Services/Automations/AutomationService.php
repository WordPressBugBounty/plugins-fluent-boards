<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Models\Automation;
use FluentBoards\App\Models\AutomationAnalytics;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Automations\Actions\SendEmailAction;

class AutomationService
{
    /** One page of the workflow table: only the fields the table shows. */
    public function getAutomationPage($page, $search, $status, array $matchingTriggers)
    {
        $perPage = 25;

        $counts = ['all' => 0];
        $statusRows = Automation::selectRaw('status, COUNT(*) AS total')->groupBy('status')->get();
        foreach ($statusRows as $statusRow) {
            $counts[$statusRow->status] = (int) $statusRow->total;
            $counts['all'] += (int) $statusRow->total;
        }

        $query = Automation::with('board:id,title');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
            $triggerNames = array_map('sanitize_key', array_filter($matchingTriggers, 'is_string'));

            $query->where(function ($q) use ($like, $triggerNames) {
                $q->where('title', 'LIKE', $like)
                    ->orWhere('status', 'LIKE', $like)
                    ->orWhere('trigger_name', 'LIKE', $like);

                if ($triggerNames) {
                    $q->orWhereIn('trigger_name', $triggerNames);
                }
            });
        }

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $automations = $query->select(['id', 'title', 'status', 'board_id', 'trigger_name', 'settings', 'steps', 'last_ran_at'])
            ->orderBy('id', 'DESC')
            ->forPage($page, $perPage)
            ->get();

        $rows = [];
        foreach ($automations as $automation) {
            $actions = array_filter(AutomationFlow::flatten($automation->steps), function ($step) {
                return ($step['type'] ?? '') === 'action';
            });
            $extraTriggers = (array) ($automation->settings['extra_triggers'] ?? []);

            $rows[] = [
                'id'                  => $automation->id,
                'title'               => $automation->title,
                'status'              => $automation->status,
                'board'               => $automation->board,
                'trigger_name'        => $automation->trigger_name,
                'last_ran_at'         => $automation->last_ran_at,
                'extra_trigger_count' => count($extraTriggers),
                'action_count'        => count($actions),
            ];
        }

        return [
            'automations' => $rows,
            'page'        => $page,
            'total'       => $total,
            'counts'      => $counts,
        ];
    }

    public function getActiveAutomationsForTrigger($triggerName, $boardId = 0)
    {
        $boardId = absint($boardId);
        $triggerName = sanitize_key($triggerName);

        return Automation::query()
            ->where('status', Constant::AUTOMATION_STATUS_ACTIVE)
            ->where(function ($query) use ($triggerName) {
                // trigger_names lists every trigger; rows saved before it existed only have trigger_name.
                $query->where('trigger_name', $triggerName)
                    ->orWhere('trigger_names', 'LIKE', '%,' . $GLOBALS['wpdb']->esc_like($triggerName) . ',%');
            })
            ->where(function ($query) use ($boardId) {
                $query->where('board_id', 0)
                    ->orWhereNull('board_id');

                if ($boardId) {
                    $query->orWhere('board_id', $boardId);
                }
            })
            ->orderBy('id', 'ASC')
            ->get();
    }

    public function find($id)
    {
        return Automation::with('board:id,title')->find(absint($id));
    }

    public function create(array $data)
    {
        $data = $this->sanitizeAutomation($data);
        $data['created_by'] = get_current_user_id();
        $data['updated_by'] = get_current_user_id();
        $data['created_at'] = current_time('mysql');
        $data['updated_at'] = current_time('mysql');

        return Automation::create($data)->load('board:id,title');
    }

    public function update($id, array $data)
    {
        $automation = Automation::find(absint($id));

        if (!$automation) {
            throw new \Exception(esc_html__('Automation not found', 'fluent-boards'), 404);
        }

        $data = $this->sanitizeAutomation($data, $automation);
        $data['updated_by'] = get_current_user_id();
        $data['updated_at'] = current_time('mysql');

        $automation->fill($data);
        $automation->save();

        // The list shows the board name; a board change must not leave the old one loaded.
        return $automation->load('board:id,title');
    }

    public function duplicate($id)
    {
        $automation = Automation::find(absint($id));

        if (!$automation) {
            throw new \Exception(esc_html__('Automation not found', 'fluent-boards'), 404);
        }

        return $this->create([
            'title' => sprintf(__('%s copy', 'fluent-boards'), $automation->title),
            'status' => Constant::AUTOMATION_STATUS_DRAFT,
            'board_id' => $automation->board_id,
            'trigger_name' => $automation->trigger_name,
            'trigger_settings' => $automation->trigger_settings,
            'steps' => $automation->steps,
            'flow' => $automation->flow,
            'settings' => $automation->settings,
        ]);
    }

    /**
     * Activate, pause, duplicate or delete several automations. Activation runs the same checks as
     * saving, so an unfinished automation fails on its own and the others still go through.
     */
    public function bulkActions($automationIds, $action)
    {
        if (empty($automationIds) || !is_array($automationIds)) {
            throw new \Exception(esc_html__('No automations selected', 'fluent-boards'));
        }

        if (count($automationIds) > 150) {
            throw new \Exception(esc_html__('Cannot process more than 150 automations at once. Please select fewer automations.', 'fluent-boards'));
        }

        $automations = Automation::whereIn('id', $automationIds)->get();

        if ($automations->isEmpty()) {
            throw new \Exception(esc_html__('No valid automations found', 'fluent-boards'));
        }

        $successfulAutomations = [];
        $failedAutomations = [];

        foreach ($automations as $automation) {
            try {
                switch ($action) {
                    case 'activate':
                        $successfulAutomations[] = $this->update($automation->id, ['status' => Constant::AUTOMATION_STATUS_ACTIVE]);
                        break;

                    case 'pause':
                        $successfulAutomations[] = $this->update($automation->id, ['status' => Constant::AUTOMATION_STATUS_PAUSED]);
                        break;

                    case 'duplicate':
                        $successfulAutomations[] = $this->duplicate($automation->id);
                        break;

                    case 'delete':
                        $this->delete($automation->id);
                        $successfulAutomations[] = $automation;
                        break;

                    default:
                        throw new \Exception(esc_html__('Invalid action specified', 'fluent-boards'));
                }
            } catch (\Exception $e) {
                $failedAutomations[] = [
                    'id' => $automation->id,
                    'title' => $automation->title,
                    'error' => $e->getMessage()
                ];
            }
        }

        $successCount = count($successfulAutomations);
        $failureCount = count($failedAutomations);

        if ($failureCount === 0) {
            // translators: %d is the number of automations updated successfully
            $message = sprintf(__('%d automations updated successfully', 'fluent-boards'), $successCount);
        } elseif ($successCount === 0) {
            // translators: %d is the number of automations that failed to update
            $message = sprintf(__('Failed to update %d automations', 'fluent-boards'), $failureCount);
        } else {
            // translators: 1: number of automations updated successfully; 2: number of automations that failed
            $message = sprintf(__('%1$d automations updated successfully, %2$d failed', 'fluent-boards'), $successCount, $failureCount);
        }

        return [
            'successful_automations' => $successfulAutomations,
            'failed_automations' => $failedAutomations,
            'message' => $message
        ];
    }

    public function delete($id)
    {
        $automation = Automation::find(absint($id));

        if (!$automation) {
            throw new \Exception(esc_html__('Automation not found', 'fluent-boards'), 404);
        }

        AutomationAnalytics::where('automation_id', $automation->id)->delete();

        return $automation->delete();
    }

    public function logs($automationId, $limit = 20)
    {
        return AutomationAnalytics::where('automation_id', absint($automationId))
            ->orderBy('id', 'DESC')
            ->limit(absint($limit) ?: 20)
            ->get();
    }

    public function activityLogs($limit = 50, $automationId = 0)
    {
        $limit = min(max(absint($limit) ?: 50, 1), 100);
        $logs = AutomationAnalytics::query()
            ->when(absint($automationId), function ($query) use ($automationId) {
                $query->where('automation_id', absint($automationId));
            })
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        $automationIds = [];
        foreach ($logs as $log) {
            $automationIds[] = absint($log->automation_id);
        }

        $automations = [];
        if ($automationIds) {
            $automations = Automation::query()
                ->whereIn('id', array_values(array_unique(array_filter($automationIds))))
                ->get()
                ->keyBy('id');
        }

        $items = [];
        foreach ($logs as $log) {
            $automation = !empty($automations[$log->automation_id]) ? $automations[$log->automation_id] : null;

            $items[] = [
                'id' => absint($log->id),
                'automation_id' => absint($log->automation_id),
                'automation_title' => $automation ? $automation->title : '',
                'automation_status' => $automation ? $automation->status : '',
                'board_id' => absint($log->board_id),
                'task_id' => absint($log->task_id),
                'trigger_name' => $log->trigger_name,
                'status' => $log->status,
                'current_step' => absint($log->current_step),
                'duration_ms' => absint($log->duration_ms),
                'error_message' => $log->error_message,
                'step_results' => $this->formatStepResults($log->step_results),
                // Timestamps are cast to Carbon; send the plain MySQL string like the other dates.
                'created_at' => (string) $log->created_at,
                'updated_at' => (string) $log->updated_at,
                'completed_at' => $log->completed_at,
                'resume_at' => $log->resume_at,
            ];
        }

        return $items;
    }

    /**
     * Run counts per automation, per trigger and per step. Step counts come from the latest
     * $limit runs; automation and trigger totals cover every run.
     *
     * @param int $limit
     * @param int $automationId Only this automation when set.
     */
    public function analytics($limit = 500, $automationId = 0)
    {
        $limit = min(max(absint($limit) ?: 500, 50), 1000);
        $automationId = absint($automationId);
        $automations = Automation::query()
            ->when($automationId, function ($query) use ($automationId) {
                $query->where('id', $automationId);
            })
            ->orderBy('id', 'DESC')
            ->get();
        $summaries = [];

        foreach ($automations as $automation) {
            $summary = $this->baseAutomationAnalytics($automation);

            foreach (AutomationFlow::flatten((array) $automation->steps) as $index => $step) {
                if (is_array($step)) {
                    $this->ensureStepAnalytics($summary, $step, $index);
                }
            }

            $summaries[$automation->id] = $summary;
        }

        $statusRows = AutomationAnalytics::query()
            ->when($automationId, function ($query) use ($automationId) {
                $query->where('automation_id', $automationId);
            })
            ->selectRaw('automation_id, status, COUNT(*) as total_count, AVG(duration_ms) as avg_duration_ms, MAX(created_at) as last_run_at')
            ->groupBy('automation_id')
            ->groupBy('status')
            ->get();

        foreach ($statusRows as $row) {
            $automationId = absint($row->automation_id);
            $summary = $this->ensureAutomationAnalytics($summaries, $automationId);
            $status = sanitize_key($row->status ?: 'unknown');
            $count = absint($row->total_count);

            $summary['total_runs'] += $count;
            $summary['status_counts'][$status] = ($summary['status_counts'][$status] ?? 0) + $count;
            $summary['_duration_total'] += (float) $row->avg_duration_ms * $count;
            $summary['last_run_at'] = $this->latestDate($summary['last_run_at'], $row->last_run_at);

            $summaries[$automationId] = $summary;
        }

        $triggerRows = AutomationAnalytics::query()
            ->when($automationId, function ($query) use ($automationId) {
                $query->where('automation_id', $automationId);
            })
            ->selectRaw('automation_id, trigger_name, COUNT(*) as total_count')
            ->groupBy('automation_id')
            ->groupBy('trigger_name')
            ->get();

        foreach ($triggerRows as $row) {
            $rowAutomationId = absint($row->automation_id);
            $summary = $this->ensureAutomationAnalytics($summaries, $rowAutomationId);
            $summary['trigger_counts'][sanitize_key($row->trigger_name)] = absint($row->total_count);
            $summaries[$rowAutomationId] = $summary;
        }

        $recentLogs = AutomationAnalytics::query()
            ->when($automationId, function ($query) use ($automationId) {
                $query->where('automation_id', $automationId);
            })
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get();

        foreach ($recentLogs as $log) {
            $automationId = absint($log->automation_id);
            $summary = $this->ensureAutomationAnalytics($summaries, $automationId);
            $summary['sampled_runs'] += 1;

            foreach ($this->formatStepResults($log->step_results) as $index => $result) {
                $step = [
                    'id' => $result['step_id'],
                    'type' => $result['type'],
                    'key' => $result['key'],
                ];
                $stepKey = $this->stepAnalyticsKey($step, $index);
                $this->ensureStepAnalytics($summary, $step, $index);

                $status = sanitize_key($result['status'] ?: 'unknown');
                $summary['steps'][$stepKey]['total_runs'] += 1;
                $summary['steps'][$stepKey]['status_counts'][$status] = ($summary['steps'][$stepKey]['status_counts'][$status] ?? 0) + 1;

                // Yes / No conditions and multi paths record the path each run took.
                $branch = is_array($result['result']) ? sanitize_key($result['result']['branch'] ?? '') : '';
                if ($branch) {
                    $summary['steps'][$stepKey]['branch_counts'][$branch] = ($summary['steps'][$stepKey]['branch_counts'][$branch] ?? 0) + 1;
                }
                $summary['steps'][$stepKey]['last_ran_at'] = $this->latestDate(
                    $summary['steps'][$stepKey]['last_ran_at'],
                    $result['created_at']
                );
            }

            $summaries[$automationId] = $summary;
        }

        foreach ($summaries as $automationId => $summary) {
            $totalRuns = max(1, (int) $summary['total_runs']);
            $summary['avg_duration_ms'] = $summary['total_runs']
                ? (int) round($summary['_duration_total'] / $totalRuns)
                : 0;
            unset($summary['_duration_total']);

            $summary['steps'] = array_values($summary['steps']);
            usort($summary['steps'], function ($first, $second) {
                return ($first['position'] <=> $second['position']) ?: strcmp($first['id'], $second['id']);
            });

            $summaries[$automationId] = $summary;
        }

        return [
            'sample_size' => $limit,
            'automations' => array_values($summaries),
        ];
    }

    protected function formatStepResults($results)
    {
        if (!is_array($results)) {
            return [];
        }

        $formatted = [];

        foreach ($results as $index => $result) {
            if (!is_array($result)) {
                continue;
            }

            $formatted[] = [
                'step_id' => sanitize_key($result['step_id'] ?? 'step_' . ($index + 1)),
                'type' => sanitize_key($result['type'] ?? ''),
                'key' => sanitize_key($result['key'] ?? ''),
                'status' => sanitize_key($result['status'] ?? ''),
                'message' => sanitize_text_field($result['message'] ?? ''),
                'result' => $this->sanitizeDeep($result['result'] ?? []),
                'created_at' => sanitize_text_field($result['created_at'] ?? ''),
            ];
        }

        return $formatted;
    }

    protected function baseAutomationAnalytics(?Automation $automation = null)
    {
        return [
            'automation_id' => $automation ? absint($automation->id) : 0,
            'automation_title' => $automation ? $automation->title : '',
            'automation_status' => $automation ? $automation->status : '',
            'trigger_name' => $automation ? $automation->trigger_name : '',
            'total_runs' => 0,
            'sampled_runs' => 0,
            'status_counts' => $this->emptyRunStatusCounts(),
            'avg_duration_ms' => 0,
            '_duration_total' => 0,
            'last_run_at' => '',
            'trigger_counts' => [],
            'steps' => [],
        ];
    }

    protected function ensureAutomationAnalytics(array &$summaries, $automationId)
    {
        $automationId = absint($automationId);

        if (!isset($summaries[$automationId])) {
            $summary = $this->baseAutomationAnalytics();
            $summary['automation_id'] = $automationId;
            $summary['automation_title'] = sprintf(__('Deleted automation #%d', 'fluent-boards'), $automationId);
            $summaries[$automationId] = $summary;
        }

        return $summaries[$automationId];
    }

    protected function ensureStepAnalytics(array &$summary, array $step, $index)
    {
        $stepKey = $this->stepAnalyticsKey($step, $index);

        if (!isset($summary['steps'][$stepKey])) {
            $summary['steps'][$stepKey] = [
                'id' => sanitize_key($step['id'] ?? $stepKey),
                'position' => absint($index) + 1,
                'type' => sanitize_key($step['type'] ?? ''),
                'key' => sanitize_key($step['key'] ?? ''),
                'total_runs' => 0,
                'status_counts' => $this->emptyRunStatusCounts(),
                'branch_counts' => [],
                'last_ran_at' => '',
            ];
        }
    }

    protected function stepAnalyticsKey(array $step, $index)
    {
        $stepId = sanitize_key($step['id'] ?? '');
        if ($stepId) {
            return $stepId;
        }

        return sanitize_key(($step['type'] ?? 'step') . '_' . ($step['key'] ?? 'item') . '_' . ((int) $index + 1));
    }

    protected function emptyRunStatusCounts()
    {
        return [
            Constant::AUTOMATION_RUN_SUCCESS => 0,
            Constant::AUTOMATION_RUN_FAILED => 0,
            Constant::AUTOMATION_RUN_RUNNING => 0,
            Constant::AUTOMATION_RUN_WAITING => 0,
            Constant::AUTOMATION_RUN_SKIPPED => 0,
            Constant::AUTOMATION_RUN_CANCELLED => 0,
            'unknown' => 0,
        ];
    }

    protected function latestDate($currentDate, $candidateDate)
    {
        if (!$candidateDate) {
            return $currentDate ?: '';
        }

        if (!$currentDate || strtotime($candidateDate) > strtotime($currentDate)) {
            return $candidateDate;
        }

        return $currentDate;
    }

    /** Sanitize saved settings and reject activation with invalid email recipients. */
    public function sanitizeAutomation(array $data, ?Automation $automation = null)
    {
        $status = sanitize_key($data['status'] ?? ($automation ? $automation->status : Constant::AUTOMATION_STATUS_DRAFT));
        $allowedStatuses = [
            Constant::AUTOMATION_STATUS_ACTIVE,
            Constant::AUTOMATION_STATUS_DRAFT,
            Constant::AUTOMATION_STATUS_PAUSED,
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            $status = Constant::AUTOMATION_STATUS_DRAFT;
        }

        // Validate conditions and original email addresses before activation, so text sanitization
        // cannot turn invalid input into a valid address.
        $steps = $data['steps'] ?? ($automation ? $automation->steps : []);
        if ($status === Constant::AUTOMATION_STATUS_ACTIVE && is_array($steps)) {
            foreach (AutomationFlow::flatten($steps) as $step) {
                if (is_array($step) && sanitize_key($step['type'] ?? '') === 'condition'
                    && !AutomationConditions::isComplete($step['settings'] ?? [])) {
                    throw new \Exception(esc_html__('Finish every Yes / No condition before activating this automation.', 'fluent-boards'));
                }

                if (is_array($step) && sanitize_key($step['type'] ?? '') === 'router') {
                    $paths = (array) ($step['settings']['paths'] ?? []);
                    $unfinished = array_filter($paths, function ($path) {
                        return !is_array($path) || !AutomationConditions::isComplete($path['settings'] ?? []);
                    });
                    if (!$paths || $unfinished) {
                        throw new \Exception(esc_html__('Give every path of a multi path step finished rules before activating this automation.', 'fluent-boards'));
                    }
                }

                if (!is_array($step) || sanitize_key($step['type'] ?? '') !== 'action'
                    || sanitize_key($step['key'] ?? '') !== 'send_email') {
                    continue;
                }

                if (!(new SendEmailAction())->hasValidRecipients((array) ($step['settings'] ?? []))) {
                    throw new \Exception(esc_html__('Choose who gets each email before activating this automation.', 'fluent-boards'));
                }
            }
        }

        if ($status === Constant::AUTOMATION_STATUS_ACTIVE) {
            $this->assertTriggersComplete($data, $automation);
        }

        if (isset($data['steps']) && AutomationFlow::exceedsLimits($data['steps'])) {
            throw new \Exception(esc_html(sprintf(
                /* translators: 1: maximum number of steps, 2: maximum nesting depth */
                __('An automation can have at most %1$d steps and %2$d levels of Yes / No paths.', 'fluent-boards'),
                AutomationFlow::MAX_STEPS,
                AutomationFlow::MAX_DEPTH
            )));
        }

        $triggerName = sanitize_key($data['trigger_name'] ?? ($automation ? $automation->trigger_name : ''));
        $triggerSettings = $this->sanitizeDeep($data['trigger_settings'] ?? ($automation ? $automation->trigger_settings : []));
        $triggerSettings = is_array($triggerSettings) ? $triggerSettings : [];

        // The first trigger's board filter is the automation's scope. An empty value means "any board".
        if (array_key_exists('board_id', $triggerSettings)) {
            $boardId = absint($triggerSettings['board_id']);
        } else {
            $boardId = absint($data['board_id'] ?? ($automation ? $automation->board_id : 0));
        }
        $triggerSettings['board_id'] = $boardId;

        $settings = $this->sanitizeDeep($data['settings'] ?? ($automation ? $automation->settings : []));
        $settings = is_array($settings) ? $settings : [];
        $settings['extra_triggers'] = $this->sanitizeExtraTriggers(
            $data['settings']['extra_triggers'] ?? ($automation ? ($automation->settings['extra_triggers'] ?? []) : []),
            $boardId
        );

        $triggerNames = array_merge([$triggerName], array_column($settings['extra_triggers'], 'name'));
        $triggerNames = array_values(array_unique(array_filter($triggerNames)));

        $stepCount = 0;

        return [
            'title' => sanitize_text_field($data['title'] ?? ($automation ? $automation->title : __('Untitled automation', 'fluent-boards'))),
            'status' => $status,
            'board_id' => $boardId,
            'trigger_name' => $triggerName,
            'trigger_names' => $triggerNames ? ',' . implode(',', $triggerNames) . ',' : '',
            'trigger_settings' => $triggerSettings,
            'steps' => AutomationFlow::sanitizeSteps(
                $data['steps'] ?? ($automation ? $automation->steps : []),
                [$this, 'sanitizeDeep'],
                1,
                $stepCount
            ),
            'flow' => $this->sanitizeDeep($data['flow'] ?? ($automation ? $automation->flow : ['nodes' => [], 'edges' => []])),
            'settings' => $settings,
        ];
    }

    /** "Label added" must name its labels; with none it would run on every label. */
    protected function assertTriggersComplete(array $data, ?Automation $automation = null)
    {
        $triggers = [[
            'name' => $data['trigger_name'] ?? ($automation ? $automation->trigger_name : ''),
            'settings' => $data['trigger_settings'] ?? ($automation ? $automation->trigger_settings : []),
        ]];
        $extra = $data['settings']['extra_triggers'] ?? ($automation ? ($automation->settings['extra_triggers'] ?? []) : []);
        foreach ((array) $extra as $trigger) {
            if (is_array($trigger)) {
                $triggers[] = $trigger;
            }
        }

        foreach ($triggers as $trigger) {
            if (sanitize_key($trigger['name'] ?? '') !== 'label_added') {
                continue;
            }

            $labelIds = array_filter(array_map('absint', (array) ($trigger['settings']['label_ids'] ?? [])));
            if (!$labelIds) {
                throw new \Exception(esc_html__('Select at least one label for the Label added trigger before activating this automation.', 'fluent-boards'));
            }
        }
    }

    /**
     * Extra triggers share the automation's board, so their own board filter always copies the
     * first trigger's board. Unknown trigger names are kept (an extension may load later).
     */
    protected function sanitizeExtraTriggers($triggers, $boardId)
    {
        $sanitized = [];

        foreach ((array) $triggers as $index => $trigger) {
            if (!is_array($trigger)) {
                continue;
            }

            $name = sanitize_key($trigger['name'] ?? '');
            if (!$name) {
                continue;
            }

            $settings = $this->sanitizeDeep($trigger['settings'] ?? []);
            $settings = is_array($settings) ? $settings : [];
            $settings['board_id'] = absint($boardId);

            $sanitized[] = [
                'id' => sanitize_key($trigger['id'] ?? '') ?: 'trigger_' . ($index + 2),
                'name' => $name,
                'settings' => $settings,
            ];

            if (count($sanitized) >= 9) {
                break;
            }
        }

        return $sanitized;
    }

    /** Public so AutomationFlow::sanitizeSteps() can use it as the settings sanitizer. */
    public function sanitizeDeep($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeDeep($item);
            }
            return $sanitized;
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        if (is_bool($value) || $value === null) {
            return $value;
        }

        $value = (string) $value;

        // Keep line breaks for multi-line settings such as comment or email bodies.
        return strpos($value, "\n") === false ? sanitize_text_field($value) : sanitize_textarea_field($value);
    }
}
