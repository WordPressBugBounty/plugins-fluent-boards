<?php

namespace FluentBoards\Database\Migrations;

class AutomationAnalyticsMigrator
{
    /**
     * Automation analytics and run ledger table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_automation_analytics';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `automation_id` INT UNSIGNED NOT NULL,
                `board_id` INT UNSIGNED NULL DEFAULT 0,
                `task_id` INT UNSIGNED NULL DEFAULT 0,
                `trigger_name` VARCHAR(100) NOT NULL DEFAULT '',
                `status` VARCHAR(20) NOT NULL DEFAULT 'running',
                `current_step` INT UNSIGNED NOT NULL DEFAULT 0,
                `step_path` VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Path of the next step to run, e.g. 2.yes.0',
                `trigger_payload` LONGTEXT NULL COMMENT 'Serialized Array',
                `step_results` LONGTEXT NULL COMMENT 'Serialized Array',
                `scheduled_action_id` BIGINT UNSIGNED NULL,
                `resume_at` TIMESTAMP NULL,
                `error_message` TEXT NULL,
                `duration_ms` BIGINT UNSIGNED NULL DEFAULT 0,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                `completed_at` TIMESTAMP NULL,
                KEY `automation_id` (`automation_id`),
                KEY `board_id` (`board_id`),
                KEY `task_id` (`task_id`),
                KEY `trigger_name` (`trigger_name`),
                KEY `status` (`status`),
                KEY `resume_at` (`resume_at`),
                KEY `created_at` (`created_at`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
