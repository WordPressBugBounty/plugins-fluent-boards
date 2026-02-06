<?php

namespace FluentBoards\Database\Migrations;

class BoardTermMigrator
{
    /**
     * @param       $isForced
     * @return void
     */
    public static function migrate($isForced = true)
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'fbs_board_terms';
        /*
         * This Schema is for the Board Labels and Stages
         */
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `board_id` INT UNSIGNED NOT NULL,
                `title` VARCHAR(100) NULL COMMENT 'Title of the stage or label. Incase of label tile can be null with color only',
                `slug` VARCHAR(100) NULL COMMENT 'Slug of the stage or label',
                `type` VARCHAR(50) NOT NULL DEFAULT 'stage' COMMENT 'stage or label',
                `position` decimal(10,2) NOT NULL DEFAULT '1' COMMENT 'Position of the stage or label. 1 = first, 2 = second, etc.',
                `color` VARCHAR(50) NULL COMMENT 'Text Color of the stage or label',
                `bg_color` VARCHAR(50) NULL COMMENT 'Background Color of the stage or label',
                `settings` TEXT NULL COMMENT 'Serialized Settings',
                `archived_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `title` (`title`),
                KEY `type` (`type`),
                KEY `position` (`position`),
                KEY `slug` (`slug`)
            ) $charsetCollate;";
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        } else {
            // change column type from int to decimal - for already installed sites
            $column_name = 'position';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and column name cannot be prepared
            $preparedQuery = $wpdb->prepare("DESCRIBE $table %s", $column_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Variable contains prepared query, schema check, caching not applicable
            $dataType = $wpdb->get_row($preparedQuery);
            if (strpos($dataType->Type, 'int') !== false) {
                $sql = $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and column name cannot be prepared
                    "ALTER TABLE $table MODIFY $column_name decimal(10,2) NOT NULL DEFAULT '1' COMMENT 'Position: 1 = top/first, 2 = second/second in top, etc.';"
                );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Variable contains prepared query, schema modification, caching not applicable
                $wpdb->query($sql);
            }
        }

    }
}
