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
                'pagination' => MCPHelper::paginationMeta(0, $pagination['page'], $pagination['per_page']),
            ];
        }

        $query = Board::with(['stages', 'labels', 'users'])
            ->whereIn('id', $boardIds);

        if (empty($params['include_archived'])) {
            $query->whereNull('archived_at');
        }

        if (!empty($params['type'])) {
            if (!in_array($params['type'], ['to-do', 'roadmap'], true)) {
                return MCPHelper::notOneOf('type', ['to-do', 'roadmap']);
            }
            $query->where('type', $params['type']);
        }

        if (!empty($params['search'])) {
            global $wpdb;
            // The text is literal: % and _ in it are not wildcards.
            $search = $wpdb->esc_like(sanitize_text_field($params['search']));
            $query->where('title', 'like', '%' . $search . '%');
        }

        $allowedSort = ['id', 'title', 'created_at', 'updated_at'];
        $sortBy = !empty($params['sort_by']) ? $params['sort_by'] : 'created_at';
        if (!in_array($sortBy, $allowedSort, true)) {
            return MCPHelper::notOneOf('sort_by', $allowedSort);
        }
        $sortType = !empty($params['sort_type']) && strtoupper($params['sort_type']) === 'ASC' ? 'ASC' : 'DESC';

        $paginated = $query->orderBy($sortBy, $sortType)
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        $items = [];
        foreach ($paginated->items() as $board) {
            $items[] = MCPHelper::formatBoardSummary($board);
        }

        return [
            'items'      => $items,
            'pagination' => MCPHelper::paginationMeta($paginated->total(), $paginated->currentPage(), $paginated->perPage()),
        ];
    }

    public static function getBoard($params = [])
    {
        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        if (!MCPHelper::canReadBoard($board->id)) {
            return MCPHelper::boardForbidden($board->id);
        }

        return [
            'board' => MCPHelper::formatBoard($board, !empty($params['include_tasks'])),
        ];
    }

    public static function createBoard($params = [])
    {
        if (!PermissionManager::userHasBoardCreationPermission()) {
            return \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::createBoardAccess();
        }

        $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
        if ($title === '') {
            return MCPHelper::invalid('title', 'title is required and cannot be empty.');
        }

        $type = !empty($params['type']) ? sanitize_text_field($params['type']) : 'to-do';
        if (!in_array($type, ['to-do', 'roadmap'], true)) {
            return MCPHelper::notOneOf('type', ['to-do', 'roadmap']);
        }

        // The contact link is skipped without a word when the user may not read contacts.
        // Refuse the call first: a board that reports success must have its contact.
        if (!empty($params['crm_contact_id'])) {
            $contactCheck = MCPHelper::assertCanLinkContact();
            if (is_wp_error($contactCheck)) {
                return $contactCheck;
            }
        }

        $memberIds = self::validateBoardMemberIds($params['member_ids'] ?? []);
        if (is_wp_error($memberIds)) {
            return $memberIds;
        }

        $labelPresetValidation = self::validateLabelPresets($params['labels'] ?? null);
        if (is_wp_error($labelPresetValidation)) {
            return $labelPresetValidation;
        }

        // A stage without a title would be dropped, and the board would get fewer stages
        // than the agent asked for.
        foreach ((array) ($params['stages'] ?? []) as $index => $stage) {
            if (!is_array($stage) || !isset($stage['title']) || trim((string) $stage['title']) === '') {
                return MCPHelper::invalid('stages', sprintf('stages[%d] needs a title.', $index));
            }
        }

        $folderId = !empty($params['folder_id']) ? absint($params['folder_id']) : 0;
        if ($folderId) {
            // Checked before the board exists: the folder service throws after the board is saved.
            try {
                (new FolderService())->assertCanModifyFolder($folderId);
            } catch (\Exception $e) {
                return MCPHelper::error('not_found', sprintf(
                    'No board folder with id %d is yours to use. Leave folder_id out to create the board outside a folder.',
                    $folderId
                ), ['fields' => ['folder_id']]);
            }
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

        if (!empty($boardData['crm_contact_id']) && Helper::canViewCrmContacts()) {
            $boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
        }

        do_action('fluent_boards/board_created', $board);

        if ($folderId) {
            (new FolderService())->addBoardToFolder($folderId, [$board->id]);
        }

        $board = Board::with(['stages', 'labels', 'users'])->find($board->id);

        return [
            'board' => MCPHelper::formatBoard($board),
        ];
    }

    /**
     * Delete a board in two calls. The first returns what will go and a confirm value.
     */
    public static function deleteBoard($params = [])
    {
        if (!PermissionManager::isAdmin()) {
            return \FluentBoards\App\Modules\MCP\AbilitiesRegistrar::deleteBoardAccess($params);
        }

        $board = MCPHelper::resolveBoard($params);
        if (is_wp_error($board)) {
            return $board;
        }

        $tasks = (int) \FluentBoards\App\Models\Task::where('board_id', $board->id)->whereNull('parent_id')->count();
        $subtasks = (int) \FluentBoards\App\Models\Task::where('board_id', $board->id)->whereNotNull('parent_id')->count();
        $willDelete = [
            'board_id' => (int) $board->id,
            'title'    => $board->title,
            'tasks'    => $tasks,
            'subtasks' => $subtasks,
            'stages'   => count($board->stages),
            'labels'   => count($board->labels),
            'members'  => count($board->users),
        ];
        // Everything the preview shows: a task, stage, label or member added after it
        // makes the confirm value stop working.
        $fingerprint = MCPHelper::previewFingerprint($willDelete);

        if (!array_key_exists('confirm', $params)) {
            return MCPHelper::confirmationNeeded('delete-board', $willDelete, MCPHelper::confirmValue('delete-board', $board->id, $fingerprint));
        }

        if (!MCPHelper::confirmMatches($params['confirm'], 'delete-board', $board->id, $fingerprint)) {
            return MCPHelper::confirmInvalid('delete-board');
        }

        $title = $board->title;
        (new BoardService())->deleteBoard($board->id);

        return [
            'deleted'  => true,
            'board_id' => (int) $board->id,
            'title'    => $title,
            'tasks'    => $tasks,
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
            return MCPHelper::invalid('member_ids', 'member_ids must be a list of user ids, for example [12, 40].');
        }

        if (count($memberIds) > self::MAX_MEMBERS_PER_BOARD_CREATE) {
            return MCPHelper::invalid('member_ids', sprintf('member_ids takes at most %d user ids.', self::MAX_MEMBERS_PER_BOARD_CREATE));
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
            return MCPHelper::error('not_found', sprintf('No WordPress user has id %s. Remove it from member_ids.', implode(', ', $unknownIds)), [
                'fields'           => ['member_ids'],
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
                return MCPHelper::invalid('labels', sprintf(
                    'labels: color_preset "%s" is not a preset. Use one of the ids in allowed, or send bg_color and color.',
                    $presetId
                ), ['allowed' => array_column(Constant::LABEL_COLOR_PRESETS, 'id')]);
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
