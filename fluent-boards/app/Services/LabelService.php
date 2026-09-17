<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;


class  LabelService
{
    public function getLabelsByBoard($boardId)
    {
        return Label::where('board_id', $boardId)->orderBy('created_at', 'ASC')->get();
    }

    public function getLabelsByBoardUsedInTasks($boardId)
    {
        $boardLabels = Label::where('board_id', $boardId)
            ->where('type', 'label')
            ->orderBy('created_at', 'ASC')
            ->get();

        if ($boardLabels->isEmpty()) {
            return [];
        }

        // One relation query for the whole board rather than an exists() per label.
        $usedIds = Relation::where('object_type', Constant::OBJECT_TYPE_TASK_LABEL)
            ->whereIn('foreign_id', $boardLabels->pluck('id')->all())
            ->distinct()
            ->pluck('foreign_id')
            ->all();

        $usedIds = array_map('intval', $usedIds);

        $usedLabel = [];
        foreach ($boardLabels as $label) {
            if (in_array((int) $label->id, $usedIds, true)) {
                $usedLabel[] = $label;
            }
        }

        return $usedLabel;
    }

    public function createLabel($labelData, $boardId)
    {
        $labelData = $this->normalizeLabelColorData($labelData);

        $label = new Label();
        $label->board_id = $boardId;
        $label->title = $labelData['label'] ?? '';
        $label->bg_color = $labelData['bg_color'] ?? '';
        $label->color = $labelData['color'] ?? '';
        if (isset($labelData['settings'])) {
            $label->settings = $labelData['settings'];
        }
        $label->save();

        return $label;
    }

