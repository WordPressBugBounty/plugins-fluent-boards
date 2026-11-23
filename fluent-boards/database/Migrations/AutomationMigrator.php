<?php

namespace FluentBoards\Database\Migrations;

class AutomationMigrator
{
    /**
     * Automations table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_automations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `title` VARCHAR(192) NOT NULL DEFAULT '',
                `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
                `board_id` INT UNSIGNED NULL DEFAULT 0,
                `trigger_name` VARCHAR(100) NOT NULL DEFAULT '',
                `trigger_names` TEXT NULL COMMENT 'Every trigger, comma wrapped: ,a,b,',
                `trigger_settings` LONGTEXT NULL COMMENT 'Serialized Array',
                `steps` LONGTEXT NULL COMMENT 'Serialized Array',
                `flow` LONGTEXT NULL COMMENT 'Serialized Array',
                `settings` LONGTEXT NULL COMMENT 'Serialized Array',
                `last_ran_at` TIMESTAMP NULL,
                `last_error` TEXT NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `updated_by` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `status` (`status`),
                KEY `board_id` (`board_id`),
                KEY `trigger_name` (`trigger_name`),
                KEY `status_trigger_board` (`status`, `trigger_name`, `board_id`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
