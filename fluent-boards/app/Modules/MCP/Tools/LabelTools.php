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
            return MCPHelper::error('forbidden', __('You do not have access to this board', 'fluent-boards'));
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
            return MCPHelper::error('forbidden', __('You do not have permission to manage labels on this board', 'fluent-boards'));
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
        ]);

        if (empty($labelData['label']) && empty($labelData['bg_color'])) {
            return MCPHelper::error('invalid_param', __('Provide a label title or a background color', 'fluent-boards'));
        }

        if ($isUpdate) {
            $label = $service->editLabelofBoard($labelData, $existing->id, $board->id);
            do_action('fluent_boards/board_label_updated', $label);
        } else {
            $label = $service->createLabel([
                'label'    => $labelData['label'] ?? '',
                'bg_color' => !empty($labelData['bg_color']) ? $labelData['bg_color'] : self::DEFAULT_BG_COLOR,
                'color'    => !empty($labelData['color']) ? $labelData['color'] : self::DEFAULT_TEXT_COLOR,
            ], $board->id);
            do_action('fluent_boards/board_label_created', $label);
        }

        return [
            'label'   => self::formatLabel($label),
            'message' => $isUpdate
                ? __('Label has been updated', 'fluent-boards')
                : __('Label has been created', 'fluent-boards'),
        ];
    }

    public static function deleteLabel($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to manage labels on this board', 'fluent-boards'));
        }

        $service = new LabelService();
        $label = self::findLabel($service, $params['label_id'] ?? 0, $board->id);
        if (is_wp_error($label)) {
            return $label;
        }

        $service->deleteLabelOfBoard($label->id, $board->id);

        return [
            'board_id' => (int) $board->id,
            'label_id' => (int) $label->id,
            'message'  => __('Label has been deleted', 'fluent-boards'),
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
            return MCPHelper::error('forbidden', __('You do not have permission to update this task', 'fluent-boards'));
        }

        $service = new LabelService();
        $labels = self::resolveLabels($service, $params, $task->board_id);
        if (is_wp_error($labels)) {
            return $labels;
        }

        if (!$labels) {
            return MCPHelper::error('invalid_param', __('Provide label_id, label_ids or label_titles', 'fluent-boards'));
        }

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

            $label = $labelsById[$labelId];
            do_action('fluent_boards/task_label', $task, $label, $mode === 'add' ? 'added' : 'removed');
        }

        return [
            'task_id'  => (int) $task->id,
            'board_id' => (int) $task->board_id,
            'labels'   => MCPHelper::formatLabelList($task->labels),
            'message'  => $mode === 'add'
                ? __('Labels have been added to the task', 'fluent-boards')
                : __('Labels have been removed from the task', 'fluent-boards'),
        ];
    }

    /**
     * Accepts label_id, label_ids and label_titles so an agent can attach labels it only
     * knows by name without a lookup round-trip. Everything is resolved against the task's
     * own board in a single query, and the batch is bounded, before anything is written.
     *
     * @return array|\WP_Error List of Label models.
     */
    private static function resolveLabels($service, $params, $boardId)
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
            return MCPHelper::error('invalid_param', __('Too many labels in one call', 'fluent-boards'), [
                'max' => self::MAX_LABELS_PER_CALL,
            ]);
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
                return MCPHelper::error('not_found', __('Label not found on this board', 'fluent-boards'), [
                    'label_id' => $id,
                ]);
            }
            $resolved[$id] = $byId[$id];
        }

        foreach ($titles as $title) {
            $match = null;
            foreach ($byId as $label) {
                if (strcasecmp((string) $label->title, $title) === 0) {
                    $match = $label;
                    break;
                }
            }

            if (!$match) {
                return MCPHelper::error('not_found', __('Label not found on this board', 'fluent-boards'), [
                    'title' => $title,
                ]);
            }

            $resolved[(int) $match->id] = $match;
        }

        return array_values($resolved);
    }

    private static function findLabel($service, $labelId, $boardId)
    {
        $labelId = absint($labelId);
        if (!$labelId) {
            return MCPHelper::error('invalid_param', __('Provide label_id', 'fluent-boards'));
        }

        try {
            return $service->findLabelOnBoard($labelId, $boardId);
        } catch (\Exception $e) {
            return MCPHelper::error('not_found', __('Label not found on this board', 'fluent-boards'), [
                'label_id' => $labelId,
            ]);
        }
    }

    private static function formatLabel($label)
    {
        $formatted = MCPHelper::formatLabelList([$label]);

        return $formatted ? $formatted[0] : null;
    }
}
