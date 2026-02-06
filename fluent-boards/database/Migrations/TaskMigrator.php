<?php

namespace FluentBoards\Database\Migrations;

class TaskMigrator
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
        $table = $wpdb->prefix . 'fbs_tasks';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema check query, caching not applicable for migrations
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name cannot be prepared in CREATE TABLE
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL COMMENT 'Parent task_id if Subtask',
                `board_id` INT UNSIGNED NULL,
                `crm_contact_id` BIGINT UNSIGNED NULL COMMENT 'User ID, Contact ID, Deal ID, Subscriber ID etc.',
                `title` TEXT NULL COMMENT 'Title or Name of the Task , It can be longer than 255 characters.',
                `slug` VARCHAR(255) NULL,
                `type` VARCHAR(50) NULL COMMENT 'task, deal, idea, to-do etc.',
                `status` VARCHAR(50) NULL DEFAULT 'open' COMMENT 'open, completed, for Boards, Won or Lost for Pipelines',
                `stage_id` INT UNSIGNED NULL,
                `source` VARCHAR(50) NULL DEFAULT 'web' COMMENT 'web, funnel, contact-section etc.',
                `source_id` VARCHAR(255) NULL,
                `priority` VARCHAR(50) NULL DEFAULT 'low' COMMENT 'low, medium, high', 
                `description` LONGTEXT NULL,
                `lead_value` DECIMAL(10,2) DEFAULT 0.00,
                `created_by` BIGINT UNSIGNED NULL,
                `position` decimal(10,2) NOT NULL DEFAULT '1' COMMENT 'Position of the stage or label. 1 = first, 2 = second, etc.',
                `comments_count` INT UNSIGNED NULL DEFAULT 0,
                `issue_number` INT UNSIGNED NULL COMMENT 'Board Specific Issue Number to track the task',
                `reminder_type` VARCHAR(100) NULL DEFAULT 'none',
                `settings` TEXT NULL COMMENT 'Serialized',
                `remind_at` TIMESTAMP NULL,
                `started_at` TIMESTAMP NULL,
                `due_at` TIMESTAMP NULL,
                `last_completed_at` TIMESTAMP NULL,
                `archived_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `type` (`type`),
                KEY `board_id` (`board_id`),
                KEY `slug` (`slug`),
                KEY `comments_count` (`comments_count`),
                KEY `issue_number` (`issue_number`),
                KEY `crm_contact_id` (`crm_contact_id`),
                KEY `due_at` (`due_at`),
                KEY `priority` (`priority`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        } else {
            $column_name = 'source_id';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and column name cannot be prepared
            $preparedQuery = $wpdb->prepare("DESCRIBE $table %s", $column_name);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Variable contains prepared query, schema check, caching not applicable
            $dataType = $wpdb->get_row($preparedQuery);
            if (strpos($dataType->Type, 'int') !== false) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and column name cannot be prepared
                $sql = $wpdb->prepare("ALTER TABLE $table MODIFY $column_name VARCHAR(255) NULL");
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Variable contains prepared query, schema modification, caching not applicable
                $wpdb->query($sql);
            }
        }
    }
}
