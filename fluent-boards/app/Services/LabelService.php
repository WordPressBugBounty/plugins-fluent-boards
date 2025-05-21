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
        $boardLabel = Label::where('board_id', $boardId)->where('type', 'label')->orderBy('created_at', 'ASC')->get();
        $usedLabel = [];
        foreach ($boardLabel as $label) {
            $exist = Relation::where('foreign_id', $label->id)->where('object_type', 'task_label')->exists();
            if ($exist) {
                $usedLabel[] = $label;
            }
        }
        return $usedLabel;
    }

    public function createLabel($labelData, $boardId)
    {
        $label = new Label();
        $label->board_id = $boardId;
        $label->title = $labelData['label'];
        $label->bg_color = $labelData['bg_color'];
        $label->color = $labelData['color'];
        $label->save();

        return $label;
    }

    public function createDefaultLabel($boardId)
    {
        $defaultColors = [
            "green" => "#4bce97",
            "yellow" => "#f5cd47",
            "orange" => "#fea362",
            "red" => "#f87168",
            "purple" => "#9f8fef"
        ];

        $data = [];

        foreach ($defaultColors as $index => $bg_color)
        {
            $data[] = [
                'board_id' => $boardId,
                'slug' => $index,
                'type' => 'label',
                'bg_color' => $bg_color,
                'color' => Constant::TEXT_COLOR_MAP[$index],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];
        }

        Label::insert($data);
    }

    public function createLabelForTask($labelData)
    {
        $task = Task::findOrFail($labelData['task_id']);

        $task->labels()->syncWithoutDetaching([$labelData['board_term_id'] => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);

        $label = $task->labels->find($labelData['board_term_id']);

        do_action('fluent_boards/task_label',$task, $label, 'added');

        return $label;
    }

    public function getLabelsByTask($taskId)
    {
        $task = Task::findOrFail($taskId);
        return $task->labels;
    }

    public function labelsByBoardId($boardId)
    {
        return Label::where('board_id', $boardId)->whereNull('archived_at')->get();
    }

    public function deleteLabelOfTask($taskId, $labelId)
    {
        $task = Task::findOrFail($taskId);
        $task->labels()->detach($labelId);
        $label = Label::findOrFail($labelId);
        do_action('fluent_boards/task_label',$task, $label, 'removed');
    }

    public function deleteLabelOfBoard($labelId)
    {
        $label = Label::findOrFail($labelId);
        $label->tasks()->detach();
        $label->delete();

        do_action('fluent_boards/board_label_deleted', $label);
    }

    public function editLabelofBoard($labelData, $id)
    {
        $label = Label::findOrFail($id);
        $label->title = $labelData['label'];
        if ($label->bg_color != $labelData['bg_color']) {
            $label->bg_color = $labelData['bg_color'];
            $label->color = $labelData['color'];
        }
        $label->save();
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
            $copiedLabel = Label::create($labelToSave);

            $labelMap[$label['id']] = $copiedLabel->id;
        }
         return $labelMap;
    }
    public function getLastOneMinuteUpdatedLabels($boardId)
    {
        $oneMinuteAgoTimestamp = current_time('timestamp') - 60;
        return Label::where('board_id', $boardId)
                        ->where('updated_at', '>=', date_i18n('Y-m-d H:i:s', $oneMinuteAgoTimestamp))
                        ->get();
    }
}