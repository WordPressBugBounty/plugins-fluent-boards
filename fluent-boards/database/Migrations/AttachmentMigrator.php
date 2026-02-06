<?php

namespace FluentBoards\Database\Migrations;

class AttachmentMigrator
{
    /**
     * Attachments Table.
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix . 'fbs_attachments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` INT UNSIGNED NOT NULL COMMENT 'Task ID or Comment ID or Board ID',
                `object_type` VARCHAR(100) DEFAULT 'TASK' COMMENT 'TASK|COMMENT|BOARD',
                `attachment_type` VARCHAR(100) NULL,
                `file_path` TEXT NULL,
                `full_url` TEXT NULL,
                `settings` TEXT NULL,
                `title` VARCHAR(192) NULL,
                `file_hash` VARCHAR(192) NULL,
                `driver` VARCHAR(100) DEFAULT 'local',
                `status` VARCHAR(100) NULL DEFAULT 'ACTIVE' COMMENT 'ACTIVE|INACTIVE|DELETED',
                `file_size` VARCHAR(100) NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `object_type` (`object_type`),
                KEY `object_id` (`object_id`),
                KEY `attachment_type` (`attachment_type`),
                KEY `status` (`status`),
                KEY `file_hash` (`file_hash`),
                KEY `driver` (`driver`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
