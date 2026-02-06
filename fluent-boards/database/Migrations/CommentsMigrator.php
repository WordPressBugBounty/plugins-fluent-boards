<?php

namespace FluentBoards\Database\Migrations;

class CommentsMigrator
{
    /**
     * Task Activities Table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_comments';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `board_id` INT UNSIGNED NOT NULL,
                `task_id` INT UNSIGNED NOT NULL,
                `parent_id` BIGINT UNSIGNED NULL,
                `type` VARCHAR(50) NULL DEFAULT 'comment' COMMENT 'comment|note|reply',
                `privacy` VARCHAR(50) NULL DEFAULT 'public' COMMENT 'public|private',
                `status` VARCHAR(50) NULL DEFAULT 'published' COMMENT 'published|draft|spam',
                `author_name` VARCHAR(192) NULL DEFAULT '',
                `author_email` VARCHAR(192) NULL DEFAULT '',
                `author_ip` VARCHAR(50) NULL DEFAULT '',
                `description` TEXT NULL,
                `created_by` BIGINT UNSIGNED NULL,
                `settings` TEXT NULL COMMENT 'Serialized Array',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `type` (`type`),
                KEY `task_id` (`task_id`),
                KEY `board_id` (`board_id`),
                KEY `status` (`status`),
                KEY `privacy` (`privacy`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