    public function createDefaultLabel($boardId)
    {
        $defaultColors = ['green-bold', 'yellow-bold', 'orange-bold', 'red-bold', 'purple-bold'];

        $data = [];

        foreach ($defaultColors as $presetId)
        {
            $preset = Constant::getLabelColorPreset($presetId);
            $colorName = strtok($presetId, '-');
            $data[] = [
                'board_id' => $boardId,
                // Titles match the create-board modal defaults, otherwise boards created
                // outside that modal end up with colour chips carrying no text.
                'title' => ucfirst($colorName),
                'slug' => $colorName,
                'type' => 'label',
                'bg_color' => $preset['light_bg_color'],
                'color' => $preset['light_text_color'],
                'settings' => maybe_serialize([Constant::LABEL_COLOR_PRESET_SETTING => $presetId]),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
        }

        Label::insert($data);
    }

    public function createLabelForTask($labelData, $boardId = null)
    {
        $task = $boardId ? (new TaskService())->findTaskOnBoard($labelData['task_id'], $boardId) : Task::findOrFail($labelData['task_id']);
        $label = $this->findLabelOnBoard($labelData['board_term_id'], $boardId ?: $task->board_id);

        $task->labels()->syncWithoutDetaching([$labelData['board_term_id'] => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);

        $label = $task->labels->find($label->id);

        do_action('fluent_boards/task_label',$task, $label, 'added');

        return $label;
    }

    public function getLabelsByTask($taskId, $boardId = null)
    {
        $task = $boardId ? (new TaskService())->findTaskOnBoard($taskId, $boardId) : Task::findOrFail($taskId);
        return $task->labels;
    }

    public function labelsByBoardId($boardId)
    {
        return Label::where('board_id', $boardId)->whereNull('archived_at')->get();
    }

    public function deleteLabelOfTask($taskId, $labelId, $boardId = null)
    {
        $task = $boardId ? (new TaskService())->findTaskOnBoard($taskId, $boardId) : Task::findOrFail($taskId);
        $label = $this->findLabelOnBoard($labelId, $boardId ?: $task->board_id);
        $task->labels()->detach($labelId);
        do_action('fluent_boards/task_label',$task, $label, 'removed');
    }

    public function deleteLabelOfBoard($labelId, $boardId = null)
    {
        $label = $boardId ? $this->findLabelOnBoard($labelId, $boardId) : Label::findOrFail($labelId);
        $label->tasks()->detach();
        $label->delete();

        do_action('fluent_boards/board_label_deleted', $label);
    }

    public function editLabelofBoard($labelData, $id, $boardId = null)
    {
        $label = $boardId ? $this->findLabelOnBoard($id, $boardId) : Label::findOrFail($id);
        $labelData = $this->normalizeLabelColorData($labelData, $label);
        $label->title = $labelData['label'] ?? $label->title;

        // Background and text colour move independently: coupling them dropped a
        // text-colour-only change on the floor while still reporting success.
        if (isset($labelData['bg_color']) && $labelData['bg_color'] !== '') {
            $label->bg_color = $labelData['bg_color'];
        }

        if (isset($labelData['color']) && $labelData['color'] !== '') {
            $label->color = $labelData['color'];
        }

        if (array_key_exists('settings', $labelData)) {
            if (array_key_exists('color_preset', $labelData) && $labelData['color_preset'] === '') {
                $label->replaceSettings($labelData['settings']);
            } else {
                $label->settings = $labelData['settings'];
            }
        }

        $label->save();
        return $label;
    }

    /**
     * Resolve a label only when it belongs to the requested board.
     *
     * @param int $labelId
     * @param int $boardId
     * @return Label
     * @throws \Exception
     */
    public function findLabelOnBoard($labelId, $boardId)
    {
        $label = Label::where('id', absint($labelId))
            ->where('board_id', absint($boardId))
            ->where('type', 'label')
            ->first();

        if (!$label) {
            throw new \Exception(esc_html__('Label not found', 'fluent-boards'));
        }

        return $label;
    }

    public function copyLabelsOfBoard($boardId, $board)
    {
        $boardCopyFrom = Board::findOrFail($boardId);

        $labelMap = [];

        foreach($boardCopyFrom->labels as $label)
        {
            $labelToSave = array();
            $labelToSave['title'] = $label->title;
            $labelToSave['slug'] = $label->slug;
            $labelToSave['board_id'] = $board->id;
            $labelToSave['type'] = 'label';
            $labelToSave['position'] = 0;
            $labelToSave['color'] = $label->color;
            $labelToSave['bg_color'] = $label->bg_color;
            $settings = (array) $label->settings;
            $presetId = $settings[Constant::LABEL_COLOR_PRESET_SETTING] ?? '';
            if (Constant::getLabelColorPreset($presetId)) {
                $labelToSave['settings'] = [Constant::LABEL_COLOR_PRESET_SETTING => $presetId];
            }
            $copiedLabel = Label::create($labelToSave);

            $labelMap[$label['id']] = $copiedLabel->id;
        }
         return $labelMap;
    }

    /**
     * Converts a selected preset into stable light-mode fallback colors.
     *
     * @param array $labelData
     * @param Label|null $label
     * @return array
     * @throws \Exception
     */
    private function normalizeLabelColorData($labelData, $label = null)
    {
        if (!array_key_exists('color_preset', $labelData)) {
            return $labelData;
        }

        $presetId = $labelData['color_preset'];
        $settings = $label ? (array) $label->settings : [];

        // Framework request extraction can represent an omitted optional field
        // as null. That must preserve an existing preset rather than reject it.
        if ($presetId === null) {
            unset($labelData['color_preset']);
            return $labelData;
        }

        if ($presetId === '') {
            unset($settings[Constant::LABEL_COLOR_PRESET_SETTING]);
            $labelData['settings'] = $settings;
            return $labelData;
        }

        $preset = Constant::getLabelColorPreset($presetId);
        if (!$preset) {
            throw new \Exception(esc_html__('Invalid label color preset', 'fluent-boards'));
        }

        $settings[Constant::LABEL_COLOR_PRESET_SETTING] = $preset['id'];
        $labelData['settings'] = $settings;
        $labelData['bg_color'] = $preset['light_bg_color'];
        $labelData['color'] = $preset['light_text_color'];

        return $labelData;
    }
    public function getLastOneMinuteUpdatedLabels($boardId, $lastUpdated = null, $includeArchived = true)
    {
        if (!$lastUpdated) {
            $oneMinuteAgoTimestamp = current_time('timestamp') - 60;
            $lastUpdated = date_i18n('Y-m-d H:i:s', $oneMinuteAgoTimestamp);
        }

        $labelsQuery = Label::where('board_id', $boardId)
            ->where('updated_at', '>=', $lastUpdated);

        if (!$includeArchived) {
            $labelsQuery->whereNull('archived_at');
        }

        return $labelsQuery->get();
    }
}
