<?php

namespace FluentBoards\Database\Migrations;

class RelationMigrator
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
        $table = $wpdb->prefix . 'fbs_relations';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table || $isForced) {
            $sql = "CREATE TABLE $table (
                `id` INT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `object_id` INT UNSIGNED NOT NULL,
                `object_type` VARCHAR(100) NOT NULL,
                `foreign_id` INT UNSIGNED NOT NULL,
                `settings` TEXT NULL COMMENT 'Serialized',
                `preferences` TEXT NULL COMMENT 'Serialized',
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                KEY `object_type` (`object_type`),
                KEY `object_id` (`object_id`),
                KEY `foreign_id` (`foreign_id`)
            ) $charsetCollate;";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);
        }
    }
}
