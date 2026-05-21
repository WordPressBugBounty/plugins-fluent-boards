<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\LabelService;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\StageService;

/**
 * Board read/write tools and board permission helpers.
 */
class BoardTools
{
    public static function canReadBoard($params = [])
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        return $boardId && MCPHelper::canReadBoard($boardId);
    }

    public static function canWriteBoard($params = [])
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        return $boardId && MCPHelper::canWriteBoard($boardId);
    }

    public static function listBoards($params = [])
    {
        $pagination = MCPHelper::normalizePagination($params);
        $userId = get_current_user_id();
        $boardIds = PermissionManager::getBoardIdsForUser($userId);

        if (!$boardIds) {
            return [
                'items'      => [],
                'pagination' => self::paginationMeta(null, $pagination),
            ];
        }

        $query = Board::with(['stages', 'labels', 'users'])
            ->whereIn('id', $boardIds);

        if (empty($params['include_archived'])) {
            $query->whereNull('archived_at');
        }

        if (!empty($params['type'])) {
            $query->where('type', sanitize_text_field($params['type']));
        }

        if (!empty($params['search'])) {
            $search = sanitize_text_field($params['search']);
            $query->where('title', 'like', '%' . $search . '%');
        }

        $allowedSort = ['id', 'title', 'created_at', 'updated_at'];
        $sortBy = !empty($params['sort_by']) && in_array($params['sort_by'], $allowedSort, true)
            ? $params['sort_by']
            : 'created_at';
        $sortType = !empty($params['sort_type']) && strtoupper($params['sort_type']) === 'ASC' ? 'ASC' : 'DESC';

        $paginated = $query->orderBy($sortBy, $sortType)
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        $items = [];
        foreach ($paginated->items() as $board) {
            $items[] = MCPHelper::formatBoardSummary($board);
        }

        return [
            'items'      => $items,
            'pagination' => self::paginationMeta($paginated, $pagination),
        ];
    }

    public static function getBoard($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canReadBoard($board->id)) {
            return MCPHelper::error('forbidden', __('You do not have access to this board', 'fluent-boards'));
        }

        return [
            'board' => MCPHelper::formatBoard($board, !empty($params['include_tasks'])),
        ];
    }

    public static function createBoard($params = [])
    {
        if (!PermissionManager::userHasBoardCreationPermission()) {
            return MCPHelper::error('forbidden', __('You do not have permission to create boards', 'fluent-boards'));
        }

        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        if ($title === '') {
            return MCPHelper::error('invalid_param', __('Board title is required', 'fluent-boards'));
        }

        $type = !empty($params['type']) ? sanitize_text_field($params['type']) : 'to-do';
        if (!in_array($type, ['to-do', 'roadmap'], true)) {
            return MCPHelper::error('invalid_param', __('Invalid board type', 'fluent-boards'), [
                'allowed' => ['to-do', 'roadmap'],
            ]);
        }

        $boardData = Helper::sanitizeBoard([
            'title'          => $title,
            'description'    => isset($params['description']) ? wp_kses_post($params['description']) : '',
            'type'           => $type,
            'currency'       => !empty($params['currency']) ? sanitize_text_field($params['currency']) : 'USD',
            'crm_contact_id' => !empty($params['crm_contact_id']) ? absint($params['crm_contact_id']) : 0,
        ]);

        $boardService = new BoardService();
        $labelService = new LabelService();
        $stageService = new StageService();

        $board = $boardService->createBoard($boardData);
        $labelService->createDefaultLabel($board->id);

        if ($type === 'roadmap') {
            $stageService->createRoadmapStages($board, self::sanitizeRoadmapStages($params['stages'] ?? []));
        } else {
            $stageService->createDefaultStages($board);
        }

        if (!empty($boardData['crm_contact_id'])) {
            $boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
        }

        do_action('fluent_boards/board_created', $board);

        if (defined('FLUENT_BOARDS_PRO') && !empty($params['folder_id']) && class_exists('FluentBoardsPro\App\Services\FolderService')) {
            (new \FluentBoardsPro\App\Services\FolderService())->addBoardToFolder(absint($params['folder_id']), [$board->id]);
        }

        $board = Board::with(['stages', 'labels', 'users'])->find($board->id);

        return [
            'board'   => MCPHelper::formatBoard($board),
            'message' => __('Board has been created successfully', 'fluent-boards'),
        ];
    }

    private static function paginationMeta($paginated, $fallback)
    {
        if (!$paginated) {
            return [
                'total'        => 0,
                'current_page' => (int) $fallback['page'],
                'per_page'     => (int) $fallback['per_page'],
                'last_page'    => 0,
            ];
        }

        return [
            'total'        => (int) $paginated->total(),
            'current_page' => (int) $paginated->currentPage(),
            'per_page'     => (int) $paginated->perPage(),
            'last_page'    => (int) $paginated->lastPage(),
        ];
    }

    private static function sanitizeRoadmapStages($stages)
    {
        $items = [];

        if (!is_array($stages)) {
            return $items;
        }

        foreach ($stages as $index => $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $title = isset($stage['title']) ? sanitize_text_field($stage['title']) : '';
            if ($title === '') {
                continue;
            }

            $items[] = [
                'title'    => $title,
                'slug'     => !empty($stage['slug']) ? sanitize_title($stage['slug']) : sanitize_title($title),
                'position' => !empty($stage['position']) ? absint($stage['position']) : $index + 1,
            ];
        }

        return $items;
    }
}
