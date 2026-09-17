<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\FolderService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\LabelService;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\StageService;

/**
 * Board read/write tools and board permission helpers.
 */
class BoardTools
{
    const MAX_MEMBERS_PER_BOARD_CREATE = 50;

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

        $memberIds = self::validateBoardMemberIds($params['member_ids'] ?? []);
        if (is_wp_error($memberIds)) {
            return $memberIds;
        }

        $labelPresetValidation = self::validateLabelPresets($params['labels'] ?? null);
        if (is_wp_error($labelPresetValidation)) {
            return $labelPresetValidation;
        }

        $description = isset($params['description']) ? MCPHelper::sanitizeMarkdown($params['description']) : '';

        $boardData = Helper::sanitizeBoard([
            'title'          => $title,
            'description'    => '',
            'type'           => $type,
            'currency'       => !empty($params['currency']) ? sanitize_text_field($params['currency']) : 'USD',
            'crm_contact_id' => !empty($params['crm_contact_id']) ? absint($params['crm_contact_id']) : 0,
        ]);
        $boardData['description'] = $description;

        $boardService = new BoardService();
        $labelService = new LabelService();
        $stageService = new StageService();

        $board = $boardService->createBoard($boardData);

        self::createBoardLabels($labelService, $board->id, $params['labels'] ?? null);

        $stages = self::sanitizeStages($params['stages'] ?? []);

        if ($type === 'roadmap') {
            if (!$stages) {
                $stages = self::defaultRoadmapStages();
            }
            $stageService->createRoadmapStages($board, $stages);
        } elseif ($stages) {
            $stageService->createStages($board, $stages);
            self::applyStageStatusOverrides($board->id, $stages);
        } else {
            $stageService->createDefaultStages($board);
        }

        self::addBoardMembers($boardService, $board->id, $memberIds);

        if (!empty($boardData['crm_contact_id'])) {
            $boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
        }

        do_action('fluent_boards/board_created', $board);

        if (!empty($params['folder_id'])) {
            (new FolderService())->addBoardToFolder(absint($params['folder_id']), [$board->id]);
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

    private static function sanitizeStages($stages)
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

            $item = [
                'title'    => $title,
                'slug'     => !empty($stage['slug']) ? sanitize_title($stage['slug']) : sanitize_title($title),
                'position' => !empty($stage['position']) ? absint($stage['position']) : $index + 1,
            ];

            if (!empty($stage['default_task_status']) && in_array($stage['default_task_status'], ['open', 'closed'], true)) {
                $item['default_task_status'] = $stage['default_task_status'];
            }

            $items[] = $item;
        }

        return $items;
    }

    private static function defaultRoadmapStages()
    {
        return [
            [
                'title'    => 'Pending',
                'slug'     => 'pending',
                'position' => 1,
            ],
            [
                'title'    => 'Under Consideration',
                'slug'     => 'under_consideration',
                'position' => 2,
            ],
            [
                'title'    => 'Planned',
                'slug'     => 'planned',
                'position' => 3,
            ],
            [
                'title'    => 'Launched',
                'slug'     => 'launched',
                'position' => 4,
            ],
        ];
    }

    private static function validateBoardMemberIds($memberIds)
    {
        if (!is_array($memberIds)) {
            return MCPHelper::error('invalid_param', __('member_ids must be an array', 'fluent-boards'));
        }

        if (count($memberIds) > self::MAX_MEMBERS_PER_BOARD_CREATE) {
            return MCPHelper::error('invalid_param', __('Too many members in one call', 'fluent-boards'), [
                'max' => self::MAX_MEMBERS_PER_BOARD_CREATE,
            ]);
        }

        $memberIds = MCPHelper::sanitizeIdArray($memberIds);
        if (!$memberIds) {
            return [];
        }

        $found = get_users([
            'include' => $memberIds,
            'fields'  => 'ID',
            'number'  => count($memberIds),
        ]);
        $found = array_map('intval', (array) $found);
        $unknownIds = array_values(array_diff($memberIds, $found));

        if ($unknownIds) {
            return MCPHelper::error('not_found', __('Some users do not exist', 'fluent-boards'), [
                'unknown_user_ids' => $unknownIds,
            ]);
        }

        return $memberIds;
    }

    /**
     * StageService::createStages() only marks stages titled "completed"/"done" as closing stages,
     * so honour explicit per-stage statuses once the stages exist.
     */
    private static function applyStageStatusOverrides($boardId, $stages)
    {
        $overrides = [];
        foreach ($stages as $index => $stage) {
            if (!empty($stage['default_task_status'])) {
                $overrides[$index] = $stage['default_task_status'];
            }
        }

        if (!$overrides) {
            return;
        }

        $created = Stage::where('board_id', $boardId)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get();

        foreach ($overrides as $index => $status) {
            $stage = $created[$index] ?? null;
            if (!$stage) {
                continue;
            }

            $settings = $stage->settings ?: [];
            if (($settings['default_task_status'] ?? '') === $status) {
                continue;
            }

            $settings['default_task_status'] = $status;
            $stage->settings = $settings;
            $stage->save();
        }
    }

    /**
     * Mirrors BoardController::createBoardLabelsFromRequest().
     */
    private static function createBoardLabels($labelService, $boardId, $labels)
    {
        if (!is_array($labels) || !$labels) {
            $labelService->createDefaultLabel($boardId);
            return;
        }

        foreach ($labels as $label) {
            if (!is_array($label)) {
                continue;
            }

            $labelData = Helper::sanitizeLabel([
                'label'    => $label['title'] ?? ($label['label'] ?? ''),
                'bg_color' => $label['bg_color'] ?? '',
                'color'    => $label['color'] ?? '',
                'color_preset' => $label['color_preset'] ?? '',
            ]);

            if (empty($labelData['label']) && empty($labelData['bg_color'])) {
                continue;
            }

            $labelService->createLabel([
                'label'    => $labelData['label'] ?? '',
                'bg_color' => !empty($labelData['bg_color']) ? $labelData['bg_color'] : '#f3f4f6',
                'color'    => !empty($labelData['color']) ? $labelData['color'] : '#1B2533',
                'color_preset' => $labelData['color_preset'] ?? '',
            ], $boardId);
        }
    }

    /**
     * Validate label preset ids before the board is persisted.
     *
     * @param mixed $labels
     * @return true|\WP_Error
     */
    private static function validateLabelPresets($labels)
    {
        if (!is_array($labels)) {
            return true;
        }

        foreach ($labels as $label) {
            if (!is_array($label)) {
                continue;
            }

            $labelData = Helper::sanitizeLabel([
                Constant::LABEL_COLOR_PRESET_SETTING => $label[Constant::LABEL_COLOR_PRESET_SETTING] ?? null,
            ]);
            $presetId = $labelData[Constant::LABEL_COLOR_PRESET_SETTING] ?? null;

            if ($presetId === null || $presetId === '') {
                continue;
            }

            if (!is_string($presetId) || !Constant::getLabelColorPreset($presetId)) {
                return MCPHelper::error('invalid_param', __('Invalid label color preset', 'fluent-boards'));
            }
        }

        return true;
    }

    private static function addBoardMembers($boardService, $boardId, $memberIds)
    {
        if (!$memberIds) {
            return;
        }

        $currentUserId = get_current_user_id();

        foreach ($memberIds as $memberId) {
            if ($memberId === $currentUserId) {
                continue;
            }

            $boardService->addMembersInBoard($boardId, $memberId);
        }
    }
}
