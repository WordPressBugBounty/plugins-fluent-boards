<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\InstallService;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\TaskService;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\UserService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Hooks\Handlers\BoardHandler;
use FluentBoards\App\Services\LabelService;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\Framework\Support\Collection;
use FluentCrm\App\Models\Subscriber;

class BoardController extends Controller
{
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
        $per_page = $request->getSafe('per_page', 'intval', 20);
        $userId = get_current_user_id();
        $type = $request->getSafe('type', 'sanitize_text_field', 'to-do');

        $order   = $request->getSafe('order', 'sanitize_text_field', 'created_at');
        $orderBy = $request->getSafe('orderBy', 'sanitize_text_field', 'DESC');
        $searchInput = $request->getSafe('searchInput', 'sanitize_text_field');

        $option = $request->getSafe('option', 'sanitize_text_field');

        if(!defined('FLUENT_ROADMAP'))
        {
            if($option == 'archived') {
                $relatedBoardsQuery = Board::whereNotNull('archived_at')->where('type', 'to-do')->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->where('type', 'to-do')->byAccessUser($userId);
            }
        } else {
            if($option == 'archived') {
                $relatedBoardsQuery = Board::whereNotNull('archived_at')->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->byAccessUser($userId);
            }
        }

        if (!empty($searchInput)) {
            $relatedBoardsQuery = $relatedBoardsQuery->where('title', 'like', '%' . $searchInput . '%');
        }

        $relatedBoards = $relatedBoardsQuery->orderBy($order, $orderBy)
                                            ->withCount('completedTasks')
                                            ->with('stages', 'users')
                                            ->paginate($per_page);

        foreach ($relatedBoards as $relatedBoard) {
            $relatedBoard->users = Helper::sanitizeUserCollections($relatedBoard->users);
        }

