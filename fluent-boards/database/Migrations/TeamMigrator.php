<?php

namespace FluentBoards\Database\Migrations;

class TeamMigrator
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
        $table = $wpdb->prefix . 'fbs_teams';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL COMMENT 'Parent Team',
                `title` VARCHAR(100) NOT NULL,
                `description` TEXT NULL,
                `type` VARCHAR(50) NOT NULL,
                `visibility` VARCHAR(50) DEFAULT 'VISIBLE' COMMENT 'Visibility of the team (VISIBLE/SECRET)',
                `notifications_enabled` TINYINT(1)  DEFAULT 1,
                `settings` TEXT NULL COMMENT 'Serialized',
                `created_by` BIGINT UNSIGNED NOT NULL COMMENT 'Team Creator User ID',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `type` (`type`),
                KEY `visibility` (`visibility`),
                KEY `created_by` (`created_by`),
                KEY `parent_id` (`parent_id`),
                KEY `notifications_enabled` (`notifications_enabled`),
                KEY `title` (`title`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
