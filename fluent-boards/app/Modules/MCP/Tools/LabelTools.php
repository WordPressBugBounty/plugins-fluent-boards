<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\LabelService;

/**
 * Board label read/write MCP tools and task label assignment.
 */
class LabelTools
{
    const DEFAULT_BG_COLOR = '#f3f4f6';
    const DEFAULT_TEXT_COLOR = '#1B2533';
    const MAX_LABELS_PER_CALL = 50;

    public static function listLabels($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canReadBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id);
        }

        $service = new LabelService();
        $labels = !empty($params['only_used'])
            ? $service->getLabelsByBoardUsedInTasks($board->id)
            : $service->getLabelsByBoard($board->id);

        return [
            'board_id' => (int) $board->id,
            'labels'   => MCPHelper::formatLabelList($labels),
        ];
    }

    /**
     * Create a label, or update it when label_id is given. One tool so an agent shaping a
     * board's taxonomy does not have to pick between two, mirroring save-stage.
     */
    public static function saveLabel($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id, true);
        }

        $service = new LabelService();
        $isUpdate = !empty($params['label_id']);
        $existing = null;

        if ($isUpdate) {
            $existing = self::findLabel($service, $params['label_id'], $board->id);
            if (is_wp_error($existing)) {
                return $existing;
            }
        }

        $labelData = Helper::sanitizeLabel([
            'label'    => array_key_exists('title', $params) ? $params['title'] : ($existing ? $existing->title : ''),
            'bg_color' => !empty($params['bg_color']) ? $params['bg_color'] : ($existing ? $existing->bg_color : ''),
            'color'    => !empty($params['color']) ? $params['color'] : ($existing ? $existing->color : ''),
            'color_preset' => array_key_exists('color_preset', $params) ? $params['color_preset'] : null,
        ]);

        if (!array_key_exists('color_preset', $params)) {
            unset($labelData['color_preset']);
        }

        if (empty($labelData['label']) && empty($labelData['bg_color'])) {
            return MCPHelper::invalid(['title', 'bg_color'], 'A label needs a title or a bg_color. Send title, or bg_color for a colour-only label.');
        }

        if ($isUpdate) {
            $label = $service->editLabelofBoard($labelData, $existing->id, $board->id);
            do_action('fluent_boards/board_label_updated', $label);
        } else {
            $label = $service->createLabel([
                'label'    => $labelData['label'] ?? '',
                'bg_color' => !empty($labelData['bg_color']) ? $labelData['bg_color'] : self::DEFAULT_BG_COLOR,
                'color'    => !empty($labelData['color']) ? $labelData['color'] : self::DEFAULT_TEXT_COLOR,
                'color_preset' => $labelData['color_preset'] ?? '',
            ], $board->id);
            do_action('fluent_boards/board_label_created', $label);
        }

        return [
            'label'   => self::formatLabel($label),
            'created' => !$isUpdate,
        ];
    }

    public static function deleteLabel($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id, true);
        }

        $service = new LabelService();
        $label = self::findLabel($service, $params['label_id'] ?? 0, $board->id);
        if (is_wp_error($label)) {
            return $label;
        }

        $service->deleteLabelOfBoard($label->id, $board->id);

        return [
            'deleted'  => true,
            'board_id' => (int) $board->id,
            'label_id' => (int) $label->id,
            'title'    => (string) $label->title,
        ];
    }

    public static function addTaskLabel($params = [])
    {
        return self::syncTaskLabels($params, 'add');
    }

    public static function removeTaskLabel($params = [])
    {
        return self::syncTaskLabels($params, 'remove');
    }

    private static function syncTaskLabels($params, $mode)
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::boardForbidden($task->board_id, true);
        }

        $service = new LabelService();
        $labels = self::resolveLabels($service, $params, $task->board_id);
        if (is_wp_error($labels)) {
            return $labels;
        }

        if (!$labels) {
            return MCPHelper::invalid(['label_id', 'label_ids', 'label_titles'], 'Send label_id, label_ids or label_titles: the labels to ' . ($mode === 'add' ? 'attach' : 'remove') . '. get-board lists the labels of the board.');
        }

        $changedIds = self::changeTaskLabels($task, $labels, $mode);

        if (count($changedIds) < count($labels)) {
            MCPHelper::addWarning($mode === 'add'
                ? 'Some of the labels were already on the task; changed lists the label ids that were added.'
                : 'Some of the labels were not on the task; changed lists the label ids that were removed.');
        }

        return [
            'task_id'  => (int) $task->id,
            'board_id' => (int) $task->board_id,
            'changed'  => $changedIds,
            'labels'   => MCPHelper::formatLabelList($task->labels),
        ];
    }

    /**
     * Attach labels to a task, or detach them, and fire one event per changed label.
     *
     * @param \FluentBoards\App\Models\Task $task
     * @param array                         $labels Label models on the task's board.
     * @param string                        $mode   add or remove.
     * @return int[] Ids of the labels that changed.
     */
    public static function changeTaskLabels($task, $labels, $mode)
    {
        $labelIds = array_map(function ($label) {
            return (int) $label->id;
        }, $labels);

        $task->load('labels');
        $currentIds = [];
        foreach ($task->labels as $label) {
            $currentIds[] = (int) $label->id;
        }

        $changedIds = $mode === 'add'
            ? array_values(array_diff($labelIds, $currentIds))
            : array_values(array_intersect($labelIds, $currentIds));

        // One pivot write for the batch instead of a service call per label, each of
        // which re-queried the task and the label.
        if ($changedIds && $mode === 'add') {
            $task->labels()->syncWithoutDetaching(array_fill_keys(
                $changedIds,
                ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]
            ));
        } elseif ($changedIds) {
            $task->labels()->detach($changedIds);
        }

        $task->load('labels');

        // Activity logging and integrations still expect one event per label.
        $labelsById = [];
        foreach ($labels as $label) {
            $labelsById[(int) $label->id] = $label;
        }

        foreach ($changedIds as $labelId) {
            if (empty($labelsById[$labelId])) {
                continue;
            }

            do_action('fluent_boards/task_label', $task, $labelsById[$labelId], $mode === 'add' ? 'added' : 'removed');
        }

        return $changedIds;
    }

    /**
     * Accepts label_id, label_ids and label_titles so an agent can attach labels it only
     * knows by name without a lookup round-trip. Everything is resolved against the task's
     * own board in a single query, and the batch is bounded, before anything is written.
     *
     * @return array|\WP_Error List of Label models.
     */
    public static function resolveLabels($service, $params, $boardId)
    {
        $ids = MCPHelper::sanitizeIdArray($params['label_ids'] ?? []);

        if (!empty($params['label_id'])) {
            $ids[] = absint($params['label_id']);
        }

        $titles = [];
        if (!empty($params['label_titles']) && is_array($params['label_titles'])) {
            foreach ($params['label_titles'] as $title) {
                $title = sanitize_text_field((string) $title);
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        $titles = array_values(array_unique($titles));

        if (count($ids) + count($titles) > self::MAX_LABELS_PER_CALL) {
            return MCPHelper::invalid(['label_ids', 'label_titles'], sprintf('A call takes at most %d labels in total.', self::MAX_LABELS_PER_CALL));
        }

        if (!$ids && !$titles) {
            return [];
        }

        // Single board-scoped read covers both id and title resolution.
        $boardLabels = $service->getLabelsByBoard($boardId);
        $byId = [];
        foreach ($boardLabels as $label) {
            if ($label->type === 'label') {
                $byId[(int) $label->id] = $label;
            }
        }

        $resolved = [];

        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                return self::labelNotFound($id, $boardId, !empty($params['label_id']) && absint($params['label_id']) === $id ? 'label_id' : 'label_ids');
            }
            $resolved[$id] = $byId[$id];
        }

        foreach ($titles as $title) {
            $matches = [];
            foreach ($byId as $label) {
                if (strcasecmp((string) $label->title, $title) === 0) {
                    $matches[] = $label;
                }
            }

            if (!$matches) {
                $known = [];
                foreach ($byId as $label) {
                    if ((string) $label->title !== '') {
                        $known[] = (string) $label->title;
                    }
                }

                return MCPHelper::error('not_found', sprintf(
                    'Board %d has no label titled "%s". Use a title from details.label_titles, or create the label with save-label.',
                    (int) $boardId,
                    $title
                ), ['fields' => ['label_titles'], 'label_titles' => array_slice($known, 0, 50)]);
            }

            // Titles that differ only in letter case: the one written exactly as asked is meant.
            if (count($matches) > 1) {
                $exact = array_values(array_filter($matches, function ($label) use ($title) {
                    return (string) $label->title === $title;
                }));
                if (count($exact) === 1) {
                    $matches = $exact;
                }
            }

            // Two labels can share a title. Picking one would attach a label the user did not mean.
            if (count($matches) > 1) {
                return MCPHelper::invalid('label_titles', sprintf(
                    '%d labels on board %d are titled "%s". Send the one you mean by id in label_ids; details.labels lists them.',
                    count($matches),
                    (int) $boardId,
                    $title
                ), ['labels' => MCPHelper::formatLabelList($matches)]);
            }

            $resolved[(int) $matches[0]->id] = $matches[0];
        }

        return array_values($resolved);
    }

    private static function findLabel($service, $labelId, $boardId)
    {
        $labelId = absint($labelId);
        if (!$labelId) {
            return MCPHelper::invalid('label_id', 'label_id is required: a label id from get-board or list-labels.');
        }

        try {
            return $service->findLabelOnBoard($labelId, $boardId);
        } catch (\Exception $e) {
            return self::labelNotFound($labelId, $boardId, 'label_id');
        }
    }

    private static function labelNotFound($labelId, $boardId, $field)
    {
        return MCPHelper::error('not_found', sprintf(
            'Board %d has no label with id %d. get-board and list-labels return the label ids.',
            (int) $boardId,
            (int) $labelId
        ), ['fields' => [$field]]);
    }

    private static function formatLabel($label)
    {
        $formatted = MCPHelper::formatLabelList([$label]);

        return $formatted ? $formatted[0] : null;
    }
}
