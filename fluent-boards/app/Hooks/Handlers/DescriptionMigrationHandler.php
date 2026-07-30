<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Services\DescriptionMarkdownConverter;

/**
 * One-time backfill that converts legacy HTML task/board descriptions to
 * markdown (see docs/plan/description-markdown-migration.md).
 *
 * Design guarantees:
 *  - Silent: writes the description column with raw $wpdb, so no model events,
 *    no `fluent_boards/task_updated` activity log, and no `updated_at` change
 *    (fbs_tasks.updated_at has no ON UPDATE clause).
 *  - Safe: the original HTML is backed up to meta (`_legacy_description_html`)
 *    before it is overwritten, and can be restored.
 *  - Idempotent: each processed row is flagged (`_description_format=markdown`);
 *    flagged rows are never re-fetched.
 *
 * This is the reusable migration engine only — no trigger is wired yet. Drive it
 * from whatever mechanism you choose (admin action, upgrade routine, background
 * job) by calling processBatch() or runToCompletion().
 */
class DescriptionMigrationHandler
{
    const FLAG_KEY   = '_description_format';
    const BACKUP_KEY = '_legacy_description_html';
    const BATCH_SIZE = 50;

    /**
     * Convert up to $limit pending rows of the given type. Returns rows processed.
     */
    public function processBatch($type, $limit = self::BATCH_SIZE, $dryRun = false)
    {
        $rows = $this->fetchPending($type, $limit);
        foreach ($rows as $row) {
            $this->migrateRow($type, (int) $row->id, $row->description, $dryRun);
        }
        return count($rows);
    }

    /**
     * Loop batches of both types to completion. Returns totals per type.
     */
    public function runToCompletion($dryRun = false)
    {
        $totals = ['task' => 0, 'board' => 0];
        foreach (['task', 'board'] as $type) {
            do {
                $count = $this->processBatch($type, self::BATCH_SIZE, $dryRun);
                $totals[$type] += $count;
            } while ($count === self::BATCH_SIZE);
        }
        return $totals;
    }

    public function countPending($type)
    {
        global $wpdb;
        [$table, $metaTable, $joinCond, $idCol] = $this->tableMap($type);

        // phpcs:ignore WordPress.DB -- one-time migration count over own tables
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} t
             LEFT JOIN {$metaTable} m ON {$joinCond} AND m.`key` = '" . self::FLAG_KEY . "'
             WHERE t.description IS NOT NULL AND t.description <> '' AND m.id IS NULL"
        );
    }

    private function fetchPending($type, $limit)
    {
        global $wpdb;
        [$table, $metaTable, $joinCond, $idCol] = $this->tableMap($type);

        // phpcs:ignore WordPress.DB -- one-time migration read over own tables
        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.{$idCol} AS id, t.description FROM {$table} t
             LEFT JOIN {$metaTable} m ON {$joinCond} AND m.`key` = '" . self::FLAG_KEY . "'
             WHERE t.description IS NOT NULL AND t.description <> '' AND m.id IS NULL
             LIMIT %d",
            $limit
        ));
    }

    private function migrateRow($type, $id, $description, $dryRun)
    {
        $isHtml   = DescriptionMarkdownConverter::looksLikeHtml($description);
        $markdown = $isHtml ? DescriptionMarkdownConverter::convert($description) : trim((string) $description);

        if ($dryRun) {
            return;
        }

        // Only legacy HTML rows need a backup + column rewrite; already-markdown
        // rows are simply flagged so they are not re-examined.
        if ($isHtml) {
            $this->writeMeta($type, $id, self::BACKUP_KEY, $description);
            $this->updateDescription($type, $id, $markdown);
        }

        $this->writeMeta($type, $id, self::FLAG_KEY, 'markdown');
    }

    private function updateDescription($type, $id, $markdown)
    {
        global $wpdb;
        $table = $type === 'board' ? $wpdb->prefix . 'fbs_boards' : $wpdb->prefix . 'fbs_tasks';

        // Raw update: bypasses model events / activity hooks and leaves updated_at intact.
        $wpdb->update($table, ['description' => $markdown], ['id' => $id]);
    }

    private function writeMeta($type, $id, $key, $value)
    {
        global $wpdb;
        $now = current_time('mysql');

        if ($type === 'board') {
            $wpdb->insert($wpdb->prefix . 'fbs_meta', [
                'object_id'   => $id,
                'object_type' => 'board',
                'key'         => $key,
                'value'       => $value,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        } else {
            $wpdb->insert($wpdb->prefix . 'fbs_task_metas', [
                'task_id'    => $id,
                'key'        => $key,
                'value'      => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Restore original HTML from backups (rollback for a bad migration).
     * Returns rows restored per type.
     */
    public function restoreAll()
    {
        global $wpdb;
        $totals = ['task' => 0, 'board' => 0];

        // Tasks
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT task_id AS id, value FROM {$wpdb->prefix}fbs_task_metas WHERE `key` = %s",
            self::BACKUP_KEY
        ));
        foreach ($rows as $row) {
            $wpdb->update($wpdb->prefix . 'fbs_tasks', ['description' => $row->value], ['id' => $row->id]);
            $totals['task']++;
        }

        // Boards
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id AS id, value FROM {$wpdb->prefix}fbs_meta WHERE object_type = 'board' AND `key` = %s",
            self::BACKUP_KEY
        ));
        foreach ($rows as $row) {
            $wpdb->update($wpdb->prefix . 'fbs_boards', ['description' => $row->value], ['id' => $row->id]);
            $totals['board']++;
        }

        return $totals;
    }

    /**
     * @return array{0:string,1:string,2:string,3:string} [table, metaTable, joinCond, idCol]
     */
    private function tableMap($type)
    {
        global $wpdb;
        if ($type === 'board') {
            return [
                $wpdb->prefix . 'fbs_boards',
                $wpdb->prefix . 'fbs_meta',
                "m.object_id = t.id AND m.object_type = 'board'",
                'id',
            ];
        }

        return [
            $wpdb->prefix . 'fbs_tasks',
            $wpdb->prefix . 'fbs_task_metas',
            'm.task_id = t.id',
            'id',
        ];
    }
}
