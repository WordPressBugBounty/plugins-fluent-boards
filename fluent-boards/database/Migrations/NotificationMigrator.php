<?php

namespace FluentBoards\Database\Migrations;

class NotificationMigrator
{
    /**
     * Task Management Table.
     *
     * @param  bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_notifications';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` INT UNSIGNED NOT NULL,
                `object_type` VARCHAR(100) NOT NULL,
                `task_id` INT UNSIGNED NULL,
                `action` VARCHAR(255) NULL COMMENT 'this will be the hooks_name like task_created, priority_changed, etc.',
                `activity_by` BIGINT UNSIGNED NOT NULL,
                `description` LONGTEXT NULL,
                `settings` TEXT NULL COMMENT 'JSON Serialized Array',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `object_id` (`object_id`),
                KEY `object_type` (`object_type`),
                KEY `activity_by` (`activity_by`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
