<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Folder;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\App\Services\DescriptionMarkdownConverter;

class BoardService
{
    private const LEGACY_BOARD_ASSOCIATED_CRM_CONTACT = 'crm_contact';

    public function getBoardsByType($type)
    {
        return Board::where('type', sanitize_text_field($type))
            ->whereNull('archived_at')
            ->byAccessUser(get_current_user_id())
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    public function deleteBoard($boardId)
    {
        $board = Board::findOrFail($boardId);

        $options = null;
        //if we need to do something before a board is deleted
        do_action('fluent_boards/before_board_deleted', $board, $options);

        //related task delete, task related relations delete
        $allTaskIdsInBoard = $board->tasks->pluck('id');
        $taskRelatedRelations = Relation::whereIn('object_id', $allTaskIdsInBoard);
        $taskRelatedRelations->delete();
        TaskMeta::whereIn('task_id', $allTaskIdsInBoard)->delete();

        // Delete time tracking records for all tasks in the board
        (new TaskService())->deleteTimeTrackingRecords($allTaskIdsInBoard->toArray());

        Task::whereIn('id', $allTaskIdsInBoard)->delete();
        
        // delete all activities
        Activity::whereIn('object_id', $allTaskIdsInBoard)->where('object_type', Constant::ACTIVITY_TASK)->delete();
        $board->activities()->delete();

        //removing all Board Settings
        $board->boardUserEmailNotificationSettings()->detach();
        $board->boardUserNotificationSettings()->detach();
        //removing all Board users
        $board->users()->detach();

        //removing add board stages
        $board->stages()->delete();

        //removing add board labels
        $board->labels()->delete();

        //removing all board comments (delete individually to fire model events and clean up images)
        $comments = $board->comments()->get();
        foreach ($comments as $comment) {
            $comment->delete();
        }
      
        //removing add board custom fields
        if (defined('FLUENT_BOARDS_PRO')) {
            $board->customFields()->delete();
        }


        foreach ($board->notifications as $notification) {
            $notification->users()->detach();
        }
        $board->notifications()->delete();
        $board->removeBoardFromFolder();

        //delete board related meta
        $this->deleteBoardMeta($boardId);

        //delete from recently viewed
        $this->deleteFromRecentlyViewed($boardId);

        //delete webhook data
        $this->deleteWebhookData($boardId);
        
        $board->delete();
        FileSystem::deleteDir('board_'.$boardId);
    }

    public function fetchBoardMeta($boardId)
    {
        $boardMeta = Meta::where('object_id', $boardId)
            ->where('object_type', 'board')
            ->where('key', 'is_auth_require')
            ->orderBy('id', 'desc')->first();

        if ($boardMeta) {
            $boardMeta->value = maybe_unserialize($boardMeta->value);
            return $boardMeta;
        } else {
            $meta = new Meta();
            $settingData = array(
                'is_auth_require_idea_submit'                        => '',
                'is_auth_require_voting_commenting'                  => '',
                'is_auth_require_reaction'                           => '',
                'is_allow_email_along_with_auth'                     => '',
                'is_allow_unauthentication_reaction_along_with_auth' => ''
            );
            $meta->object_id = $boardId;
            $meta->object_type = 'board';
            $meta->key = 'is_auth_require';
            $meta->value = \maybe_serialize($settingData);
            $meta->save();
            $meta->value = $settingData;
            return $meta;
        }
    }

    public function modifyAuthenticationPermission($data, $boardId)
    {
        $boardMeta = Meta::where('object_id', $boardId)
            ->where('object_type', 'board')
            ->where('key', 'is_auth_require')
            ->orderBy('id', 'desc')->first();

        if ($boardMeta) {
            $settings = array(
                'is_auth_require_idea_submit'                        => $data['is_auth_require_idea_submit'],
                'is_auth_require_voting_commenting'                  => $data['is_auth_require_voting_commenting'],
                'is_auth_require_reaction'                           => $data['is_auth_require_reaction'],
                'is_allow_email_along_with_auth'                     => $data['is_allow_email_along_with_auth'],
                'is_allow_unauthentication_reaction_along_with_auth' => $data['is_allow_unauthentication_reaction_along_with_auth']
            );
            $boardMeta->value = \maybe_serialize($settings);
            $boardMeta->save();
        }
        return $boardMeta;
    }

    public function createBoard($boardData)
    {
        $boardData = [
            'title'       => $boardData['title'],
            'type'        => $boardData['type'] ? $boardData['type'] : 'to-do',
            'description' => DescriptionMarkdownConverter::normalize($boardData['description'] ?? ''),
            'currency'    => isset($boardData['currency']) ? $boardData['currency'] : 'USD',
            'background'  => isset($boardData['background']) ? $boardData['background'] : '',
            'created_by'  => isset($boardData['created_by']) ? $boardData['created_by'] : get_current_user_id()
        ];

        $boardData = apply_filters('fluent_boards/before_create_board', $boardData);

        $board = Board::create($boardData);

        $this->setCurrentUserPreferencesOnBoardCreate($board);

        return $board;
    }

    /**
     * Attach a user-owned board to its creator with Board Admin preferences.
     *
     * @param Board $board
     * @return void
     */
    public function setCurrentUserPreferencesOnBoardCreate($board)
    {
        $creatorId = absint($board->created_by);
        if (!$creatorId) {
            return;
        }

        $board->users()->attach(
            $creatorId,
            [
                'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                'settings'    => maybe_serialize([
                    Constant::IS_BOARD_ADMIN => true
                ]),
                'preferences' => maybe_serialize(Constant::BOARD_NOTIFICATION_TYPES)
            ]
        );
    }

    public function removeUserFromBoard($boardId, $userId)
    {

        $board = Board::findOrFail($boardId);
        $user = User::findOrFail($userId);

        $board->users()->detach($userId);
        $board->boardUserNotificationSettings()->detach($userId); //removing notification settings of user in that board
        $board->boardUserEmailNotificationSettings()->detach($userId); //removing email notification settings of user in that board

        //detacing all tasks of this board from user
        $taskIdsToDetach = $user->tasks()->where('board_id', $boardId)->get()->pluck('id');

        $user->tasks()->detach($taskIdsToDetach);
        $user->watchingTasks()->detach($taskIdsToDetach);

    }

    private function removeFromDefaultAssignee($boardId, $user)
    {
        $stages = Stage::where('board_id', $boardId)->get();
        foreach ($stages as $stage) {
            if (isset($stage->settings['default_task_assignees'])) {
                if (($key = array_search($user, $stage->settings['default_task_assignees'])) !== false) {
                    unset($stage->settings['default_task_assignees'][$key]);
                }
            }
        }
    }

    public function removeFromRecentlyOpened($boardId, $userId)
    {
        $recentlyOpened = Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_RECENT_BOARDS)
            ->first();
        if ($recentlyOpened) {
            $recentBoardIds = $recentlyOpened->value;

            // Recently opened meta can be empty or legacy-shaped; only splice a usable board ID list.
            if (!is_array($recentBoardIds)) {
                return;
            }

            $index = array_search($boardId, $recentBoardIds);
            if ($index === false) {
                return;
            }

            array_splice($recentBoardIds, $index, 1);

            $recentlyOpened->value = $recentBoardIds;
            $recentlyOpened->save();
        }

    }

    public function updateBoard($board, $data)
    {
        if ($data['title']) {
            $data['title'] = $data['title'];
        } else {
            throw new \Exception(esc_html__('Title cannot be empty', 'fluent-boards'));
        }
        if (isset($data['description'])) {
            $data['description'] = DescriptionMarkdownConverter::normalize($data['description']);
        }
        $board->fill($data);
        $board->save();
        //        do_action('fluent_boards/board_updated', $board);
        return $board;
    }

    public function defaultStages()
    {
        $stages = [
            (object)[
                'group' => 'open',
                'label' => 'Open',
            ],
            (object)[
                'group' => 'in_progress',
                'label' => 'In Progress',
            ],
            (object)[
                'group' => 'completed',
                'label' => 'Completed',
            ],
        ];

        return serialize($this->processStages($stages));
    }


    public function repositionStages($boardId, $incomingList)
    {
        $oldList = Stage::where('board_id', $boardId)->where('type', 'stage')->whereNull('archived_at')->orderBy('position')->pluck('id');

        foreach ($incomingList as $key => $stage_id) {
            $stage = Stage::findOrFail($stage_id);
            $stage->moveToNewPosition($key + 1);
        }
        do_action('fluent_boards/board_stages_reordered', $boardId, $oldList);
    }

    public function processStages($stages)
    {
        $processedStages = [];
        foreach ($stages as $stage) {
            if (is_object($stage)) {
                $processedStages[] = (object)[
                    'group' => Helper::snake_case($stage->slug),
                    'label' => sanitize_text_field($stage->label)
                ];
            } else {
                $processedStages[] = (object)[
                    'group' => Helper::snake_case($stage['group']),
                    'label' => sanitize_text_field($stage['label'])
                ];
            }
        }
        return $processedStages;
    }

    /**
     * Archive a stage and persist the user who archived it for future archive-list metadata.
     */
    public function archiveStage($boardId, $stage)
    {
        $settings = $stage->settings ?: [];
        $settings['archived_by_id'] = absint(get_current_user_id()) ?: null;

        $stage->archived_at = current_time('mysql');
        $stage->position = 0;
        $stage->settings = $settings;
        $stage->save();

        do_action('fluent_boards/stage_archived', $boardId, $stage); // Old hook
        do_action('fluent_boards/stage_archived_with_tasks', $boardId, $stage); // New hook
        return $stage;
    }

    /**
     * Restore an archived stage and clear stale archived-by metadata.
     */
    public function restoreStage($boardId, $stage)
    {
        $stageService = new StageService();
        $lastStagePosition = $stageService->getLastPositionOfStagesOfBoard($stage->board_id);
        $settings = $stage->settings ?: [];
        $settings['archived_by_id'] = null;

        $stage->archived_at = null;
        $stage->position = $lastStagePosition ? $lastStagePosition->position + 1 : 1;
        $stage->settings = $settings;
        $stage->save();
        do_action('fluent_boards/board_stage_restored', $boardId, $stage->title); // Old hook
        do_action('fluent_boards/stage_restored_with_tasks', $boardId, $stage); // New hook
        return $stage;
    }

    public function getActivities($id, $data)
    {
        $per_page = isset($data['per_page']) ? $data['per_page'] : 40;
        $page = isset($data['page']) ? $data['page'] : 1;
        $activities = Activity::where('object_id', $id)->where('object_type', Constant::ACTIVITY_BOARD)->with(['user'])
            ->orderBy('id', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);

        Helper::translateActivities($activities);

        return $activities;
    }

    public function isAlreadyMember($boardId, $memberId)
    {
        $isAlreadyMember = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $memberId)->first();

        return $isAlreadyMember ?? false;
    }

    /**
     * Add a WordPress user to a board.
     *
     * @return User|false|null User on success, false for an existing relation,
     *                         or null when the board/user does not exist.
     */
    public function addMembersInBoard($boardId, $memberId, $isViewerOnly = null)
    {
        $boardId = intval($boardId);
        $memberId = intval($memberId);
        $isViewerOnly = sanitize_text_field((string)$isViewerOnly);

        if ($boardId <= 0 || $memberId <= 0) {
            return null;
        }

        $board = Board::find($boardId);
        $boardMember = User::find($memberId);

        if (!$board || !$boardMember) {
            return null;
        }
        $isAlreadyMember = $this->isAlreadyMember($boardId, $memberId);
        if($isAlreadyMember) {
            return false;
        }
        $settings = Constant::BOARD_USER_SETTINGS;

        if($isViewerOnly === 'yes') {
            $settings = Constant::BOARD_USER_VIEWER_ONLY_SETTINGS;
        }



        $board->users()->attach(
            $memberId,
            [
                'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                'settings'    => maybe_serialize($settings),
                'preferences' => maybe_serialize(Constant::BOARD_NOTIFICATION_TYPES)
            ]
        );
        if(!$isViewerOnly) {
            do_action('fluent_boards/board_member_added', $boardId, $boardMember);
        } else {
            do_action('fluent_boards/board_viewer_added', $boardId, $boardMember);
        }
        return $boardMember;
    }

    public function makeAdminOfBoard($boardId, $userId)
    {
        $boardUser = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $userId)->first();
        $boardUser->settings = [
            'is_admin' => true
        ];
        $boardUser->save();

        $user = User::findOrFail($userId);
        do_action('fluent_boards/board_admin_added', $boardId, $userId);
        $user['is_admin'] = true;
        $user['is_board_admin'] = true;
        return $user;
    }

    public function removeAdminFromBoard($boardId, $userId)
    {
        $boardUser = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $userId)->first();

        $boardUser->settings = [
            'is_admin' => false
        ];

        $boardUser->save();
        $user = User::findOrFail($userId);
        do_action('fluent_boards/board_admin_removed', $boardId, $userId);
        $user['is_admin'] = false;
        $user['is_board_admin'] = false;
        return $user;
    }

    /**
     * Create or update a board access relation with the selected member role.
     */
    public function syncBoardUserRole($boardId, $userId, $role)
    {
        $boardId = absint($boardId);
        $userId = absint($userId);
        $role = sanitize_text_field($role);

        if (!$boardId || !$userId || !in_array($role, ['admin', 'member', 'viewer'], true)) {
            return false;
        }

        $board = Board::find($boardId);
        $user = User::find($userId);

        if (!$board || !$user) {
            return false;
        }

        $boardUser = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $userId)
            ->first();

        $previousSettings = $boardUser ? (array)$boardUser->settings : [];

        // Board roles live as flags on the board_user relation; member access means both flags stay false.
        $settings = [
            'is_admin'       => 'admin' === $role,
            'is_viewer_only' => 'viewer' === $role,
        ];

        if ($boardUser) {
            $boardUser->settings = $settings;
            $boardUser->save();
        } else {
            // New access should get the same default notification preferences as the normal add-member flow.
            $board->users()->attach(
                $userId,
                [
                    'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                    'settings'    => maybe_serialize($settings),
                    'preferences' => maybe_serialize(Constant::BOARD_NOTIFICATION_TYPES)
                ]
            );
        }

        // Only emit admin transition hooks when the role actually changes.
        if ('admin' === $role && empty($previousSettings['is_admin'])) {
            do_action('fluent_boards/board_admin_added', $boardId, $userId);
        } elseif (!empty($previousSettings['is_admin'])) {
            do_action('fluent_boards/board_admin_removed', $boardId, $userId);
        }

        if ('viewer' === $role) {
            do_action('fluent_boards/board_viewer_added', $boardId, $user);
        } elseif ('member' === $role) {
            do_action('fluent_boards/board_member_added', $boardId, $user);
        }

        $user['is_admin'] = 'admin' === $role;
        $user['is_board_admin'] = 'admin' === $role;

        return $user;
    }

    public function getUsersOfBoards()
    {
        $userBoards = Relation::whereNotNull('board_id')
            ->where('user_id', get_current_user_id())
            ->where('status', 'ACTIVE')->get();

        return $userBoards;
    }

    /**
     * Change or clear the board background.
     *
     * Image attachments must belong to the target board and use the board
     * background attachment type before their identifiers can be persisted.
     *
     * @param array $backgroundData
     * @param int   $board_id
     * @return array|string
     * @throws \Exception
     */
    public function setBoardBackground($backgroundData, $board_id)
    {
        $boardId = absint($board_id);
        $board = Board::find($boardId);

        if (!$board) {
            throw new \Exception(esc_html__('Board not found.', 'fluent-boards'));
        }

        $oldBackground = $board->background;

        if (!empty($backgroundData['reset'])) {
            $board->background = '';
            $board->save();
            do_action('fluent_boards/board_background_updated', $boardId, $oldBackground);

            return $board->background;
        }

        $background = $board->background;
        if (!is_array($background)) {
            $background = [];
        }

        // Resolve image metadata from the board-owned attachment, never from the client URL.
        if (isset($backgroundData['image_url'])) {
            $attachmentId = absint($backgroundData['id'] ?? 0);
            $attachment = Attachment::where('id', $attachmentId)
                ->where('object_id', $boardId)
                ->where('object_type', Constant::BOARD_BACKGROUND_IMAGE)
                ->first();

            if (!$attachment) {
                throw new \Exception(esc_html__('Background image not found.', 'fluent-boards'));
            }

            $background['id'] = (int) $attachment->id;
            $background['image_url'] = (new CommentService())->createPublicUrl($attachment, $boardId);
            $background['is_image'] = true;
            $background['color'] = null;
        } elseif (isset($backgroundData['color'])) {
            $background['id'] = $backgroundData['id'];
            $background['color'] = $backgroundData['color'];
            $background['image_url'] = null;
            $background['is_image'] = false;
        }

        $board->background = $background;
        $board->save();
        do_action('fluent_boards/board_background_updated', $boardId, $oldBackground);

        return $board->background;
    }


    /**
     * Summary of getStageTaskAvailablePositions
     * @param mixed $board_id
     * @param mixed $stage_slug
     * @return array of available positions of the stage with one increased value because if the stage has 10  tasks than it will have 10 position and +1 as last position of the stage
     */
    public function getStageTaskAvailablePositions($board_id, $stage_id, $task_id = null)
    {
        $task_id = absint($task_id);
        $task = $task_id ? Task::find($task_id) : null;
        $isCurrentStage = $task
            && (int) $task->board_id === (int) $board_id
            && (int) $task->stage_id === (int) $stage_id;

        $stageTasks = Task::query()
            ->where('board_id', $board_id)
            ->where('parent_id', null)
            ->where('stage_id', $stage_id)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get(['id', 'position']);

        if ($isCurrentStage) {
            $stageTasks = $stageTasks->filter(function ($stageTask) use ($task_id) {
                return (int) $stageTask->id !== $task_id;
            })->values();
        }

        $availablePositions = [];
        $moveTargets = [];
        $currentMoveTargetKey = null;
        $totalSlots = $stageTasks->count() + 1;
        $currentSlot = $this->getCurrentStageSlotIndex($task, $stageTasks, $isCurrentStage);

        for ($slotIndex = 0; $slotIndex < $totalSlots; $slotIndex++) {
            $slotNumber = $slotIndex + 1;
            // Each slot represents a drop target between two ordered tasks, so the
            // modal can send exact neighbour ids instead of a fragile display index.
            $prevTask = $slotIndex > 0 ? $stageTasks->get($slotIndex - 1) : null;
            $nextTask = $slotIndex < $stageTasks->count() ? $stageTasks->get($slotIndex) : null;
            $slotKey = 'slot_' . $slotNumber;

            $availablePositions[] = $slotNumber;
            $moveTargets[] = [
                'key' => $slotKey,
                'label' => $slotNumber,
                'prevTaskId' => $prevTask ? (int) $prevTask->id : null,
                'nextTaskId' => $nextTask ? (int) $nextTask->id : null,
                'isCurrent' => $isCurrentStage && $currentSlot === $slotNumber,
            ];

            if ($isCurrentStage && $currentSlot === $slotNumber) {
                $currentMoveTargetKey = $slotKey;
            }
        }

        return [
            'availablePositions' => $availablePositions,
            'moveTargets' => $moveTargets,
            'currentMoveTargetKey' => $currentMoveTargetKey,
            'defaultMoveTargetKey' => 'slot_' . $totalSlots,
        ];
    }

    private function getCurrentStageSlotIndex($task, $stageTasks, $isCurrentStage)
    {
        if (!$isCurrentStage || !$task) {
            return null;
        }

        $slotNumber = 1;
        foreach ($stageTasks as $stageTask) {
            if ((float) $task->position > (float) $stageTask->position) {
                $slotNumber++;
                continue;
            }

            break;
        }

        return $slotNumber;
    }

    public function getAssigneesByBoard($board_id, $search = '')
    {
        $assignees = [];
        $boardUsers = [];
        $board = Board::with('users')->find($board_id);

        if ($board) {
            if ($search) {
                $boardUsers = $board->users->filter(
                    function ($user) use ($search) {
                        return strpos($user->display_name, $search) !== false || strpos($user->user_email, $search) !== false;
                    }
                );
            } else {
                $boardUsers = $board->users;
            }
        };
        foreach ($boardUsers as $user) {
            $taskAssignee = Relation::where('foreign_id', $user->ID)->where('object_type', 'task_assignee')->exists();
            if ($taskAssignee) {
                $assignees[] = $user;
            }
        }
        return $assignees;
    }

    private function deleteFromRecentlyViewed($boardId)
    {
        $recentlyOpened = $this->recentlyViewedByUserQuery()->first();
        if ($recentlyOpened) {
            $recentBoardIds = $recentlyOpened->value;
            if (in_array($boardId, $recentBoardIds)) {
                $index = array_search($boardId, $recentBoardIds);
                unset($recentBoardIds[$index]);
                $recentlyOpened->value = $recentBoardIds;
                $recentlyOpened->save();
            }
        }
    }

    public function updateRecentBoards($boardId)
    {
        $userId = get_current_user_id();
        $recentlyOpened = $this->recentlyViewedByUserQuery($userId)->first();
        if (!$recentlyOpened) {
            $openedBoards = [$boardId];
            $userMeta = new Meta();
            $userMeta->object_id = $userId;
            $userMeta->object_type = Constant::OBJECT_TYPE_USER;
            $userMeta->key = Constant::USER_RECENT_BOARDS;
            $userMeta->value = $openedBoards;
            $userMeta->save();
        } else {
            $recentBoardIds = $recentlyOpened->value;
            // Ensure the value is an array
            if (!is_array($recentBoardIds)) {
                $recentBoardIds = [];
            }

            // Check if the board is already in the list
            if (!in_array($boardId, $recentBoardIds)) {
                // Keep the 4 most recently opened boards for the dashboard view.
                if (count($recentBoardIds) >= 4) {
                    array_pop($recentBoardIds);
                }
            } else {
                // Remove the existing board id to move it to the front
                $index = array_search($boardId, $recentBoardIds);
                unset($recentBoardIds[$index]);
            }
            // Add the board to the beginning of the list
            array_unshift($recentBoardIds, $boardId);

            // Update the meta value and save it
            $recentlyOpened->value = $recentBoardIds;
            $recentlyOpened->save();
        }
    }

    public function recentlyViewedByUserQuery($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        return Meta::query()->where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_RECENT_BOARDS);
    }

    public function getRecentBoards()
    {
        $userId = get_current_user_id();

        $recentBoardIds = $this->recentlyViewedByUserQuery($userId)->value('value');

        if (!$recentBoardIds) {
            return [];
        }

        if (!is_array($recentBoardIds)) {
            $recentBoardIds = [];
        }

        $currentUser = User::find($userId);

        if (!PermissionManager::isAdmin($userId)){
            $recentBoardIds = array_intersect($recentBoardIds, $currentUser->whichBoards->pluck('id')->toArray());
        }

        $recentBoardIds = array_values(array_slice($recentBoardIds, 0, 4));

        // This is for checking if that board is exists
        // TODO: we will remove this code in future version
        if (!$this->recentBoardBackwardCompatibilityCheck()) {
            foreach ($recentBoardIds as $index => $boardId) {
                $board = Board::find($boardId);
                if (!$board) {
                    $this->deleteFromRecentlyViewed($boardId);
                    unset($recentBoardIds[$index]);
                }
            }

            $this->updateRecentBoardCheckMeta();
        }

        return Board::whereIn('id', $recentBoardIds)
            ->whereNull('archived_at')
            ->excludeTemplates()
            ->availableInCurrentInstall()
            ->withCount('completedTasks')
            ->with(['stages', 'users'])
            ->get();
    }

    public function getRecentBoardCheckMeta($userId = null){
        if (!$userId) {
            $userId = get_current_user_id();
        }

         return Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::FBS_RECENTLY_VIEWED_CHECK)
            ->first();
    }

    private function recentBoardBackwardCompatibilityCheck() {
        $userId = get_current_user_id();

        $checkedMeta = $this->getRecentBoardCheckMeta($userId);

        if (!$checkedMeta) {
            $recentBoardCheck = new Meta();
            $recentBoardCheck->object_id = $userId;
            $recentBoardCheck->object_type = Constant::OBJECT_TYPE_USER;
            $recentBoardCheck->key = Constant::FBS_RECENTLY_VIEWED_CHECK;
            $recentBoardCheck->value = 'no';
            $recentBoardCheck->save();

            return false;
        } else {
            if ($checkedMeta->value == 'yes') {
                return true;
            } else {
                return false;
            }
        }
    }

    private function updateRecentBoardCheckMeta()
    {
        $checkedMeta = $this->getRecentBoardCheckMeta();

        if ($checkedMeta) {
            $checkedMeta->value = 'yes';
            $checkedMeta->save();
        }
    }

    public function updateAssociateMember($contactId, $boardId)
    {
        $contactOfBoard = $this->getAssociateMember($boardId, true);

        if ($contactOfBoard) {
            $contactOfBoard->value = $contactId;
            $contactOfBoard->save();
        } else {
            $contactOfBoard = new Meta();
            $contactOfBoard->object_id = $boardId;
            $contactOfBoard->object_type = Constant::OBJECT_TYPE_BOARD;
            $contactOfBoard->key = Constant::BOARD_ASSOCIATED_CRM_CONTACT;
            $contactOfBoard->value = $contactId;
            $contactOfBoard->save();
        }

        $board = Board::findOrFail($boardId);
        do_action('fluent_boards/contact_added_to_board', $board, $contactId);

    }

    public function getAssociateMember($boardId, $fromUpdateMethod = false)
    {

        $contactOfBoard = Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_ASSOCIATED_CRM_CONTACT)
            ->first();

        if ($fromUpdateMethod) {
            return $contactOfBoard;
        }

        if (!$contactOfBoard) {
            return null;
        }

        return Helper::crm_contact($contactOfBoard->value);

