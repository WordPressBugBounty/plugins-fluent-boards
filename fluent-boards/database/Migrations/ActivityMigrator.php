<?php

namespace FluentBoards\Database\Migrations;

class ActivityMigrator
{
    /**
     * Task Activities Table.
     *
     * @param  bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_activities';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` INT UNSIGNED NOT NULL,
                `object_type` VARCHAR(100) NOT NULL,
                `action` VARCHAR(50) NOT NULL,
                `column` VARCHAR(50) NULL,
                `old_value` VARCHAR(50) NULL,
                `new_value` VARCHAR(50) NULL,
                `description` LONGTEXT NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `settings` TEXT NULL COMMENT 'Serialized Array',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `object_type` (`object_type`),
                KEY `object_id` (`object_id`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
