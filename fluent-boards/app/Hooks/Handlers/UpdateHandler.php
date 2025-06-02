<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Constant;

class UpdateHandler
{
    public static function maybeSubtaskGroupSync()
    {
        $lastDbVersion = get_option('_fluent_boards_db_version');
        if (!$lastDbVersion || version_compare($lastDbVersion, FLUENT_BOARDS_DB_VERSION, '<')) {
            // the last updated version is less than the current version
//            self::adjustOldSubtasksToSubtaskGroup();
            update_option('_fluent_boards_db_version', FLUENT_BOARDS_DB_VERSION);
        }

    }
    public static function maybeUpdateDbTables()
    {
        $lastDbVersion = get_option('_fluent_boards_db_version');
        if (!$lastDbVersion || version_compare($lastDbVersion, FLUENT_BOARDS_DB_VERSION, '<')) {
            // last updated version is less than current version
            self::updateDatabase();
            update_option('_fluent_boards_db_version', FLUENT_BOARDS_DB_VERSION);
        }
    }

    private static function adjustOldSubtasksToSubtaskGroup()
    {
        $allSubtasks = Task::whereNotNull('parent_id')->get();
        foreach ($allSubtasks as $subtask) {
            //need to check if that subtask is in a subtaskGroup or not
            $isInGroup = TaskMeta::where('task_id', $subtask->id)
                                 ->where('key', Constant::SUBTASK_GROUP_CHILD)
                                 ->exists();
            if ($isInGroup) {
                continue;
            }

            //check if any subtask group created for the parent task of the current subtask
            $group = TaskMeta::where('task_id', $subtask->parent_id)
                             ->where('key', Constant::SUBTASK_GROUP_NAME)
                             ->first();

            if ($group) {
                TaskMeta::create([
                    'task_id' => $subtask->id,
                    'key'     => Constant::SUBTASK_GROUP_CHILD,
                    'value'   => $group->id
                ]);
            } else {
                $newSubtaskGroup = TaskMeta::create([
                    'task_id' => $subtask->parent_id,
                    'key'     => Constant::SUBTASK_GROUP_NAME,
                    'value'   => 'Subtask Group One'
                ]);

                TaskMeta::create([
                    'task_id' => $subtask->id,
                    'key'     => Constant::SUBTASK_GROUP_CHILD,
                    'value'   => $newSubtaskGroup->id
                ]);
            }
        }

    }

    private static function updateDatabase()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'fbs_board_terms';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            return;
        } else {
            // change column type from int to decimal - for already installed sites
            $column_name = 'position';
            $preparedQuery = $wpdb->prepare("DESCRIBE $table %s", $column_name);
            $dataType = $wpdb->get_row($preparedQuery);
            if (strpos($dataType->Type, 'int') !== false) {
                $sql = $wpdb->prepare(
                    "ALTER TABLE $table MODIFY $column_name decimal(10,2) NOT NULL DEFAULT '1' COMMENT 'Position: 1 = top/first, 2 = second/second in top, etc.';"
                );
                $wpdb->query($sql);
            }
        }

        $table = $wpdb->prefix . 'fbs_comments';

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) != $table) {
            return;
        } else {
            $column_name = 'settings';
            // Check if the column already exists
            $column_exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM $table LIKE %s", $column_name
            ));

            if (!$column_exists) {
                $sql = "ALTER TABLE $table ADD $column_name TEXT NULL COMMENT 'serialize array' AFTER `created_by`";
                $wpdb->query($sql);
            }
        }

    }

}