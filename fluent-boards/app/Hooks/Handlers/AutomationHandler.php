<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Automation;
use FluentBoards\App\Models\AutomationAnalytics;
use FluentBoards\App\Services\Automations\AutomationProcessor;
use FluentBoards\App\Services\Automations\AutomationRegistry;
use FluentBoards\App\Services\Constant;
use FluentBoards\Database\Migrations\AutomationAnalyticsMigrator;
use FluentBoards\Database\Migrations\AutomationMigrator;

class AutomationHandler
{
    // v2 adds fbs_automations.trigger_names and fbs_automation_analytics.step_path (flow canvas).
    // v3 widens fbs_automation_analytics.duration_ms to BIGINT so runs with long waits fit, and
    // fbs_automations.trigger_names to TEXT so ten long extension trigger names still fit.
    const SCHEMA_VERSION = 3;
    const SCHEMA_OPTION = '_fluent_boards_automation_schema_version';
    const ACTIVE_TRIGGERS_OPTION = '_fluent_boards_automation_active_triggers';

    /**
     * Collect triggers/actions on `init`, after every plugin has loaded. Trigger hooks are
     * attached only once the tables exist, so task events never query a missing table.
     */
    public function boot()
    {
        AutomationRegistry::getInstance()->boot(self::isSchemaReady());
    }

    /** Create the automation tables on sites that were installed before automations existed. */
    public function maybeInstallSchema()
    {
        if (self::isSchemaReady() || !current_user_can('manage_options')) {
            return;
        }

        if (self::installSchema()) {
            AutomationRegistry::getInstance()->listen();
        }
    }

    /**
     * Create or update the automation tables. The schema version is recorded only when every
     * required table and column exists, so a failed upgrade is retried on the next admin load.
     *
     * @return bool Whether the schema is ready.
     */
    public static function installSchema()
    {
        // Forced so dbDelta also adds new columns to tables that already exist.
        AutomationMigrator::migrate(true);
        AutomationAnalyticsMigrator::migrate(true);

        if (!self::hasRequiredColumns()) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- surfaced for site debugging only
            error_log('FluentBoards automation schema upgrade did not complete; it will be retried.');
            return false;
        }

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
        self::refreshActiveTriggerNames();

        return true;
    }

    public static function isSchemaReady()
    {
        return (int) get_option(self::SCHEMA_OPTION, 0) >= self::SCHEMA_VERSION;
    }

    /** Columns added by each schema version, checked against the live tables. */
    protected static function hasRequiredColumns()
    {
        global $wpdb;

        $required = [
            'fbs_automations' => ['trigger_names' => 'text'],
            'fbs_automation_analytics' => ['step_path' => '', 'duration_ms' => 'bigint'],
        ];

        foreach ($required as $table => $columns) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema verification during migration
            $types = $wpdb->get_results($wpdb->prepare(
                'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $wpdb->prefix . $table
            ), OBJECT_K);

            foreach ($columns as $column => $type) {
                if (!isset($types[$column]) || ($type && strtolower($types[$column]->DATA_TYPE) !== $type)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function resume($runId)
    {
        // A run that started waiting before an upgrade can resume (from cron) before any admin
        // page load has added the new columns.
        if (!self::isSchemaReady() && self::installSchema()) {
            // init skipped the trigger hooks while the schema was missing; attach them so actions in
            // this resumed run can still start other automations.
            AutomationRegistry::getInstance()->listen();
        }

        (new AutomationProcessor(AutomationRegistry::getInstance()))->resume(absint($runId));
    }

    /** Automations scoped to a deleted board can never run again; remove them and their runs. */
    public function onBoardDeleted($board)
    {
        if (!self::isSchemaReady() || empty($board->id)) {
            return;
        }

        $automationIds = Automation::where('board_id', (int) $board->id)->pluck('id')->toArray();
        if ($automationIds) {
            AutomationAnalytics::whereIn('automation_id', $automationIds)->delete();
            Automation::whereIn('id', $automationIds)->delete();
            self::refreshActiveTriggerNames();
        }
    }

    /**
     * Trigger names used by at least one active automation, kept in an autoloaded option so task
     * events with no automation listening never query the automations table.
     *
     * @return string[]
     */
    public static function activeTriggerNames()
    {
        $names = get_option(self::ACTIVE_TRIGGERS_OPTION, null);

        return is_array($names) ? $names : self::refreshActiveTriggerNames();
    }

    /** Rebuild the active trigger list. Call after any automation is saved, activated or deleted. */
    public static function refreshActiveTriggerNames()
    {
        $names = [];
        $rows = Automation::where('status', Constant::AUTOMATION_STATUS_ACTIVE)
            ->select(['trigger_name', 'trigger_names'])
            ->get();

        foreach ($rows as $row) {
            $names[] = $row->trigger_name;
            $names = array_merge($names, explode(',', (string) $row->trigger_names));
        }

        $names = array_values(array_unique(array_filter(array_map('sanitize_key', $names))));
        update_option(self::ACTIVE_TRIGGERS_OPTION, $names, true);

        return $names;
    }

    /**
     * Delete finished runs older than the retention window, at most once a day. Waiting runs are kept
     * so they can still resume. Change the window with `fluent_boards/automation_run_retention_days`.
     */
    public static function maybePruneRuns()
    {
        if (get_transient('fluent_boards_automation_runs_pruned')) {
            return;
        }
        set_transient('fluent_boards_automation_runs_pruned', 1, DAY_IN_SECONDS);

        $days = absint(apply_filters('fluent_boards/automation_run_retention_days', 30));
        if (!$days) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fbs_automation_analytics';
        $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS);

        // ponytail: one 5000-row batch a day; loop the batches if a site outgrows that.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- bulk retention delete; table name is not user input
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND status != %s LIMIT 5000",
            $cutoff,
            Constant::AUTOMATION_RUN_WAITING
        ));
    }

    /**
     * Queued "Send email" action. One message per recipient, so recipients don't see each other.
     *
     * @param string[] $recipients
     * @param string   $subject
     * @param string   $body HTML, already filled in and sanitized by SendEmailAction.
     */
    public function sendEmail($recipients, $subject, $body)
    {
        foreach ((array) $recipients as $recipient) {
            if (is_email($recipient)) {
                wp_mail($recipient, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }
    }
}
