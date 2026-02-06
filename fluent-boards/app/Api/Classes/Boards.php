<?php

namespace FluentBoards\App\Api\Classes;

defined('ABSPATH') || exit;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\LabelService;


/**
 * Boards Class - PHP API Wrapper
 *
 * Boards API Wrapper Class that can be used as <code>FluentBoardsApi('boards')</code> to get the class instance
 *
 * @package FluentBoards\App\Api\Classes
 * @namespace FluentBoards\App\Api\Classes
 *
 * @version 1.0.0
 */
class Boards
{
    private $instance = null;

    private $allowedInstanceMethods = [
        'all',
        'get',
        'find',
        'first',
        'paginate'
    ];

    public function __construct(Board $instance)
    {
        $this->instance = $instance;
    }

    /**
     * Get Boards
     *
     * Use:
     * <code>FluentBoardsApi('boards')->getBoards();</code>
     *
     * @param array $with
     * @return array|Board Model
     */
    public function getBoards($with = [], $sortBy = 'title', $sortOrder = 'asc')
    {
        $userId = get_current_user_id();
        $query = Board::byAccessUser($userId);

        if($sortBy) {
            $query->orderBy($sortBy, $sortOrder);
        }

        if ($with) {
            $query->with($with);
        }

        $boards = $query->get();
        return $boards;
    }

    /**
     * Get stages by board
     *
     * Use:
     * <code>FluentBoardsApi('boards')->getStagesByBoard($board_id);</code>
     *
     * @param int|string $board_id
     * @return array Model
     */
    public function getStagesByBoard($board_id)
    {
        if (empty($board_id)) {
            return [];
        }

        return Board::with('stages')->where('id', $board_id)->get();
    }

    public function create($data)
    {
        if (empty($data['title'])) {
            return false;
        }

        $boardData = $this->boardSanitizeAndValidate($data);

        $boardService = new BoardService();
        $labelService = new LabelService();
        $stageService = new StageService();

        $board = $boardService->createBoard($boardData);

        if (!$board) {
            return false;
        }

        $labelService->createDefaultLabel($board->id);
        $stageService->createDefaultStages($board);

        // if board is created from crm contact
        if (isset($boardData['crm_contact_id'])) {
            $boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
        }
        do_action('fluent_boards/board_created', $board);

        return $board;
    }

    public function getStages($board_id)
    {
        if (empty($board_id)) {
            return [];
        }

        return Stage::where('board_id', $board_id)->where('archived_at', null)->orderBy('position', 'asc')->get();
    }



    private function boardSanitizeAndValidate($data)
    {
        return Helper::sanitizeBoard($data);
    }

    public function getInstance()
    {
        return $this->instance;
    }

    public function __call($method, $params)
    {
        if (in_array($method, $this->allowedInstanceMethods)) {
            return call_user_func_array([$this->instance, $method], $params);
        }

        throw new \Exception(sprintf('Method %s does not exist.', esc_html($method)));
    }

    public function getLabels($boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$board) {
            return false;
        }

        if (!$this->userHasAccessToBoard($boardId)) {
            return false;
        }

        return Label::where('board_id', $board->id)->orderBy('created_at', 'ASC')->get();
    }

    public function createLabel($boardId, $data)
    {
        if (empty($data['bg_color']))
        {
            return false;
        }

        if (empty($data['color']))
        {
            $data['color'] = '#1B2533';
        }

        if (!$this->userHasAccessToBoard($boardId)) {
            return false;
        }

        $labelData = $this->labelSanitize($data);

        $labelService = new LabelService();
        return $labelService->createLabel($labelData, $boardId);
    }

    private function labelSanitize($data)
    {
        return Helper::sanitizeLabel($data);
    }

    /**
     * Check if the current user has access to a board
     *
     * @param int $boardId The board ID to check access for
     * @return bool True if user has access, false otherwise
     */
    private function userHasAccessToBoard($boardId)
    {
        return PermissionManager::userHasPermission($boardId);
    }


}