//        return \FluentCrm\App\Models\Subscriber::find($contactOfBoard->value);
    }

    public function deleteAssociateMember($boardId, $contact_id)
    {
        $contactOfBoard = Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_ASSOCIATED_CRM_CONTACT)
            ->where('value', $contact_id)
            ->first();

        $contactOfBoard->delete();
    }

    public function sendInvitationToBoard($boardId, $email, $role = 'member')
    {
        $role = sanitize_text_field($role);
        if (!in_array($role, ['manager', 'member', 'viewer'], true)) {
            $role = 'member';
        }

        $user = User::query()->where('user_email', $email)->first();

        if ($user) {
            return $user;
        }

        $current_user_id = get_current_user_id();

        do_action('fluent_boards/send_invitation', $boardId, $email, $current_user_id, $role);

        return;

    }

    public function getInvitations($boardId)
    {
        return Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_INVITATION)
            ->get();
    }

    /**
     * Delete an invitation only when it belongs to the supplied board.
     *
     * The optional second argument lets older Pro releases receive a controlled
     * error instead of reporting a successful deletion that never happened.
     */
    public function deleteInvitation($boardId, $invitationId = null)
    {
        if ($invitationId === null) {
            throw new \Exception(
                __('A board ID is required to delete an invitation.', 'fluent-boards')
            );
        }

        $boardId = intval($boardId);
        $invitationId = intval($invitationId);

        if ($boardId <= 0 || $invitationId <= 0) {
            return false;
        }

        return (bool) Meta::query()
            ->where('id', $invitationId)
            ->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_INVITATION)
            ->delete();
    }

    public function hasDataChanged($boardId, $includeArchived = false, $since = null)
    {
        $stages = [];
        $labels = [];
        $tasks = [];
        $syncStartedAt = current_time('mysql');
        $isCursorRequest = !empty($since);
        $forceFullSync = false;

        if ($isCursorRequest) {
            $lastUpdated = $this->normalizeSyncCursor($since, $syncStartedAt);
            $forceFullSync = !$lastUpdated;
        } else {
            $oneMinuteAgoTimestamp = current_time('timestamp') - 60;
            $lastUpdated = date_i18n('Y-m-d H:i:s', $oneMinuteAgoTimestamp);
        }

        $board = Board::find($boardId);
        if (!$board) {
            throw new \Exception(esc_html__("Board doesn't exists", 'fluent-boards'));
        }
        $boardUpdatedAt = $this->formatSyncTimestamp($board->updated_at);
        $boardChanged = !$isCursorRequest || $forceFullSync || $boardUpdatedAt >= $lastUpdated;

        // Reset the local list if a change can remove an item from the user's current view.
        $stageActivityQuery = Activity::where('object_id', $boardId)
            ->where('object_type', Constant::ACTIVITY_BOARD)
            ->where('updated_at', '>=', $lastUpdated)
            ->where('column', 'stage');

        $stageResetRequired = $forceFullSync || (clone $stageActivityQuery)
            ->whereIn('action', ['deleted', 'archived', 'restored'])
            ->exists();

        if ($stageResetRequired) {
            $stagesQuery = Stage::where('board_id', $boardId)->orderBy('position', 'asc');
            if (!$includeArchived) {
                $stagesQuery->whereNull('archived_at');
            }
            $stages = $stagesQuery->get();
        } else {
            $stages = (new StageService())->getLastOneMinuteUpdatedStages($boardId, $lastUpdated, $includeArchived);
        }

        $labelResetRequired = $forceFullSync || Activity::where('object_id', $boardId)
            ->where('object_type', Constant::ACTIVITY_BOARD)
            ->where('updated_at', '>=', $lastUpdated)
            ->where('action', 'deleted')
            ->where('column', 'label')
            ->exists();
        if ($labelResetRequired) {
            $labelsQuery = Label::where('board_id', $boardId)->orderBy('position', 'asc');
            if (!$includeArchived) {
                $labelsQuery->whereNull('archived_at');
            }
            $labels = $labelsQuery->get();
        } else {
            $labels = (new LabelService())->getLastOneMinuteUpdatedLabels($boardId, $lastUpdated, $includeArchived);
        }

        $stageArchiveRestored = !$forceFullSync && (clone $stageActivityQuery)
            ->whereIn('action', ['archived', 'restored'])
            ->exists();

        $taskResetRequired = $forceFullSync || $stageArchiveRestored || Activity::where('object_id', $boardId)
            ->where('object_type', Constant::ACTIVITY_BOARD)
            ->where('updated_at', '>=', $lastUpdated)
            ->where(function($query) {
                $query->where('action', 'deleted')
                    ->orWhere('action', 'moved')
                    ->orWhere('action', 'archived')
                    ->orWhere('action', 'restored');
            })
            ->where('column', 'task')
            ->exists();
        if ($taskResetRequired) {
            $tasksQuery = Task::query()
                ->where([
                    'board_id'    => $boardId,
                    'parent_id'   => null,
                ])
                ->with(['assignees', 'labels', 'watchers']);

            if (!$includeArchived) {
                $tasksQuery->whereNull('archived_at');
            }

            if (!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                $tasksQuery->with('customFields');
            }

            $tasks = $tasksQuery->orderBy('due_at', 'ASC')->get();
        } else {
            $tasks = (new TaskService())->getLastOneMinuteUpdatedTasks($boardId, $lastUpdated, $includeArchived);
        }

        foreach ($tasks as $task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->is_watching = $task->isWatching();
            $task->contact = Helper::crm_contact($task->crm_contact_id);
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
            $task->watchers = Helper::sanitizeUserCollections($task->watchers);
        }

        $board->background = \maybe_unserialize($board->background);
        if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
            $board->custom_fields = $board->customFields;
        }

        $boardPayload = $boardChanged ? $board : (object) [];
        $hasChanges = $boardChanged
            || $stageResetRequired
            || $labelResetRequired
            || $taskResetRequired
            || count($stages)
            || count($labels)
            || count($tasks);

        return [
            'board'              => $boardPayload,
            'stages'             => $stages,
            'labels'             => $labels,
            'tasks'              => $tasks,
            'taskDeleted'        => $taskResetRequired,
            'stageDeleted'       => $stageResetRequired,
            'labelDeleted'       => $labelResetRequired,
            'taskResetRequired'  => $taskResetRequired,
            'stageResetRequired' => $stageResetRequired,
            'labelResetRequired' => $labelResetRequired,
            'has_changes'        => (bool) $hasChanges,
            'synced_at'          => $syncStartedAt,
            'sync_reset'         => $forceFullSync,
        ];
    }

    private function normalizeSyncCursor($since, $syncStartedAt)
    {
        if (!is_string($since)) {
            return null;
        }

        $since = trim($since);
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
            return null;
        }

        if ($since > $syncStartedAt) {
            return null;
        }

        if (strtotime($since) < strtotime('-24 hours', strtotime($syncStartedAt))) {
            return null;
        }

        return $since;
    }

    private function formatSyncTimestamp($timestamp)
    {
        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->format('Y-m-d H:i:s');
        }

        return (string) $timestamp;
    }

    /**
     * Get CRM-associated boards that the current user can access.
     *
     * @param int $associatedId CRM contact/subscriber id.
     * @param int|null $userId WordPress user id used for board access checks.
     * @return \FluentBoards\Framework\Database\Orm\Collection|array
     */
    public function getAssociatedBoards($associatedId, $userId = null)
    {
        $associatedId = absint($associatedId);
        $userId = $userId ?: get_current_user_id();

        if (!$associatedId || !$userId) {
            return [];
        }

        $boardIds = Meta::query()->where('value', $associatedId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->whereIn('key', [
                Constant::BOARD_ASSOCIATED_CRM_CONTACT,
                self::LEGACY_BOARD_ASSOCIATED_CRM_CONTACT,
            ])
            ->pluck('object_id');

        $boards = Board::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $boardIds->toArray()))))
            ->whereNull('archived_at')
            ->byAccessUser($userId)
            ->withCount('completedTasks')
            ->with(['stages', 'users'])
            ->orderBy('created_at', 'DESC')
            ->get();

        foreach ($boards as $board) {
            $board->users = Helper::sanitizeUserCollections($board->users);
        }

        return $boards;
    }

    private function deleteBoardMeta($boardId)
    {
        Meta::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->delete();
    }

    public function copyBoard($boardData)
    {
        $sourceBoard = Board::findOrFail($boardData['source_board_id']);
        $boardData['background'] = $sourceBoard->background;
        if (isset($boardData['description'])) {
            $boardData['description'] = DescriptionMarkdownConverter::normalize($boardData['description']);
        }
        $boardData = apply_filters('fluent_boards/before_create_board', $boardData);

        $board = Board::create($boardData);

        $this->setCurrentUserPreferencesOnBoardCreate($board);

        return $board;
    }

    public function archiveBoard($boardId)
    {
        $board = Board::findOrFail($boardId);
        $board->archived_at = current_time('mysql');
        $board->save();

        do_action('fluent_boards/board_archived', $board);
        return $board;
    }

    public function restoreBoard($boardId)
    {
        $board = Board::findOrFail($boardId);
        $board->archived_at = null;
        $board->save();

        do_action('fluent_boards/board_restored', $board);
        return $board;
    }

    public function makeMember($boardId, $userId)
    {
        $boardUser = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $userId)->first();

        $boardUser->settings = [
            'is_admin' => false,
            'is_viewer_only' => false
        ];

        $boardUser->save();
        $user = User::findOrFail($userId);
        do_action('fluent_boards/board_member_added', $boardId, $boardUser);
        $user['is_admin'] = false;
        $user['is_board_admin'] = false;
        return $user;
    }

    public function makeViewer($boardId, $userId)
    {
        $boardUser = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $userId)->first();

        $boardUser->settings = [
            'is_admin' => false,
            'is_viewer_only' => true
        ];

        $boardUser->save();
        $user = User::findOrFail($userId);
        do_action('fluent_boards/board_viewer_added', $boardId, $boardUser);
        $user['is_admin'] = false;
        $user['is_board_admin'] = false;
        return $user;
    }

    private function getUserWisePinnedBoards()
    {
        $userId = get_current_user_id();

        $pinnedBoardMeta = Meta::query()->where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', Constant::USER_PINNED_BOARDS)
            ->first();

        return $pinnedBoardMeta;
    }

    /**
     * Sidebar counts cover every board the user can access, so they are counted
     * with their own queries rather than derived from the filtered/paginated list.
     *
     * byAccessUser() re-reads the user's accessible board ids from the database on
     * every call, so the access scope is resolved once and cloned per count.
     *
     * @return array{all: int, pinned: int, archived: int}
     */
    public function getBoardCounts($userId)
    {
        $baseQuery = Board::byAccessUser($userId)
            ->excludeTemplates()
            ->availableInCurrentInstall();

        $counts = [
            'all'      => (clone $baseQuery)->whereNull('archived_at')->count(),
            'pinned'   => 0,
            'archived' => (clone $baseQuery)->whereNotNull('archived_at')->count()
        ];

        $pinnedIds = $this->getPinnedBoardIds();

        if ($pinnedIds) {
            $counts['pinned'] = (clone $baseQuery)->whereNull('archived_at')
                                                  ->whereIn('id', $pinnedIds)
                                                  ->count();
        }

        return $counts;
    }

    /**
     * @return array board ids the current user has pinned
     */
    public function getPinnedBoardIds()
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if (!$pinnedBoardMeta) {
            return [];
        }

        return array_map('intval', (array) $pinnedBoardMeta->value);
    }

    public function getPinnedBoards()
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if (!$pinnedBoardMeta) {
            return [];
        } else {
            $ids  = $pinnedBoardMeta->value;

            // Convert to array of integers
            $intIds = array_map('intval', $ids);

            return Board::whereIn('id', $intIds)
                ->whereNull('archived_at')
                ->byAccessUser(get_current_user_id())
                ->get();
        }
    }

    public function pinBoard($boardId)
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if ($pinnedBoardMeta) {
            $currentPinnedBoards = $pinnedBoardMeta->value;
            if (!in_array($boardId, $currentPinnedBoards)) {
                $currentPinnedBoards[] = $boardId;
                $pinnedBoardMeta->value = $currentPinnedBoards;
                $pinnedBoardMeta->save();
            }
        } else {
            // Create an empty array
            $boardIds = [];
            $boardIds[] = $boardId;

            $meta = new Meta();
            $meta->object_id = get_current_user_id();
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = Constant::USER_PINNED_BOARDS;
            $meta->value = $boardIds;
            $meta->save();
        }
    }

    /**
     * @param $boardId
     * @return bool
     */
    public function unpinBoard($boardId)
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if (!$pinnedBoardMeta) {
            return false;
        }

        $currentPinnedBoards = $pinnedBoardMeta->value;
        if (in_array($boardId, $currentPinnedBoards)) {
            $index = array_search($boardId, $currentPinnedBoards);
            array_splice($currentPinnedBoards, $index, 1);
            $pinnedBoardMeta->value = $currentPinnedBoards;
            $pinnedBoardMeta->save();
            return true;
        }

        return false;
    }

    /**
     * @param $boardId
     * @return bool
     * If board id is in user's current pinned boards list
     */
    public function isPinned($boardId)
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if (!$pinnedBoardMeta) {
            return false;
        }

        $currentPinnedBoards = $pinnedBoardMeta->value;
        if (in_array($boardId, $currentPinnedBoards)) {
            return true;
        }

        return false;
    }

    public function getBoardFolder($boardId)
    {
        $relation = Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('foreign_id', $boardId)
            ->first();

        if (!$relation) {
            return null;
        }

        return Folder::find($relation->object_id);
    }

    public function deleteWebhookData($boardId)
    {
        $outgoingRelations = Relation::where('object_type', 'outgoing_webhook_board')
            ->where('foreign_id', $boardId)
            ->get();

        foreach ($outgoingRelations as $relation) {
            $webhookMetaId = (int) $relation->object_id;

            $linkedCount = Relation::where('object_type', 'outgoing_webhook_board')
                ->where('object_id', $webhookMetaId)
                ->count();

            if ($linkedCount === 1) {
                Meta::where('id', $webhookMetaId)
                    ->where('object_type', 'outgoing_webhook')
                    ->delete();
            } else if ($linkedCount > 1) {
                $meta = Meta::find($webhookMetaId);
                if ($meta && $meta->object_type === 'outgoing_webhook') {
                    $value = $meta->value; 

                    if (isset($value['board_id'])) {
                        $boards = $value['board_id'];

                        if (is_array($boards)) {
                            $boards = array_values(array_filter($boards, function ($id) use ($boardId) {
                                return intval($id) !== intval($boardId);
                            }));
                            $value['board_id'] = $boards;
                        } else {
                            if ($boards !== null && intval($boards) === intval($boardId)) {
                                $value['board_id'] = [];
                            }
                        }

                        $meta->value = $value; 
                        $meta->save();
                    }
                }
            }
            $relation->delete();
        }
    }
}
