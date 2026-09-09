<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\CommentService;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\DescriptionMarkdownConverter;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\InstallService;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\TaskService;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\FolderService;
use FluentBoards\App\Services\UploadService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\PublicAccessService;
use FluentBoards\App\Hooks\Handlers\BoardHandler;
use FluentBoards\App\Hooks\Handlers\BoardMenuHandler;
use FluentBoards\App\Services\LabelService;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\Framework\Support\Collection;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentBoardsPro\App\Services\CustomFieldService;
use FluentBoardsPro\App\Services\RemoteUrlParser;

class BoardController extends Controller
{
    private const LEGACY_BOARD_ASSOCIATED_CRM_CONTACT = 'crm_contact';

    private $boardService;
    private $taskService;
    private $stageService;
    private $labelService;

    public function __construct(
        BoardService $boardService,
        TaskService  $taskService,
        StageService $stageService,
        LabelService $labelService
    )
    {
        parent::__construct();
        $this->boardService = $boardService;
        $this->taskService = $taskService;
        $this->stageService = $stageService;
        $this->labelService = $labelService;
    }

    public function getBoards(Request $request)
    {
        $per_page = $request->getSafe('per_page', 'intval', 100);
        $per_page = max(1, min(100, $per_page));
        $userId = get_current_user_id();
        $type = $request->getSafe('type', 'sanitize_text_field', 'to-do');

        $order   = $request->getSafe('order', 'sanitize_text_field', 'created_at');
        $orderBy = $request->getSafe('orderBy', 'sanitize_text_field', 'DESC');
        $searchInput = $request->getSafe('searchInput', 'sanitize_text_field');

        $option = $request->getSafe('option', 'sanitize_text_field');
        $folderId = $request->getSafe('fid', 'intval'); // Get folder ID from request

        // Initialize the query based on archive status
        if (!defined('FLUENT_ROADMAP')) {
            if ($option == 'archived') {
                $relatedBoardsQuery = Board::whereNotNull('archived_at')->where('type', 'to-do')->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->where('type', 'to-do')->byAccessUser($userId);
            }
        } else {
            if ($option == 'archived') {
                $relatedBoardsQuery = Board::whereNotNull('archived_at')->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->byAccessUser($userId);
            }
        }

        // Scope pinned before pagination, from the same id source as getBoardCounts(),
        // so the pinned page and board_counts.pinned always agree. Filtering pinned
        // after pagination would drop pinned boards that fall on a later active page.
        if ($option == 'pinned') {
            $pinnedIds = $this->boardService->getPinnedBoardIds();

            $relatedBoardsQuery = $pinnedIds
                ? $relatedBoardsQuery->whereIn('id', $pinnedIds)
                : $relatedBoardsQuery->where('id', 0);
        }

        if ($folderId) {
            $boardIds = (new FolderService())->getBoardIdsByFolder($folderId);
            $relatedBoardsQuery = $boardIds
                ? $relatedBoardsQuery->whereIn('id', $boardIds)
                : $relatedBoardsQuery->where('id', 0);
        }

        // Filter out boards that are templates (exclude boards where settings->is_template is true)
        $relatedBoardsQuery = $relatedBoardsQuery->excludeTemplates();

        // Add search functionality
        if (!empty($searchInput)) {
            $relatedBoardsQuery = $relatedBoardsQuery->where('title', 'like', '%' . $searchInput . '%');
        }

        $relatedBoards = $relatedBoardsQuery->orderBy($order, $orderBy)
                                            ->withCount('completedTasks')
                                            ->with('stages', 'users')
                                            ->paginate($per_page);

        foreach ($relatedBoards as $relatedBoard) {
            $relatedBoard->description = DescriptionMarkdownConverter::normalize($relatedBoard->description);
            $relatedBoard->users = Helper::sanitizeUserCollections($relatedBoard->users);
            $relatedBoard->is_pinned = $this->boardService->isPinned($relatedBoard->id);
        }

        $response = [
            'boards'       => $relatedBoards,
            'board_counts' => $this->boardService->getBoardCounts($userId)
        ];

        $folderMapping = $this->getBoardFolderMapping($userId);
        $response['folder_mapping'] = $folderMapping;
        if ($folderId) {
            $response['current_folder'] = isset($folderMapping[$folderId])
                ? $this->getCurrentFolderInfoFromMapping($folderMapping[$folderId])
                : null;
        }

        return $this->sendSuccess($response);
    }

    /**
     * Get folder mapping for boards
     */
    private function getBoardFolderMapping($userId)
    {
        $folderService = new FolderService();
        $folders = $folderService->getFolders($userId);

        $mapping = [];
        foreach ($folders as $folder) {
            $mapping[$folder->id] = [
                'id' => $folder->id,
                'title' => $folder->title,
                'board_ids' => $folder->boards ? $folder->boards->pluck('id')->toArray() : []
            ];
        }

        return $mapping;
    }

    private function getCurrentFolderInfoFromMapping(array $folder)
    {
        return [
            'id'          => $folder['id'],
            'title'       => $folder['title'],
            'board_count' => count($folder['board_ids'])
        ];
    }

