<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Label;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\LabelService;

class LabelController extends Controller
{
    private LabelService $labelService;

    public function __construct(LabelService $labelService)
    {
        parent::__construct();
        $this->labelService = $labelService;
    }

    public function getLabelsByBoard($board_id)
    {
        try {
            $labels = $this->labelService->getLabelsByBoard($board_id);

            return $this->sendSuccess([
                'labels' => $labels,
            ], 200);
        } catch(\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getLabelsByBoardUsedInTasks($board_id)
    {
        try {
            $labels = $this->labelService->getLabelsByBoardUsedInTasks($board_id);

            return $this->sendSuccess([
                'labels' => $labels,
            ], 200);
        } catch(\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function createLabel(Request $request, $board_id)
    {
        $labelData = $this->labelSanitizeAndValidate($request->all(), [
            'bg_color' => 'required|string',
            'color' => 'required|string',
            'label' => 'nullable|string',
        ]);

        try {
            $label = $this->labelService->createLabel($labelData, $board_id);
            do_action('fluent_boards/board_label_created', $label);

            return $this->sendSuccess([
                'message' => __('Label has been created', 'fluent-boards'),
                'label'  => $label,
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function createLabelForTask(Request $request, $board_id)
    {
        $requestData = [
            'task_id'    => $request->taskId,
            'boardTerm_id' => (int) $request->labelId,
        ];

        $labelData = $this->labelSanitizeAndValidate($requestData, [
            'task_id'    => 'required|integer',
            'boardTerm_id' => 'required|integer',
        ]);
        try {
            $label = $this->labelService->createLabelForTask($labelData);
//            do_action('fluent_boards/label_manage_for_task_activity', $label->task_id, 'added');

            return $this->sendSuccess([
                'message' => __('Label has been added', 'fluent-boards'),
                'label'  => $label,
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getLabelsByTask($board_id, $task_id)
    {
        try {
            $labels = $this->labelService->getLabelsByTask($task_id);

            return $this->sendSuccess([
                'labels' => $labels,
            ], 200);
        } catch(\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function deleteLabelOfTask($board_id, $task_id, $label_id)
    {
        try {
            $this->labelService->deleteLabelOfTask($task_id, $label_id);

            return $this->sendSuccess([
                'message' => __('Label has been deleted', 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function deleteLabelOfBoard($board_id, $label_id)
    {
        try {

            $label = Label::findOrFail($label_id);

            if (count($label->tasks) > 0) {
                return $this->sendError([
                    'message' => __("You can't delete. This label used in task.", 'fluent-boards'),
                    'type'    => 'warning',
                ]);
            }

            $this->labelService->deleteLabelOfBoard($label_id);

            return $this->sendSuccess([
                'message' => __('Label has been deleted', 'fluent-boards'),
                'type'    => 'success',
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function editLabelofBoard(Request $request, $board_id, $label_id)
    {
        $labelData = $this->labelSanitizeAndValidate($request->all(), [
            'bg_color' => 'required|string',
            'color' => 'required|string',
            'label' => 'nullable|string',
        ]);
        try {
            $label = $this->labelService->editLabelofBoard($labelData, $label_id);
            do_action('fluent_boards/board_label_updated', $label);

            return $this->sendSuccess([
                'message' => __('Label has been updated', 'fluent-boards'),
                'label'  => $label,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    private function labelSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeLabel($data);

        return $this->validate($data, $rules);
    }
}
