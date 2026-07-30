<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\StageService;

/**
 * Stage create/update and archive/restore MCP tools.
 */
class StageTools
{
    public static function saveStage($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to manage stages on this board', 'fluent-boards'));
        }

        $status = null;
        if (!empty($params['default_task_status'])) {
            if (!in_array($params['default_task_status'], ['open', 'closed'], true)) {
                return MCPHelper::error('invalid_param', __('Invalid stage default task status', 'fluent-boards'), [
                    'allowed' => ['open', 'closed'],
                ]);
            }
            $status = $params['default_task_status'];
        }

        $title = array_key_exists('title', $params) ? sanitize_text_field($params['title']) : null;
        $stageService = new StageService();
        $isUpdate = !empty($params['stage_id']);

        if ($isUpdate) {
            $stage = self::findStage($params['stage_id'], $board->id, true);
            if (is_wp_error($stage)) {
                return $stage;
            }

            if ($title !== null && $title !== '') {
                $stage = $stageService->updateStageProperty('title', $title, $stage->id);
            }
        } else {
            if (!$title) {
                return MCPHelper::error('invalid_param', __('Stage title is required', 'fluent-boards'));
            }

            $stage = $stageService->createStage([
                'title'    => $title,
                'status'   => $status,
                'position' => !empty($params['position']) ? absint($params['position']) : null,
            ], $board->id);

            do_action('fluent_boards/board_stage_added', $board, $stage);
        }

        if ($status !== null && $isUpdate) {
            $stage = $stageService->updateStageProperty('status', $status, $stage->id);
        }

        foreach (['bg_color', 'color'] as $colorField) {
            if (!empty($params[$colorField])) {
                $stage = $stageService->updateStageProperty($colorField, sanitize_text_field($params[$colorField]), $stage->id);
            }
        }

        if ($isUpdate && !empty($params['position'])) {
            $stage->moveToNewPosition(absint($params['position']));
        }

        $stage = Stage::find($stage->id);

        return [
            'stage'   => MCPHelper::formatStage($stage),
            'message' => $isUpdate
                ? __('Stage has been updated', 'fluent-boards')
                : __('Stage has been created', 'fluent-boards'),
        ];
    }

    public static function archiveStage($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to manage stages on this board', 'fluent-boards'));
        }

        $stage = self::findStage($params['stage_id'] ?? 0, $board->id, true);
        if (is_wp_error($stage)) {
            return $stage;
        }

        $archived = array_key_exists('archived', $params) ? (bool) $params['archived'] : true;
        $boardService = new BoardService();

        $stage = $archived
            ? $boardService->archiveStage($board->id, $stage)
            : $boardService->restoreStage($board->id, $stage);

        return [
            'stage'   => MCPHelper::formatStage($stage),
            'message' => $archived
                ? __('Stage has been archived', 'fluent-boards')
                : __('Stage has been restored', 'fluent-boards'),
        ];
    }

    /**
     * MCPHelper::assertStageBelongsToBoard() skips archived stages, which makes restore
     * impossible, so resolve here with an explicit archived opt-in.
     */
    private static function findStage($stageId, $boardId, $includeArchived = false)
    {
        $stageId = absint($stageId);
        if (!$stageId) {
            return MCPHelper::error('invalid_param', __('Provide stage_id', 'fluent-boards'));
        }

        $query = Stage::where('id', $stageId)->where('board_id', absint($boardId));

        if (!$includeArchived) {
            $query->whereNull('archived_at');
        }

        $stage = $query->first();

        if (!$stage) {
            return MCPHelper::error('not_found', __('Stage not found on this board', 'fluent-boards'), [
                'board_id' => absint($boardId),
                'stage_id' => $stageId,
            ]);
        }

        return $stage;
    }
}
