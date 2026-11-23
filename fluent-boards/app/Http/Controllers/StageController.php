<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\TaskService;
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
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);
        $col = $request->getSafe('property', 'sanitize_text_field');
        $value = $request->getSafe('value', 'sanitize_text_field');

        try {
            $validatedData = $this->updateStagePropValidationAndSanitation($col, $value);
            $this->findStageOnBoard($stage_id, $board_id);

            $stage = $this->stageService->updateStageProperty($col, $validatedData[$col], $stage_id);

            return $this->sendSuccess([
                'message'      => __('Stage has been updated', 'fluent-boards'),
                'stage'        => $stage
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function copyStage(Request $request, $board_id, $stage_id)
    {
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);

        try {
            $this->findStageOnBoard($stage_id, $board_id);

            // Sync cursor taken before the copy, so a long copy still returns every new row.
            $startedAt = date_i18n('Y-m-d H:i:s', current_time('timestamp') - 5);

            $result = $this->stageService->copyStage($board_id, $stage_id, [
                'title'      => $request->getSafe('title', 'sanitize_text_field'),
                'with_tasks' => filter_var($request->get('with_tasks'), FILTER_VALIDATE_BOOLEAN),
            ]);

            $message = __('Stage has been copied', 'fluent-boards');

            if ($result['failed_tasks']) {
                $message = sprintf(
                    /* translators: 1: number of copied tasks, 2: number of tasks that could not be copied */
                    __('Stage has been copied with %1$d tasks. %2$d tasks could not be copied.', 'fluent-boards'),
                    $result['copied_tasks'],
                    $result['failed_tasks']
                );
            } elseif ($result['copied_tasks']) {
                $message = sprintf(
                    /* translators: %d is the number of copied tasks */
                    _n('Stage has been copied with %d task', 'Stage has been copied with %d tasks', $result['copied_tasks'], 'fluent-boards'),
                    $result['copied_tasks']
                );
            }

            return $this->sendSuccess([
                'message'       => $message,
                'stage'         => $result['stage'],
                'copied_tasks'  => $result['copied_tasks'],
                'failed_tasks'  => $result['failed_tasks'],
                'updatedStages' => $this->stageService->getLastOneMinuteUpdatedStages($board_id, $startedAt),
                'updatedTasks'  => $result['copied_tasks'] ? (new TaskService())->getLastOneMinuteUpdatedTasks($board_id, $startedAt) : [],
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
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
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);
        $order   = $request->getSafe('order', 'sanitize_text_field');
        $orderBy = $request->getSafe('orderBy', 'sanitize_text_field');
        $this->findStageOnBoard($stage_id, $board_id);

        $updatedTasks = $this->stageService->sortStageTasks($order, $orderBy, $stage_id);
        return [
            'message'      => __('Tasks has been sorted', 'fluent-boards'),
            'updatedTasks' => $updatedTasks,
        ];
    }

    public function dragStage(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $stage_id = $request->getSafe('stageId', 'intval');
        $position = $request->getSafe('newPosition', 'intval');
        try{
            $stage = $this->findStageOnBoard($stage_id, $board_id);
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
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);
        $stageData = $request->getSafe('stage');
        if (!is_array($stageData)) {
            $stageData = [];
        }
        $updatedStage = $this->stageSanitizeAndValidate($stageData, [
            'title'    => 'required|string',
            'cover_bg' => 'nullable'
        ]);
        try {
            $oldStage = $this->findStageOnBoard($stage_id, $board_id);
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

    /**
     * Resolve a stage only when it belongs to the requested board.
     *
     * @param int $stageId
     * @param int $boardId
     * @return Stage
     * @throws \Exception
     */
    private function findStageOnBoard($stageId, $boardId)
    {
        $stage = Stage::where('id', absint($stageId))
            ->where('board_id', absint($boardId))
            ->first();

        if (!$stage) {
            throw new \Exception(esc_html__('Stage not found', 'fluent-boards'));
        }

        return $stage;
    }
}