        return $this->sendSuccess([
            'boards' => $relatedBoards
        ], 200);
    }

    /**
     * Get the list of boards and their associated stages for the current user.
     *
     * @param \FluentBoards\Framework\Http\Request\Request $request
     * @return \WP_REST_Response
     */
    public function getBoardsList(Request $request)
    {
        try {
            $userId = get_current_user_id();

            // Query to fetch boards that are not archived and accessible by the user
            // Check if the FLUENT_ROADMAP constant is defined
            if (!defined('FLUENT_ROADMAP')) {
                $relatedBoardsQuery = Board::whereNull('archived_at')->where('type', 'to-do')->byAccessUser($userId);
            } else {
                $relatedBoardsQuery = Board::whereNull('archived_at')->byAccessUser($userId);
            }

            $relatedBoards = $relatedBoardsQuery->with('stages')->get();

            // Fetch the stages associated with the boards
            $stages = Stage::whereIn('board_id', $relatedBoards->pluck('id'))->where('archived_at', null)->get();

            return $this->sendSuccess([
                'boards' => $relatedBoards,
                'all_stages' => $stages,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getRecentBoards()
    {
        $boards = $this->boardService->getRecentBoards();

        if (!$boards || $boards->isEmpty()) {
            $boards = Board::where('type', 'to-do')->byAccessUser(get_current_user_id())
                ->limit(4)
                ->withCount('completedTasks')
                ->with(['stages', 'users'])
                ->get();
        }

        foreach ($boards as $board) {
            $board->users = Helper::sanitizeUserCollections($board->users);
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
        ], 200);
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

        $installFluentCRM = $request->get('withFluentCRM') == 'yes' ? true : false;

        $postStages = $request->get('stages');
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
        ]);

        try {
            $board = $this->boardService->createBoard($boardData);
            $this->labelService->createDefaultLabel($board->id);
            $type = ucfirst($boardData['type']);

            if (isset($boardData['type']) && $boardData['type'] == 'roadmap') {
                $this->stageService->createRoadmapStages($board, $request->get('stages'));
            } else {
                $this->stageService->createDefaultStages($board);
            }

            // if board is created from crm contact
            if (isset($boardData['crm_contact_id'])) {
                $this->boardService->updateAssociateMember($boardData['crm_contact_id'], $board->id);
            }

            do_action('fluent_boards/board_created', $board);

            $message = __('Board has been created successfully', 'fluent-boards');

            return $this->sendSuccess([
                'message' => $message,
                'board'   => $board,
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getArchivedStage(Request $request, $board_id)
    {
        try {
            $pagination = $request->noPagination ? true : false;
            $per_page = isset($data['per_page']) ? $data['per_page'] : 30;
            $page = isset($data['page']) ? $data['page'] : 1;
            if ($pagination) {
                $stages = Stage::where('board_id', $board_id)
                    ->whereNotNull('archived_at')
                    ->orderBy('created_at', 'DESC')
                    ->get();
            } else {
                $stages = Stage::where('board_id', $board_id)
                    ->whereNotNull('archived_at')
                    ->orderBy('created_at', 'DESC')
                    ->paginate($per_page, ['*'], 'page', $page);
            }

            return $this->sendSuccess([
                'stages' => $stages,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function find($board_id)
    {
        $board = Board::findOrFail($board_id);
        $board->background = maybe_unserialize($board->background);
        $board->createdOn = $board->created_at->format('Y-m-d');

        $board->load(['users', 'stages', 'labels', 'owner']);

        if (defined('FLUENT_BOARDS_PRO')){
            $board->load(['customFields']);
        }

        $this->boardService->updateRecentBoards($board_id);

        $board->labelColor = Constant::TRELLO_COLOR_MAP;
        $board->labelColorText = Constant::TEXT_COLOR_MAP;

        $board->users = Helper::sanitizeUserCollections($board->users);
        $board->owner = Helper::sanitizeUserCollections($board->owner);

        $board = apply_filters('fluent_boards/board_find', $board);

        return [
            'board' => $board
        ];
    }

    public function update(Request $request, $board_id)
    {
        $boardData = $this->boardSanitizeAndValidate($request->only(['title', 'description']), [
            'title'       => 'required|string',
            'description' => 'nullable|string',
        ]);

        $board = Board::findOrFail($board_id);

        $oldBoard = clone $board;
        $board->fill($boardData);
        $board->save();

        do_action('fluent_boards/board_updated', $board, $oldBoard);

        return [
            'stages'  => $board->stages()->get(),
            'message' => __('Board has been updated', 'fluent-boards'),
            'board'   => $board,
        ];
    }

    public function archiveStage($board_id, $stage_id)
    {
        try {
            $stage = Stage::findOrFail($stage_id);
            $board = Board::findOrFail($stage->board_id);

            $updatedStage = $this->boardService->archiveStage($board->id, $stage);

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
        try {
            $stage = Stage::findOrFail($stage_id);
            $board = Board::findOrFail($board_id);

            $updatedStage = $this->boardService->restoreStage($board->id, $stage);

            return $this->sendSuccess([
                'success'      => true,
                'updatedStage' => $updatedStage,
                'message'      => __('Stage has been restored', 'fluent-boards')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }


    public function changePositionOfStage(Request $request, $board_id)
    {
        $changeData = $this->boardSanitizeAndValidate($request->only(['fromPosition', 'toPosition']), [
            'fromPosition' => 'required',
            'toPosition'   => 'required',
        ]);

        try {
            $this->boardService->changePositionOfStage($board_id, $changeData);

            return $this->sendSuccess([
                'message' => __('Board stage has been updated', 'fluent-boards')
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function rePositionStages(Request $request, $board_id)
    {
        $incomingList = $request->get('list');
        try {
            $this->boardService->rePositionStages($board_id, $incomingList);
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
                throw new \Exception('You do not have permission to delete this board', 400);
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
            $activities = $this->boardService->getActivities($board_id, $request->all());
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
         * These are the rest of the admin users who are not in the board
         */
        $adminUserIds = Meta::query()->where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->whereNotIn('object_id', $userIds)
            ->get()
            ->pluck('object_id')
            ->toArray();

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
        $memberId = $request->getSafe('memberId');
        $isViewerOnly = $request->getSafe('isViewerOnly');
        $isAlreadyMember = $this->boardService->isAlreadyMember($board_id, $memberId);

        if ($isAlreadyMember) {
            return $this->sendError([
                'message' => __('User already a member', 'fluent-boards'),
            ], 304);
        }
        $member = $this->boardService->addMembersInBoard($board_id, $memberId, $isViewerOnly);

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
        $per_page = $request->get('per_page', 10);
        $search_input = $request->searchInput . trim('');
        $type = $request->type;

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
        try {
            $stage = Stage::findOrFail($stage_id);
            $message = __('The stage is made public!', 'fluent-boards');
            $settings = $stage->settings;

            if (isset($settings['is_public'])) {
                if ($settings['is_public']) {
                    $settings['is_public'] = false;
                    $message = __('The stage is made admin only!', 'fluent-boards');
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
     * Set board background image or color
     * @param \FluentBoards\Framework\Http\Request\Request $request
     * @return
     */
    public function setBoardBackground(Request $request, $board_id)
    {
        // sanitize and validate image_url
        if ($request->image_url) {
            $backgroundData = $this->boardSanitizeAndValidate($request->all(), [
                "id"        => 'required',
                'image_url' => 'required|string|url',
            ]);
        }

        // sanitize and validate color
        if ($request->color) {
            $backgroundData = $this->boardSanitizeAndValidate($request->all(), [
                "id"    => 'required',
                'color' => 'required',
            ]);
        }

        try {
            if (!$board_id) {
                $errorMessage = __('Board id is required', 'fluent-boards');
                throw new \Exception($errorMessage, 400);
            }

            return $this->sendSuccess([
                'message'    => __('Background updated successfully', 'fluent-boards'),
                'background' => $this->boardService->setBoardBackground($backgroundData, $board_id),
            ]);
        } catch (\Exception $e) {
            $this->sendError([$e->getMessage(), 400]);
        }
    }


    /**
     * Summary of getStageTaskAvailablePositions
     * @param mixed $board_id
     * @param mixed $stage_slug
     * @return $availablePositions as an array
     * @throws \Exception
     */
    public function getStageTaskAvailablePositions($board_id, $stage_id)
    {
        try {
            if ($board_id && $stage_id) {
                $availablePositions = $this->boardService->getStageTaskAvailablePositions($board_id, $stage_id);
                return $this->sendSuccess([
                    'availablePositions' => $availablePositions
                ], 200);
            } else {
                $message = '';
                if (!$board_id) {
                    $message = 'Board id ';
                }
                if (!$stage_id) {
                    $message = 'Stage ';
                }
                throw new \Exception($message . 'is required', 400);
            }
        } catch (\Exception $e) {
            $this->sendError([$e->getMessage(), 400]);
        }
    }

    public function getAssociateCrmContacts($board_id)
    {
        try {
            $contactAssociatedTasks = Task::with('board')->where('board_id', $board_id)
                ->whereNotNull('crm_contact_id')
                ->get();

            $formattedContacts = Collection::make($contactAssociatedTasks)
                ->groupBy('crm_contact_id')
                ->map(function ($tasks, $contactId) {
                    $subscriber = Subscriber::find($contactId);
                    if (!$subscriber) {
                        return null; // Skip if subscriber not found
                    }

                    return [
                        'name'           => $subscriber->first_name . ' ' . $subscriber->last_name,
                        'photo'          => $subscriber->photo,
                        'email'          => $subscriber->email,
                        'crm_contact_id' => $contactId,
                        'id'             => $contactId,
                        'tasks'          => $tasks,
                    ];
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

    public function hasDataChanged($board_id)
    {
        return $this->boardService->hasDataChanged($board_id);
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
        $oldStageId = $request->getSafe('oldStageId');
        $newStageId = $request->getSafe('newStageId');

        $updates = $this->stageService->moveAllTasks($oldStageId, $newStageId, $board_id);

        return [
            'message'      => __('Tasks has been Moved', 'fluent-boards'),
            'updatedTasks' => $updates,
        ];

    }

    public function archiveAllTasksInStage($board_id, $stage_id)
    {
        $updates = $this->stageService->archiveAllTasksInStage($stage_id);
        return [
            'message'      => __('Tasks has been archived', 'fluent-boards'),
            'updatedTasks' => $updates,
        ];
    }

    public function getAssociatedBoards(Request $request, $associated_id)
    {
        $associatedBoards = $this->boardService->getAssociatedBoards($associated_id);
        return [
            'boards' => $associatedBoards,
        ];
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
                throw new \Exception($errorMessage, 400);
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
        $position = $request->getSafe('position');

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

}
