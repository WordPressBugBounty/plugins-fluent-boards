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
            return MCPHelper::boardForbidden($board->id, true);
        }

        $status = null;
        if (!empty($params['default_task_status'])) {
            if (!in_array($params['default_task_status'], ['open', 'closed'], true)) {
                return MCPHelper::notOneOf('default_task_status', ['open', 'closed']);
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

            if ($title !== null && $title === '') {
                return MCPHelper::invalid('title', 'title cannot be empty. Leave title out to keep the current one.');
            }

            if ($title !== null) {
                $stage = $stageService->updateStageProperty('title', $title, $stage->id);
            }
        } else {
            if (!$title) {
                return MCPHelper::invalid('title', 'title is required to create a stage. To update a stage send its stage_id.');
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
            'stage'    => MCPHelper::formatStage($stage),
            'board_id' => (int) $board->id,
            'created'  => !$isUpdate,
        ];
    }

    public static function archiveStage($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canWriteBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id, true);
        }

        $stage = self::findStage($params['stage_id'] ?? 0, $board->id, true);
        if (is_wp_error($stage)) {
            return $stage;
        }

        $archived = array_key_exists('archived', $params) ? (bool) $params['archived'] : true;
        $boardService = new BoardService();

        // Archiving twice would stamp the stage again and fire the archive events a second time.
        if ($archived === (bool) $stage->archived_at) {
            MCPHelper::addWarning($archived ? 'The stage was already archived. Nothing changed.' : 'The stage was not archived. Nothing changed.');
        } else {
            $stage = $archived
                ? $boardService->archiveStage($board->id, $stage)
                : $boardService->restoreStage($board->id, $stage);
        }

        return [
            'stage'    => MCPHelper::formatStage($stage),
            'board_id' => (int) $board->id,
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
            return MCPHelper::invalid('stage_id', 'stage_id is required: a stage id from get-board.');
        }

        $query = Stage::where('id', $stageId)->where('board_id', absint($boardId));

        if (!$includeArchived) {
            $query->whereNull('archived_at');
        }

        $stage = $query->first();

        if (!$stage) {
            return MCPHelper::error('not_found', sprintf(
                'Board %d has no stage with id %d. get-board lists the active stages.',
                absint($boardId),
                $stageId
            ), ['fields' => ['stage_id']]);
        }

        return $stage;
    }
}