    /**
     * Get the list of boards and their associated stages for the current user.
     *
     * @param \FluentBoards\Framework\Http\Request\Request $request
     * @return \WP_REST_Response
     */
    public function getBoardsList(Request $request)
    {
        $userId = get_current_user_id();

        // Query to fetch boards that are not archived and accessible by the user
        // Check if the FLUENT_ROADMAP constant is defined
        if (!defined('FLUENT_ROADMAP')) {
            $relatedBoardsQuery = Board::whereNull('archived_at')->where('type', 'to-do')->excludeTemplates()->byAccessUser($userId);
        } else {
            $relatedBoardsQuery = Board::whereNull('archived_at')->excludeTemplates()->byAccessUser($userId);
        }

        $relatedBoards = $relatedBoardsQuery->with('stages')->get();

        // Fetch the stages associated with the boards
        $stages = Stage::whereIn('board_id', $relatedBoards->pluck('id'))->where('archived_at', null)->get();

        return $this->sendSuccess([
            'boards' => $relatedBoards,
            'all_stages' => $stages,
        ], 200);
    }
    public function getOnlyBoardsByUser(Request $request)
    {
        try {
            $userId = get_current_user_id();

            $searchInput = $request->getSafe('searchInput', 'sanitize_text_field');


            if(!defined('FLUENT_ROADMAP'))
            {
                $relatedBoardsQuery = Board::whereNull('archived_at')->where('type', 'to-do')->excludeTemplates()->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->excludeTemplates()->byAccessUser($userId);
            }

            if (!empty($searchInput)) {
                $relatedBoardsQuery = $relatedBoardsQuery->where('title', 'like', '%' . $searchInput . '%');
            }

            $relatedBoards = $relatedBoardsQuery->orderBy('created_at', 'DESC')->get();

            return $this->sendSuccess([
                'boards' => $relatedBoards
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function getRecentBoards()
    {
        $boards = $this->boardService->getRecentBoards();

        if (!$boards || $boards->isEmpty()) {
            $boards = Board::whereNull('archived_at')
                ->excludeTemplates()
                ->availableInCurrentInstall()
                ->byAccessUser(get_current_user_id())
                ->limit(4)
                ->withCount('completedTasks')
                ->with(['stages', 'users'])
                ->get();
        }

        foreach ($boards as $board) {
            $board->users = Helper::sanitizeUserCollections($board->users);
            $board->is_pinned = $this->boardService->isPinned($board->id);
        }

        return [
            'boards' => $boards,
        ];
    }

    /*
     * TODO: Refactor this method , remove this
     */
    public function getBoardsByType($type)
    {
        $boards = $this->boardService->getBoardsByType($type);

        return $this->sendSuccess([
            'boards' => $boards,
        ]);
    }

    public function createFirstBoard(Request $request)
    {
        $boardData = $this->boardSanitizeAndValidate($request->get('board'), [
            'title'          => 'required|string',
            'description'    => 'nullable',
            'type'           => 'required|string',
            'currency'       => 'nullable|string',
            'crm_contact_id' => 'nullable|numeric',
        ]);

        $installFluentCRM = $request->getSafe('withFluentCRM', 'sanitize_text_field') == 'yes' ? true : false;

        $postStages = $request->get('stages');
        if (!is_array($postStages)) {
            $postStages = [];
        }
        $stageData = array();
        foreach ($postStages as $stage) {
            $temp = $this->stageSanitizeAndValidate($stage, [
                'title' => 'required|string',
            ]);
            $stageData[] = $temp;
        }

        $taskData = null;
        if ($request->get('task')) {
            $taskData = $this->taskSanitizeAndValidate($request->get('task'), [
                'title' => 'required|string',
            ]);
        }

        $board = $this->boardService->createBoard($boardData);
        $this->labelService->createDefaultLabel($board->id);
        $type = ucfirst($boardData['type']);
        $stage = $this->stageService->createStages($board, $stageData);

        if ($taskData) {
            $taskData['board_id'] = $board->id;
            $taskData['stage_id'] = $stage->id;
            $this->taskService->createTask($taskData, $board->id);
        }

        do_action('fluent_boards/board_created', $board);

        if ($installFluentCRM && !defined('FLUENTCRM')) {
            InstallService::install('fluent-crm');
        }

        return [
            'message' => __('Board has been created', 'fluent-boards'),
            'board'   => $board,
        ];
    }

    public function skipOnboarding(Request $request)
    {
        $onboarding = Meta::where('key', Constant::FBS_ONBOARDING)->first();
        if($onboarding && $onboarding->value == 'no'){
            $onboarding->value = 'yes' ;
            $onboarding->save();
        }

        return [
            'message' => __('Onboarding skipped successfully', 'fluent-boards'),
        ];
    }

    public function create(Request $request)
    {
        $boardData = $this->boardSanitizeAndValidate($request->get('board'), [
            'title'          => 'required|string',
            'description'    => 'nullable',
            'type'           => 'required|string',
            'currency'       => 'nullable|string',
            'crm_contact_id' => 'nullable|numeric',
            'folder_id'      => 'nullable',
        ]);

        try {
            $folderId = $request->getSafe('folder_id', 'intval');
            $folderService = new FolderService();
            if ($folderId) {
                $folderService->assertCanModifyFolder($folderId);
            }

            $backgroundData = $this->sanitizeCreateBoardBackground($request->get('background'));
            if (!empty($backgroundData)) {
                $boardData['background'] = $backgroundData;
            }

            $board = $this->boardService->createBoard($boardData);
            $this->createBoardLabelsFromRequest($request, $board->id);
            $this->addBoardMembersFromRequest($request, $board->id);
            $type = ucfirst($boardData['type']);
            $stages = $request->get('stages');
            $sanitizedStages = [];

            if (is_array($stages) && !empty($stages)) {
                foreach ($stages as $stage) {
                    $sanitizedStages[] = $this->stageSanitizeAndValidate($stage, [
                        'title' => 'required|string',
                        'slug' => 'nullable|string',
                        'position' => 'nullable|numeric'
                    ]);
                }
            }

            if (isset($boardData['type']) && $boardData['type'] == 'roadmap') {
                $this->stageService->createRoadmapStages($board, $sanitizedStages);
            } elseif (!empty($sanitizedStages)) {
                $this->stageService->createStages($board, $sanitizedStages);
            } else {
                $this->stageService->createDefaultStages($board);
            }

            // if board is created from crm contact
            if (isset($boardData['crm_contact_id'])) {
                $this->boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
            }

            do_action('fluent_boards/board_created', $board);


            if ($folderId) {
                $folderService->addBoardToFolder($folderId, [$board->id]);
            }

            $message = __('Board has been created successfully', 'fluent-boards');

            return $this->send([
                'message' => $message,
                'board'   => $board,
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ]);
        }
    }

    private function sanitizeCreateBoardBackground($background)
    {
        if (!is_array($background) || empty($background['id'])) {
            return '';
        }

        $backgroundId = sanitize_text_field($background['id']);

        // Only accept ids from the curated solid/gradient palettes and always
        // persist the canonical value from the constant (never the client-supplied
        // color) so arbitrary CSS can't be stored and later rendered into a style.
        $allowedBackgrounds = [];
        foreach (array_merge(
            Constant::BOARD_BACKGROUND_DEFAULT_SOLID_COLORS,
            Constant::BOARD_BACKGROUND_DEFAULT_GRADIENT_COLORS
        ) as $option) {
            if (isset($option['id'], $option['value'])) {
                $allowedBackgrounds[$option['id']] = $option['value'];
            }
        }

        if (!isset($allowedBackgrounds[$backgroundId])) {
            return '';
        }

        return [
            'id'        => $backgroundId,
            'color'     => $allowedBackgrounds[$backgroundId],
            'is_image'  => false,
            'image_url' => null,
        ];
    }

    private function createBoardLabelsFromRequest(Request $request, $boardId)
    {
        $labels = $request->get('labels');

        if (!is_array($labels)) {
            $this->labelService->createDefaultLabel($boardId);
            return;
        }

        foreach ($labels as $label) {
            $labelData = Helper::sanitizeLabel((array) $label);

            if (empty($labelData['label']) && empty($labelData['bg_color'])) {
                continue;
            }

            $this->labelService->createLabel([
                'label'    => $labelData['label'] ?? '',
                'bg_color' => $labelData['bg_color'] ?? '#f3f4f6',
                'color'    => $labelData['color'] ?? '#1B2533',
            ], $boardId);
        }
    }

    private function addBoardMembersFromRequest(Request $request, $boardId)
    {
        $memberIds = $request->get('member_ids');

        if (!is_array($memberIds)) {
            return;
        }

        $memberIds = array_filter(array_unique(array_map('intval', $memberIds)));
        $currentUserId = get_current_user_id();

        foreach ($memberIds as $memberId) {
            if ($memberId === $currentUserId) {
                continue;
            }

            $this->boardService->addMembersInBoard($boardId, $memberId);
        }
    }

    /**
     * Get archived stages for a board with optional pagination and archive actor metadata.
     */
    public function getArchivedStage(Request $request, $board_id)
    {
        try {
            $board_id = absint($board_id);
            $sanitizedParams = [
                'noPagination' => $request->getSafe('noPagination', 'boolval', false),
                'per_page'     => $request->getSafe('per_page', 'intval', 30),
                'page'         => $request->getSafe('page', 'intval', 1),
            ];

            $stages = $this->stageService->getArchivedStages($sanitizedParams, $board_id);

            return $this->sendSuccess([
                'stages' => $stages,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function find(Request $request, $board_id)
    {
        $board = Board::findOrFail($board_id);
        $board->description = DescriptionMarkdownConverter::normalize($board->description);
        $includeArchived = filter_var($request->get('include_archived', false), FILTER_VALIDATE_BOOLEAN);
        $board->background = maybe_unserialize($board->background);
        $board->createdOn = $board->created_at->format('Y-m-d');

        $board->load(['users', 'labels', 'owner']);

        if ($includeArchived) {
            $board->stages = Stage::where('board_id', $board_id)
                ->orderBy('position', 'asc')
                ->get();
        } else {
            $board->load('stages');
        }

        if (defined('FLUENT_BOARDS_PRO')){
            $customFiledPositionMeta = $board->getMetaByKey('custom_field_positions');
            if(!$customFiledPositionMeta) {
                (new CustomFieldService())->reIndexCustomFieldPositions($board_id);
                $board->updateMeta('custom_field_positions', 'yes');
            }
            
            $board->load(['customFields']);
        }

        $this->boardService->updateRecentBoards($board_id);

        $board->labelColor = Constant::TRELLO_COLOR_MAP;
        $board->labelColorText = Constant::TEXT_COLOR_MAP;

        $board->users = Helper::sanitizeUserCollections($board->users);
        $board->owner = Helper::sanitizeUserCollections($board->owner);

        $board->is_pinned = $this->boardService->isPinned($board->id);

        $board = apply_filters('fluent_boards/board_find', $board);

        return [
            'board'     => $board,
            'synced_at' => current_time('mysql')
        ];
    }

    public function update(Request $request, $board_id)
    {
        // Board identity (title/description) is manager-only. This action shares the
        // `update` name with CommentController@update under the same policy group, so the
        // guard lives here rather than in a SingleBoardPolicy::update() method that would
        // also block ordinary members from editing their own comments.
        if (!PermissionManager::isBoardManager(absint($board_id))) {
            return $this->sendError([
                'message' => __('You do not have permission to edit this board.', 'fluent-boards'),
            ], 403);
        }

        $boardData = $this->boardSanitizeAndValidate($request->only(['title', 'description']), [
            'title'       => 'required|string',
            'description' => 'nullable|string',
        ]);

        $board = Board::findOrFail($board_id);
        $boardData['description'] = DescriptionMarkdownConverter::normalize($boardData['description']);

        $oldBoard = clone $board;
        $board->fill($boardData);
        $board->save();

        do_action('fluent_boards/board_updated', $board, $oldBoard);

        return [
            'message' => __('Board has been updated', 'fluent-boards'),
            'board'   => $board,
            'stages'  => $board->stages()->get(),  
        ];
    }

    public function archiveStage($board_id, $stage_id)
    {
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);

        try {
            $stage = $this->findStageOnBoard($stage_id, $board_id);

            $updatedStage = $this->boardService->archiveStage($board_id, $stage);

            return $this->sendSuccess([
                'updatedStage' => $updatedStage,
                'message'      => __('Stage has been archived', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function restoreStage($board_id, $stage_id)
    {
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);

        try {
            $stage = $this->findStageOnBoard($stage_id, $board_id);

            $updatedStage = $this->boardService->restoreStage($board_id, $stage);

            return $this->sendSuccess([
                'success' => true,
                'updatedStage' => $updatedStage,
                'message' => __('Stage has been restored', 'fluent-boards')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }


    public function repositionStages(Request $request, $board_id)
    {
        $incomingList = $request->get('list');
        if (!is_array($incomingList)) {
            $incomingList = [];
        }
        $incomingList = array_map('intval', $incomingList);
        try {
            foreach ($incomingList as $stageId) {
                $this->findStageOnBoard($stageId, $board_id);
            }

            $this->boardService->repositionStages($board_id, $incomingList);
            return $this->sendSuccess([
                'message'       => __('Stages Reordered', 'fluent-boards'),
                'updatedStages' => $this->stageService->getLastOneMinuteUpdatedStages($board_id)
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getAssigneesByBoard($board_id)
    {
        return $this->sendSuccess([
            'data' => $this->boardService->getAssigneesByBoard($board_id),
        ], 200);
    }

    public function delete($board_id)
    {
        try {
            if (!PermissionManager::isAdmin()) {
                throw new \Exception(esc_html__('You do not have permission to delete this board', 'fluent-boards'), 400);
            }
            $this->boardService->deleteBoard($board_id);

            return $this->sendSuccess([
                'message' => __('Board has been deleted', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getCurrencies()
    {
        return BoardHandler::getCurrencies();
    }

    public function getActivities(Request $request, $board_id)
    {
        try {
            $activities = $this->boardService->getActivities($board_id, [
                'per_page' => $request->getSafe('per_page', 'intval', 40),
                'page' => $request->getSafe('page', 'intval', 1),
            ]);
            return $this->sendSuccess([
                'activities' => $activities,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /*
     * TODO: Refactor this method - for Masiur
     */
    public function getBoardUsers($board_id)
    {
        $board = Board::findOrFail($board_id);

        $boardObjects = Relation::where('object_type', 'board_user')
            ->where('object_id', $board_id)
            ->get()->keyBy('foreign_id');

        $superAdminIds = Meta::query()->where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->get()->pluck('object_id')->toArray();

        $userIds = $boardObjects->pluck('foreign_id')->toArray();

        $coreUsers = [];
        if ($userIds) {
            // Get the users who are in the board (members and managers
            $coreUsers = get_users([
                'include' => $userIds
            ]);
        }

        $formattedUsers = [];

        foreach ($coreUsers as $user) {
            $name = trim($user->first_name . ' ' . $user->last_name);
            if (!$name) {
                $name = $user->display_name;
            }

            $boardRelation = $boardObjects[$user->ID] ?? null;


            $formattedUsers[] = [
                'ID'           => $user->ID,
                'display_name' => $name,
                'user_login'   => $user->user_login,
                'email'        => $user->user_email,
                'photo'        => fluent_boards_user_avatar($user->user_email, $name),
                'role'         => $this->boardUserRole($boardRelation),
                'is_super'     => in_array($user->ID, $superAdminIds),
                'is_wpadmin'   => $user->has_cap('manage_options')
            ];
        }

        // order formatted users by display_name
        usort($formattedUsers, function ($a, $b) {
            return strcmp($a['display_name'], $b['display_name']);
        });

        $returnData = [
            'users'         => Helper::sanitizeUsersArray($formattedUsers, $board_id),
            'global_admins' => []
        ];

        if (!PermissionManager::isAdmin(get_current_user_id())) {
            return $returnData;
        }

        /*
         * These are the rest of the Fluent Boards and WordPress admins who are not in the board.
         */
        $fluentBoardAdminIds = Meta::query()->where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->whereNotIn('object_id', $userIds)
            ->get()
            ->pluck('object_id')
            ->toArray();

        $wordPressAdminIds = get_users([
            'capability' => 'manage_options',
            'exclude'    => $userIds,
            'fields'     => 'ID',
        ]);

        $adminUserIds = array_values(array_unique(array_map('intval', array_merge($fluentBoardAdminIds, $wordPressAdminIds))));

        if ($adminUserIds) {
            $adminUsers = get_users([
                'include' => $adminUserIds,
            ]);

            $formattedAdminUsers = [];

            foreach ($adminUsers as $user) {
                $name = trim($user->first_name . ' ' . $user->last_name);
                if (!$name) {
                    $name = $user->display_name;
                }

                $formattedAdminUsers[] = [
                    'ID'           => $user->ID,
                    'display_name' => $name,
                    'email'        => $user->user_email,
                    'photo'        => fluent_boards_user_avatar($user->user_email, $name),
                    'role'         => 'admin',
                    'is_super'     => in_array($user->ID, $superAdminIds),
                    'is_wpadmin'   => $user->has_cap('manage_options')
                ];
            }

            // order formatted users by display_name
            usort($formattedAdminUsers, function ($a, $b) {
                return strcmp($a['display_name'], $b['display_name']);
            });

            $returnData['global_admins'] = Helper::sanitizeUsersArray($formattedAdminUsers, $board_id);
        }

        return $this->sendSuccess($returnData, 200);
    }


    public function removeUserFromBoard($board_id, $userId)
    {
        $this->boardService->removeUserFromBoard($board_id, $userId);

        if (!PermissionManager::isAdmin($userId)) {
            $this->boardService->removeFromRecentlyOpened($board_id, $userId);
        }

        return [
            'message' => __('Member removed successfully', 'fluent-boards'),
        ];
    }

    public function addMembersInBoard(Request $request, $board_id)
    {
        $memberId = $request->getSafe('memberId', 'intval');
        $isViewerOnly = $request->getSafe('isViewerOnly', 'sanitize_text_field');
        $member = $this->boardService->addMembersInBoard($board_id, $memberId, $isViewerOnly);

        if ($member === null) {
            return $this->sendError([
                'message' => __('User not found.', 'fluent-boards'),
            ], 404);
        }

        if (!$member) {
            return $this->sendError([
                'message' => __('User already a member', 'fluent-boards'),
            ], 409);
        }


        return [
            'message' => __('Member added successfully', 'fluent-boards'),
            'member'  => Helper::sanitizeUserCollections($member)
        ];
    }

    private function boardSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeBoard($data);

        return $this->validate($data, $rules);
    }

    private function stageSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeStage($data);

        return $this->validate($data, $rules);
    }

    private function taskSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeTask($data);

        return $this->validate($data, $rules);
    }

    public function searchBoards(Request $request)
    {
        $per_page = $request->getSafe('per_page', 'intval', 10);
        $search_input = $request->getSafe('searchInput', 'sanitize_text_field', '');
        $type = $request->getSafe('type', 'sanitize_text_field', 'to-do');

        $currentUserId = get_current_user_id();

        if (PermissionManager::isAdmin($currentUserId)) {
            $boards = Board::query()->where('type', $type)
                ->where('title', 'like', '%' . $search_input . '%')
                ->with('stages', 'tasks', 'users')
                ->paginate($per_page);

            foreach ($boards as $board) {
                $board->users = Helper::sanitizeUserCollections($board->users);
            }

        } else {
            $currentUser = User::find($currentUserId);
            $boards = $currentUser->boards()->where('type', $type)->where('title', 'like', '%' . $search_input . '%')->paginate($per_page);
        }

        return [
            'boards' => $boards,
        ];
    }

    public function getUsersOfBoards()
    {
        $userBoards = $this->boardService->getUsersOfBoards();

        return $this->sendSuccess([
            'userBoards' => $userBoards,
        ], 200);
    }



    /**
     * Refactor this code form me - Masiur
     * change stage settings is_public for roadmap user and admin view
     * @param $board_id
     * @param $stage_id
     * @return
     */
    public function changeStageView($board_id, $stage_id)
    {
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);

        try {
            $stage = $this->findStageOnBoard($stage_id, $board_id);
            $message = __('The stage is made public!', 'fluent-boards');
            $settings = $stage->settings;

            if (isset($settings['is_public'])) {
                if ($settings['is_public']) {
                    $settings['is_public'] = false;
                    $message = __('The stage is made private!', 'fluent-boards');
                } else {
                    $settings['is_public'] = true;
                }
            } else {
                $settings['is_public'] = true;
            }

            $stage->settings = $settings;
            $stage->save();
            return $this->sendSuccess([
                'message' => $message,
                'stage'   => $stage
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }


    /**
     * Set or reset board background image/color.
     * @param \FluentBoards\Framework\Http\Request\Request $request
     * @return
     */
    public function setBoardBackground(Request $request, $board_id)
    {
        $backgroundData = [];
        $isResetRequest = $request->getSafe('reset', 'rest_sanitize_boolean');

        if ($isResetRequest) {
            $backgroundData = [
                'reset' => true,
            ];
        } elseif ($request->image_url) {
            $backgroundData = $this->boardSanitizeAndValidate($request->all(), [
                'id'        => 'required|integer',
                'image_url' => 'required|string|url',
            ]);
        } elseif ($request->color) {
            $backgroundData = $this->boardSanitizeAndValidate($request->all(), [
                "id"    => 'required',
                'color' => 'required',
            ]);
        }

        try {
            if (!$board_id) {
                $errorMessage = __('Board id is required', 'fluent-boards');
                throw new \Exception(esc_html($errorMessage), 400);
            }

            if (empty($backgroundData)) {
                $errorMessage = __('Background data is required', 'fluent-boards');
                throw new \Exception(esc_html($errorMessage), 400);
            }

            return $this->sendSuccess([
                'message'    => __('Background updated successfully', 'fluent-boards'),
                'background' => $this->boardService->setBoardBackground($backgroundData, $board_id),
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }


    /**
     * Summary of getStageTaskAvailablePositions
     * @param mixed $board_id
     * @param mixed $stage_slug
     * @return $availablePositions as an array
     * @throws \Exception
     */
    public function getStageTaskAvailablePositions(Request $request, $board_id, $stage_id)
    {
        try {
            if ($board_id && $stage_id) {
                $taskId = $request->getSafe('task_id', 'intval');
                $availablePositions = $this->boardService->getStageTaskAvailablePositions($board_id, $stage_id, $taskId);
                return $this->sendSuccess([
                    'availablePositions' => $availablePositions['availablePositions'],
                    'moveTargets' => $availablePositions['moveTargets'],
                    'currentMoveTargetKey' => $availablePositions['currentMoveTargetKey'],
                    'defaultMoveTargetKey' => $availablePositions['defaultMoveTargetKey'],
                ], 200);
            } else {
                $message = '';
                if (!$board_id) {
                    $message = 'Board id ';
                }
                if (!$stage_id) {
                    $message = 'Stage ';
                }
                throw new \Exception(esc_html($message . 'is required'), 400);
            }
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getAssociateCrmContacts($board_id)
    {
        try {
            $contactAssociatedTasks = Task::with('board')->where('board_id', $board_id)
                ->whereNotNull('crm_contact_id')
                ->get();

            $tasksByContact = [];
            foreach ($contactAssociatedTasks as $task) {
                $tasksByContact[absint($task->crm_contact_id)][] = $task;
            }

            $boardContactIds = Meta::query()
                ->where('object_id', absint($board_id))
                ->where('object_type', Constant::OBJECT_TYPE_BOARD)
                ->whereIn('key', [
                    Constant::BOARD_ASSOCIATED_CRM_CONTACT,
                    self::LEGACY_BOARD_ASSOCIATED_CRM_CONTACT,
                ])
                ->pluck('value')
                ->toArray();
            $boardContactIds = array_values(array_unique(array_filter(array_map('absint', $boardContactIds))));

            $contactIds = array_values(array_unique(array_filter(array_map('absint', array_merge(
                array_keys($tasksByContact),
                $boardContactIds
            )))));

            usort($contactIds, function ($firstContactId, $secondContactId) use ($boardContactIds) {
                return (int) in_array($secondContactId, $boardContactIds, true) - (int) in_array($firstContactId, $boardContactIds, true);
            });

            $formattedContacts = Collection::make($contactIds)
                ->map(function ($contactId) use ($tasksByContact, $boardContactIds) {
                    $contact = Helper::crm_contact($contactId);
                    if (!$contact) {
                        return null; // Skip if subscriber not found
                    }

                    $tasks = $tasksByContact[$contactId] ?? [];
                    $contact['name'] = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: ($contact['full_name'] ?? $contact['email'] ?? '');
                    $contact['crm_contact_id'] = $contactId;
                    $contact['is_board_contact'] = in_array($contactId, $boardContactIds, true);
                    $contact['tasks'] = $tasks;

                    return $contact;
                })
                ->filter()->toArray();


            return $this->sendSuccess([
                'associatedContacts' => $formattedContacts
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updateAssociateCrmContact(Request $request, $board_id)
    {
        $value = $request->getSafe('value');
        $this->boardService->updateAssociateMember($value, $board_id);

        return $this->sendSuccess([
            'message' => __('Associated Crm Member has been updated', 'fluent-boards'),
        ], 200);
    }

    public function hasDataChanged(Request $request, $board_id)
    {
        $includeArchived = filter_var($request->get('include_archived', false), FILTER_VALIDATE_BOOLEAN);
        $since = $request->getSafe('since', 'sanitize_text_field');
        return $this->boardService->hasDataChanged($board_id, $includeArchived, $since);
    }

    public function createStage(Request $request, $board_id)
    {
        $stageData = $this->stageSanitizeAndValidate($request->all(), [
            'title' => 'required|string',
            'position' => 'nullable|numeric'
        ]);

        $board = Board::find($board_id);
        $stage = $this->stageService->createStage($stageData, $board_id);

        do_action('fluent_boards/board_stage_added', $board, $stage);

        $updatedStates = (new StageService())->getLastOneMinuteUpdatedStages($board_id);

        return [
            'updatedStages' => $updatedStates,
            'message'       => __('stage has been created', 'fluent-boards'),
        ];
    }

    public function moveAllTasks(Request $request, $board_id)
    {
        $oldStageId = $request->getSafe('oldStageId', 'intval');
        $newStageId = $request->getSafe('newStageId', 'intval');

        if (!$oldStageId || !$newStageId) {
            return $this->sendError(__('Invalid stage IDs provided', 'fluent-boards'), 400);
        }

        // Verify stages exist and belong to the board
        $oldStage = Stage::where('id', $oldStageId)->where('board_id', $board_id)->first();
        $newStage = Stage::where('id', $newStageId)->where('board_id', $board_id)->first();

        if (!$oldStage || !$newStage) {
            return $this->sendError(__('One or both stages do not exist or do not belong to this board', 'fluent-boards'), 400);
        }

        $updates = $this->stageService->moveAllTasks($oldStageId, $newStageId, $board_id);

        return [
            'message'      => __('Tasks have been moved', 'fluent-boards'),
            'updatedTasks' => $updates,
        ];

    }

    public function archiveAllTasksInStage($board_id, $stage_id)
    {
        $board_id = absint($board_id);
        $stage_id = absint($stage_id);

        try {
            $this->findStageOnBoard($stage_id, $board_id);
            $updates = $this->stageService->archiveAllTasksInStage($stage_id, $board_id);

            return [
                'message'      => __('Tasks have been archived', 'fluent-boards'),
                'updatedTasks' => $updates,
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getAssociatedBoards(Request $request, $associated_id)
    {
        if (!$this->currentUserCanReadCrmContacts()) {
            return $this->sendError(esc_html__('You do not have permission to view CRM contact boards', 'fluent-boards'), 403);
        }

        $associatedId = absint($associated_id);

        if (!$associatedId) {
            return $this->sendError(__('Invalid CRM contact', 'fluent-boards'), 400);
        }

        $associatedBoards = $this->boardService->getAssociatedBoards($associatedId, get_current_user_id());

        return [
            'boards' => $associatedBoards,
        ];
    }

    private function currentUserCanReadCrmContacts()
    {
        $permissionManager = 'FluentCrm\\App\\Services\\PermissionManager';

        if (!class_exists($permissionManager)) {
            return false;
        }

        return (bool) $permissionManager::currentUserCan('fcrm_read_contacts');
    }

    public function duplicateBoard(Request $request, $board_id)
    {
        $boardData = $this->taskSanitizeAndValidate($request->get('board'), [
            'title' => 'required|string'
        ]);

        $boardData['source_board_id'] = $board_id;

        $isWithLabels = $request->getSafe('isWithLabels');
        $isWithTasks = $request->getSafe('isWithTasks');
        $isWithTemplates = $request->getSafe('isWithTemplates');

        try {
            if(!PermissionManager::isAdmin()) {
                $errorMessage = __('You do not have permission to duplicate board', 'fluent-boards');
                throw new \Exception(esc_html($errorMessage), 400);
            }
            //create board
            $newBoard = $this->boardService->copyBoard($boardData);

            //label copy
            $labelMap = [];

            if ($isWithLabels == 'yes') {
                $labelMap = $this->labelService->copyLabelsOfBoard($board_id, $newBoard);
            }

            //stage copy
            $stageMapForCopyingTask = $this->stageService->copyStagesOfBoard($newBoard, $board_id, $isWithTemplates);

            //copy tasks of selected stages
            if ($isWithTasks == 'yes') {
                $this->taskService->copyTasks($board_id, $stageMapForCopyingTask, $newBoard, $labelMap,$isWithTemplates);
            }

            return $this->sendSuccess([
                'board' => $newBoard,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function importFromBoard(Request $request, $board_id)
    {
        $selectedStages = $request->getSafe('selectedStages');
        $position = $request->getSafe('position', 'intval');

        // Validate and sanitize selectedStages array
        if (!is_array($selectedStages)) {
            $selectedStages = [$selectedStages];
        }
        $selectedStages = array_filter(array_map('intval', $selectedStages));

        try {
            $this->stageService->importStagesFromBoard($board_id, $selectedStages, $position);

            return $this->sendSuccess([
                'message' => __('Import successfully', 'fluent-boards'),
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getBoardDefaultBackgroundColors()
    {
        return [
            'solidColors' => Constant::BOARD_BACKGROUND_DEFAULT_SOLID_COLORS,
            'gradients'   => Constant::BOARD_BACKGROUND_DEFAULT_GRADIENT_COLORS
        ];
    }

    /*
     * TODO: For Masiur - I will update this later
     */
    public function updateBoardProperties(Request $request, $board_id)
    {
        $pageId = $request->getSafe('page_id');
        $enable_stage_change_email = $request->getSafe('enable_stage_change_email');

        $board = Board::findOrFail($board_id);

        $board->updateMeta('roadmap_page_id', $pageId);
        $board->updateMeta('enable_stage_change_email', $enable_stage_change_email);

        $board = $board->fresh();

        return [
            'message' => __('Board has been updated', 'fluent-boards'),
            'board'   => apply_filters('fluent_boards/board_find', $board)
        ];
    }

    public function archiveBoard($board_id)
    {
        try {
            $board = $this->boardService->archiveBoard($board_id);

            return [
                'board' => $board,
                'message' => __('Board has been archived successfully!', 'fluent-boards')
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function restoreBoard($board_id)
    {
        try {
            $board = $this->boardService->restoreBoard($board_id);

            return [
                'board' => $board,
                'message' => __('Board has been restored successfully!', 'fluent-boards')
            ];
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    private function boardUserRole($boardRelation)
    {
        return $boardRelation && Arr::get($boardRelation->settings, 'is_admin')
            ? 'manager'
            : ($boardRelation && Arr::has($boardRelation->settings, 'is_viewer_only') && Arr::get($boardRelation->settings, 'is_viewer_only')
            ? 'viewer'
            : 'member');
    }
    public function uploadBoardBackground(Request $request,$board_id)
    {
        $file = Arr::get($request->files(), 'file')->toArray();
        (new \FluentBoards\App\Services\UploadService)->validateFile($file);

        $uploadInfo = UploadService::handleFileUpload( $request->files(), $board_id);

        $fileData = $uploadInfo[0];
        $initialDataData = [
            'type' => 'url',
            'url' => '',
            'name' => '',
            'size' => 0,
        ];

        $attachData = array_merge($initialDataData, $fileData);
        $UrlMeta = [];
        if($attachData['type'] == 'url') {
            $UrlMeta = RemoteUrlParser::parse($attachData['url']);
        }
        $uid = wp_generate_uuid4();
        $fileUploadedData = new Attachment();
        $fileUploadedData->object_id = $board_id;
        $fileUploadedData->object_type = Constant::BOARD_BACKGROUND_IMAGE;
        $fileUploadedData->attachment_type = $attachData['type'];
        $fileUploadedData->title = (new TaskService())->setTitle($attachData['type'], $attachData['name'], $UrlMeta);
        $fileUploadedData->file_path = $attachData['type'] != 'url' ?  $attachData['file'] : null;
        $fileUploadedData->full_url = esc_url($attachData['url']);
        $fileUploadedData->file_size = $attachData['size'];
        $fileUploadedData->settings = $attachData['type'] == 'url' ? [
            'meta' => $UrlMeta
        ] : '';
        $fileUploadedData->driver = 'local';
        $fileUploadedData->file_hash = md5($uid . wp_rand(0, 1000));
        $fileUploadedData->save();
        if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
            $mediaData = (new AttachmentService())->processMediaData($fileData, $file);
            $fileUploadedData['driver'] = $mediaData['driver'];
            $fileUploadedData['file_path'] = $mediaData['file_path'];
            $fileUploadedData['full_url'] = $mediaData['full_url'];
            $fileUploadedData->save();
        }

        $board = Board::find($board_id);
        $oldBackground = $board->background;
        $publicUrl = (new CommentService())->createPublicUrl($fileUploadedData, $board_id);
        $background = [
            'color' => null,
            'id' => $fileUploadedData->id,
            'image_url' => $publicUrl,
            'is_image' => true,
        ];
        $board->background = $background;
        $board->save();
        do_action('fluent_boards/board_background_updated', $board_id, $oldBackground);

        return $this->sendSuccess([
            'message'    => __('Background updated successfully', 'fluent-boards'),
            'background' => $board->background,
        ]);
    }

    public function getPinnedBoards()
    {
        $pinnedBoards = $this->boardService->getPinnedBoards();

        return $this->sendSuccess([
            'pinnedBoards' => $pinnedBoards,
        ], 200);
    }

    public function pinBoard($boardId)
    {
        $this->boardService->pinBoard($boardId);

        return $this->sendSuccess([
            'message' => __('The Board has been pinned', 'fluent-boards'),
        ], 200);
    }

    public function unpinBoard($boardId)
    {
        $remove = $this->boardService->unpinBoard($boardId);

        if (!$remove) {
            return $this->sendError([
                'message' => __('Board is not pinned', 'fluent-boards'),
            ], 400);
        }

        return $this->sendSuccess([
            'message' => __('Board is removed from pinned boards', 'fluent-boards'),
        ], 200);
    }

    public function getBoardFolder($board_id)
    {
        try {
            $folder = $this->boardService->getBoardFolder($board_id);
            return $this->sendSuccess([
                'folder' => $folder,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getBoardMenuItems($board_id)
    {
        try {
            $menuItems = (new BoardMenuHandler())->getMenuItems($board_id);
            
            return $this->sendSuccess([
                'menu_items' => $menuItems
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 500);
        }
    }

    public function getPublicAccessSettings($board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);

        $enabled = (bool) $board->getMetaByKey('public_access_enabled');
        $shortcode = $enabled ? '[fluent_board_public id="' . $board_id . '"]' : '';

        return $this->sendSuccess([
            'enabled'   => $enabled,
            'shortcode' => $shortcode,
        ], 200);
    }

    public function togglePublicAccess(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);

        $enabled = filter_var(
            $request->getSafe('enabled', 'sanitize_text_field', false),
            FILTER_VALIDATE_BOOLEAN
        );

        $board->updateMeta('public_access_enabled', $enabled ? '1' : '');

        $shortcode = $enabled ? '[fluent_board_public id="' . $board_id . '"]' : '';

        return $this->sendSuccess([
            'message'   => $enabled
                ? __('Public access has been enabled', 'fluent-boards')
                : __('Public access has been disabled', 'fluent-boards'),
            'enabled'   => $enabled,
            'shortcode' => $shortcode,
        ], 200);
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
