<?php

namespace FluentBoards\Database\Migrations;

class NotificationUserMigrator
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
        $table = $wpdb->prefix . 'fbs_notification_users';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `notification_id` INT UNSIGNED NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `marked_read_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `notification_id` (`notification_id`),
                KEY `user_id` (`user_id`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
