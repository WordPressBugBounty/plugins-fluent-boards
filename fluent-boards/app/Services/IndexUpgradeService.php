<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\App;
use FluentBoards\App\Models\BoardTerm;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use RuntimeException;
use Throwable;
use wpdb;

/** Add lookup indexes without replaying legacy column/data migrations. */
class IndexUpgradeService
{
    const VERSION = 1;
    const VERSION_OPTION = '_fluent_boards_index_schema_version';

    /** Retry incomplete upgrades on an administrator visit; never commit caller transactions. */
    public function maybeUpgrade()
    {
        if (!current_user_can('manage_options') || App::getInstance('db')->inTransaction()) {
            return false;
        }
        if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
            return true;
        }

        global $wpdb;
        // Do not bypass a database drop-in's routing with an unmanaged connection.
        if (get_class($wpdb) !== 'wpdb') {
            error_log('FluentBoards index upgrade: custom database driver requires a managed schema upgrade.');
            return false;
        }
        $database = null;
        $lock = 'fbs_indexes_' . md5($wpdb->dbname . ':' . $wpdb->prefix);
        $locked = false;
        $lockWait = null;
        try {
            // DDL on the request handle would commit raw/other-ORM transactions.
            $database = new class($wpdb->dbuser, $wpdb->dbpassword, $wpdb->dbname, $wpdb->dbhost) extends wpdb {
                public function db_connect($allow_bail = true)
                {
                    return parent::db_connect(false);
                }

                /** Stop this attempt: reconnecting loses its lock and session timeout. */
                public function check_connection($allow_bail = true)
                {
                    $this->ready = false;
                    $this->last_error = 'Schema connection lost; retry on a later request.';
                    return false;
                }

                public function bail($message, $error_code = '500')
                {
                    // Connection/select failures must not terminate the admin request.
                    $this->ready = false;
                    return false;
                }
            };
            $database->prefix = $wpdb->prefix;
            $database->suppress_errors(true);
            if (!$database->ready) {
                throw new RuntimeException('Could not open an isolated schema connection.');
            }
            // Connection-owned locks survive long ALTERs and release on disconnect.
            $locked = (int) $database->get_var($database->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === 1;
            if (!$locked) {
                return false;
            }
            if ((int) get_option(self::VERSION_OPTION, 0) >= self::VERSION) {
                return true;
            }

            $lockWait = $database->get_var('SELECT @@SESSION.lock_wait_timeout');
            if (!is_numeric($lockWait) || $database->query('SET SESSION lock_wait_timeout = 1') === false) {
                throw new RuntimeException('Could not limit index metadata lock waits.');
            }

            $indexes = [
                [(new BoardTerm())->getTable(), 'fbs_board_type', ['board_id', 'type']],
                [(new Task())->getTable(), 'fbs_stage_id', ['stage_id']],
                [(new Task())->getTable(), 'fbs_parent_id', ['parent_id']],
                [(new Relation())->getTable(), 'fbs_object_type_id', ['object_type', 'object_id']],
            ];
            foreach ($indexes as [$table, $name, $columns]) {
                $table = $database->prefix . $table;
                if ($this->hasIndex($database, $table, $columns)) {
                    continue;
                }
                $quotedTable = '`' . str_replace('`', '``', $table) . '`';
                $quotedColumns = '`' . implode('`, `', $columns) . '`';
                // Unsupported engines remain pending; never fall back to blocking COPY.
                $sql = "ALTER TABLE $quotedTable ADD INDEX `$name` ($quotedColumns), ALGORITHM=INPLACE, LOCK=NONE";
                if ($database->query($sql) === false || !$this->hasIndex($database, $table, $columns)) {
                    throw new RuntimeException('Index ' . $name . ' on ' . $table . ' remains pending: ' . $database->last_error);
                }
            }

            update_option(self::VERSION_OPTION, self::VERSION, false);
            return (int) get_option(self::VERSION_OPTION, 0) >= self::VERSION;
        } catch (Throwable $e) {
            error_log('FluentBoards index upgrade: ' . sanitize_text_field($e->getMessage()));
            return false;
        } finally {
            if ($database) {
                if ($database->ready && is_numeric($lockWait)) {
                    $database->query($database->prepare('SET SESSION lock_wait_timeout = %d', (int) $lockWait));
                }
                if ($database->ready && $locked) {
                    $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                }
                $database->close();
            }
        }
    }

    /** An existing full-column BTREE left prefix also satisfies the lookup. */
    private function hasIndex($database, $table, array $columns)
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $rows = $database->get_results("SHOW INDEX FROM $quotedTable", ARRAY_A);
        if ($database->last_error) {
            throw new RuntimeException('Could not inspect ' . $table . ': ' . $database->last_error);
        }

        $indexes = [];
        foreach ($rows as $row) {
            if (strtoupper($row['Index_type']) !== 'BTREE' || ($row['Visible'] ?? 'YES') === 'NO' || ($row['Ignored'] ?? 'NO') === 'YES') {
                continue;
            }
            $indexes[$row['Key_name']][(int) $row['Seq_in_index']] = $row['Sub_part'] === null ? $row['Column_name'] : null;
        }
        foreach ($indexes as $index) {
            ksort($index);
            if (array_slice(array_values($index), 0, count($columns)) === $columns) {
                return true;
            }
        }
        return false;
    }
}
