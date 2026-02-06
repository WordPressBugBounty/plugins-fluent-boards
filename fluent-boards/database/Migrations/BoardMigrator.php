<?php

namespace FluentBoards\Database\Migrations;

class BoardMigrator
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
        $table = $wpdb->prefix . 'fbs_boards';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL COMMENT 'For SuperBoard like Project or Company, for sub-board etc.',
                `title` TEXT NULL COMMENT 'Title of the board , It can be longer than 255 characters.',
                `description` LONGTEXT NULL,
                `type` VARCHAR(50) NULL COMMENT 'type will be to-do/sales-pipeline/roadmap/task etc.',
                `currency` VARCHAR(50) NULL,
                `background` TEXT NULL COMMENT 'Serialized Array',
                `settings` TEXT NULL COMMENT 'Serialized Array',
                `created_by` INT UNSIGNED NOT NULL,
                `archived_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `parent_id` (`parent_id`),
                KEY `type` (`type`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
