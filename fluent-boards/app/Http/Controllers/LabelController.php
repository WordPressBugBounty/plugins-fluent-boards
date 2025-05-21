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
            return $this->sendError($e->getMessage(), 400);
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
            return $this->sendError($e->getMessage(), 400);
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
            'task_id'    => $request->getSafe('taskId', 'intval'),
            'board_term_id' => $request->getSafe('labelId', 'intval'),
        ];

        $labelData = $this->labelSanitizeAndValidate($requestData, [
            'task_id'    => 'required|integer',
            'board_term_id' => 'required|integer',
        ]);
        try {
            $label = $this->labelService->createLabelForTask($labelData);

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
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function deleteLabelOfTask($board_id, $task_id, $label_id)
    {
        try {
            $this->labelService->deleteLabelOfTask($task_id, $label_id);

            return $this->sendSuccess([
                'message' => __('Label has been deleted', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function deleteLabelOfBoard($board_id, $label_id)
    {
        try {
            $this->labelService->deleteLabelOfBoard($label_id);

            return $this->sendSuccess([
                'message' => __('Label has been deleted', 'fluent-boards'),
                'type'    => 'success',
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function editLabelofBoard(Request $request, $board_id, $label_id)
    {
        $labelData = $this->labelSanitizeAndValidate($request->all(), [
            'bg_color' => 'required|string',
            'color' => 'nullable|string',
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
