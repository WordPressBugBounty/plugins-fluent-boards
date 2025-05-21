<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\StageService;
use FluentBoards\Framework\Http\Request\Request;

class StageController extends Controller
{
    private $stageService;
    public function __construct(StageService $stageService)
    {
        parent::__construct();
        $this->stageService = $stageService;
    }
    public function updateStageProperty(Request $request, $board_id, $stage_id)
    {
        $col = $request->getSafe('property');
        $value = $request->getSafe('value');

        try {
            $validatedData = $this->updateStagePropValidationAndSanitation($col, $value);

            $stage = $this->stageService->updateStageProperty($col, $validatedData[$col], $stage_id);

            return $this->sendSuccess([
                'message'      => __('Stage has been updated', 'fluent-boards'),
                'stage'        => $stage
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    private function updateStagePropValidationAndSanitation($col, $value)
    {
        $rules = [
            'title'             => 'required|string',
            'color'             => 'nullable|string',
            'bg_color'          => 'nullable|string',
            'archived_at'       => 'nullable|string',
            'status'            => 'required|string',
            'settings'          => 'nullable|array',
        ];
        if (array_key_exists($col, $rules)) {
            $rule = $rules[$col];
            $data = Helper::sanitizeStage([$col => $value]);

            return $this->validate($data, [
                $col => $rule,
            ]);
        }
    }

    public function sortStageTasks(Request $request, $board_id, $stage_id)
    {

        $order   = $request->getSafe('order', 'sanitize_text_field');
        $orderBy = $request->getSafe('orderBy', 'sanitize_text_field');

        $updatedTasks = $this->stageService->sortStageTasks($order, $orderBy, $stage_id);
        return [
            'message'      => __('Tasks has been sorted', 'fluent-boards'),
            'updatedTasks' => $updatedTasks,
        ];
    }

    public function dragStage(Request $request, $board_id)
    {
        $stage_id = $request->getSafe('stageId');
        $position = $request->getSafe('newPosition');
        try{
            $stage = Stage::findOrFail($stage_id);
            $stage->moveToNewPosition($position);
            do_action('fluent_boards/board_stages_reordered', $board_id, [$stage_id]);
            return $this->sendSuccess([
                'message'       => __('Board stage has been updated', 'fluent-boards'),
                'updatedStages' => $this->stageService->getLastOneMinuteUpdatedStages($board_id)
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function updateStage(Request $request, $board_id, $stage_id)
    {
        $updatedStage = $this->stageSanitizeAndValidate($request->stage, [
            'title'    => 'required|string',
            'cover_bg' => 'nullable'
        ]);
        try {
            $oldStage = Stage::findOrFail($stage_id);
            $board = $oldStage->board;
            $stages = $board->stages()->where('id', '!=', $stage_id)->get();

            $updatedStage = $this->stageService->updateStage($updatedStage, $board->id, $oldStage);

            return $this->sendSuccess([
                'success'      => true,
                'stages'       => $board->stages()->get(),
                'updatedStage' => $updatedStage,
                'message'      => __('Stage has been updated', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    private function stageSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeStage($data);

        return $this->validate($data, $rules);
    }
}