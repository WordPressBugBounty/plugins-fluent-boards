<?php

namespace FluentBoards\App\Api\Classes;

use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\StageService;

defined('ABSPATH') || exit;

class Stages
{
    private $instance = null;

    public function __construct(Stage $stage)
    {
        $this->instance = $stage;
    }

    /**
     * Get a stage only when the current user can read its board.
     *
     * @param int|string $id
     * @param array $with
     * @return Stage|false
     */
    public function getStage($id, $with = [])
    {
        if ( ! $id) {
            return false;
        }

        $stage = Stage::where('id', $id)->with($with)->first();

        if ( ! $stage) {
            return false;
        }

        if (!$this->canReadBoard($stage->board_id)) {
            return false;
        }

        return $stage;
    }

    public function getStagesByBoard($boardId, $with = [])
    {
        if ( ! $boardId) {
            return false;
        }

        if (!$this->canReadBoard($boardId)) {
            return false;
        }

        $query = Stage::where('board_id', $boardId)
                      ->whereNull('archived_at')
                      ->orderBy('position', 'asc');

        if ( ! empty($with)) {
            $query->with($with);
        }

        $stages = $query->get();

        return $stages;
    }

    /**
     * Create a new stage
     *
     * @param array $data Stage data including title and board_id
     * @return Stage|false The created stage or false on failure
     */
    public function create($data)
    {
        // Validate required data
        if (empty($data) || empty($data['title']) || empty($data['board_id'])) {
            return false;
        }

        // Check if current user has access to board
        if (!$this->canWriteBoard($data['board_id'])) {
            return false;
        }

        $stageData = Helper::sanitizeStage($data);

        $stageService = new StageService();
        $stage        = $stageService->createStage($stageData,
            $stageData['board_id']);

        return $stage;
    }

    public function updateProperty($stageId, $property, $value)
    {
        $stageService = new StageService();

        $stage = Stage::findOrFail($stageId);

        if ( ! $stage) {
            return false;
        }

        $allowedColumns = ['title', 'status', 'bg_color'];

        if ( ! in_array($property, $allowedColumns)) {
            return false;
        }

        //checking if current user has access to board
        if (!$this->canWriteBoard($stage->board_id)) {
            return false;
        }

        $stage = $stageService->updateStageProperty($property, $value,
            $stageId);

        return $stage;
    }

    public function archiveStage($stageId)
    {
        if ( ! $stageId) {
            return false;
        }

        $stage = Stage::findOrFail($stageId);

        if ( ! $stage) {
            return false;
        }

        //checking if current user has access to board
        if (!$this->canWriteBoard($stage->board_id)) {
            return false;
        }

        $boardService = new BoardService();

        $stage = $boardService->archiveStage($stage->board_id, $stage);

        return $stage;
    }

    public function restoreStage($stageId)
    {
        if ( ! $stageId) {
            return false;
        }

        $stage = Stage::findOrFail($stageId);

        if ( ! $stage) {
            return false;
        }

        //checking if current user has access to board
        if (!$this->canWriteBoard($stage->board_id)) {
            return false;
        }

        $boardService = new BoardService();

        return $boardService->restoreStage($stage->board_id, $stage);
    }


    /**
     * Block raw model proxy calls so board access cannot be bypassed.
     *
     * @param string $method
     * @param array $params
     * @throws \Exception
     */
    public function __call($method, $params)
    {
        throw new \Exception(sprintf('Method %s does not exist.', esc_html($method)));
    }

    /**
     * Check if the current user can read a board.
     *
     * @param int $boardId
     * @return bool
     */
    private function canReadBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission($boardId, 'GET');
    }

    /**
     * Check if the current user can write to a board.
     *
     * @param int $boardId
     * @return bool
     */
    private function canWriteBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission($boardId, 'POST');
    }


}
