<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\Framework\Support\Arr;
use FluentRoadmap\App\Models\Idea;

class BoardService
{
    public function getBoardsByType($type)
    {
        return Board::where('type', $type)->whereNull('archived_at')->orderBy('created_at', 'ASC')->get();
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

        //removing add board comment
        $board->comments()->delete();

        foreach ($board->notifications as $notification) {
            $notification->users()->detach();
        }
        $board->notifications()->delete();

        //delete board related meta
        $this->deleteBoardMeta($boardId);

        //delete from recently viewed
        $this->deleteFromRecentlyViewed($boardId);

        $board->delete();
        FileSystem::deleteDir('board_'.$boardId);
//        do_action('fluent_boards/board_deleted', $board);
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
            'description' => $boardData['description'],
            'currency'    => isset($boardData['currency']) ? $boardData['currency'] : 'USD',
            'background'  => isset($boardData['background']) ? $boardData['background'] : '',
            'created_by'  => isset($boardData['created_by']) ? $boardData['created_by'] : get_current_user_id()
        ];

        $boardData = apply_filters('fluent_boards/before_create_board', $boardData);

        $board = Board::create($boardData);

        $this->setCurrentUserPreferencesOnBoardCreate($board);

        return $board;
    }

    private function setCurrentUserPreferencesOnBoardCreate($board)
    {
        $board->users()->attach(
            $board->created_by,
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

            $index = array_search($boardId, $recentBoardIds);
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
            throw new \Exception('Title cannot be empty');
        }
        if (isset($data['description'])) {
            $data['description'] = $data['description'];
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

    public function changePositionOfStage($boardId, $changeData)
    {
        $allStages = Stage::where('board_id', $boardId)
            ->where('is_archived', 0)
            ->where('type', 'stage')
            ->orderBy('position', 'asc')
            ->get();

        $changedStage = null;

        foreach ($allStages as $stage) {
            if ($changeData['fromPosition'] < $changeData['toPosition']) {
                if ($stage['position'] == $changeData['fromPosition']) {
                    $stage['position'] = (int)$changeData['toPosition'];
                    $stage->save();
                    $changedStage = $stage;
                } elseif (
                    $stage['position'] > $changeData['fromPosition'] &&
                    $stage['position'] <= $changeData['toPosition']
                ) {
                    $stage['position'] = $stage->position - 1;
                    $stage->save();
                }
            } else {
                if (
                    $stage['position'] >= $changeData['toPosition'] &&
                    $stage['position'] < $changeData['fromPosition']
                ) {
                    $stage['position'] = $stage->position + 1;
                    $stage->save();
                } elseif ($stage['position'] == $changeData['fromPosition']) {
                    $stage['position'] = (int)$changeData['toPosition'];
                    $stage->save();
                    $changedStage = $stage;
                }
            }
        }

        do_action('fluent_boards/board_stage_dragged', $changedStage);
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

    public function archiveStage($boardId, $stage)
    {
        $stage->archived_at = current_time('mysql');
        $stage->position = 0;
        $stage->save();

        do_action('fluent_boards/stage_archived', $boardId, $stage);
        return $stage;
    }

    public function restoreStage($boardId, $stage)
    {
        $stageService = new StageService();
        $lastStagePosition = $stageService->getLastPositionOfStagesOfBoard($stage->board_id);
        $stage->archived_at = null;
        $stage->position = $lastStagePosition ? $lastStagePosition->position + 1 : 1;
        $stage->save();
        do_action('fluent_boards/board_stage_restored', $boardId, $stage->title);
        return $stage;
    }

    public function getActivities($id, $data)
    {
        $per_page = isset($data['per_page']) ? $data['per_page'] : 40;
        $page = isset($data['page']) ? $data['page'] : 1;
        return Activity::where('object_id', $id)->where('object_type', Constant::ACTIVITY_BOARD)->with(['user'])
            ->orderBy('id', 'DESC')
            ->paginate($per_page, ['*'], 'page', $page);
    }

    public function isAlreadyMember($boardId, $memberId)
    {
        $isAlreadyMember = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('foreign_id', $memberId)->first();

        return $isAlreadyMember ?? false;
    }

    public function addMembersInBoard($boardId, $memberId, $isViewerOnly = null)
    {
        $board = Board::find($boardId);

        if (!$board) {
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
        $boardMember = User::find($memberId);
        if(!$isViewerOnly) {
            do_action('fluent_boards/board_viewer_added', $boardId, $boardMember);
        } else {
            do_action('fluent_boards/board_member_added', $boardId, $boardMember);
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

    public function getUsersOfBoards()
    {
        $userBoards = Relation::whereNotNull('board_id')
            ->where('user_id', get_current_user_id())
            ->where('status', 'ACTIVE')->get();

        return $userBoards;
    }

    /**
     * change board background
     * @param mixed $backgroundData
     * @return string
     */

    public function setBoardBackground($backgroundData, $board_id)
    {
        $board = Board::find($board_id);
        $oldBackground = $board->background;
        $background = $board->background;

        // if board background has color
        if (isset($backgroundData['color'])) {
            $background['color'] = $backgroundData['color'];
            $background['image_url'] = null;
            $background['is_image'] = false;
        }

        // if board background has image
        if (isset($backgroundData['image_url'])) {
            $background['image_url'] = $backgroundData['image_url'];
            $background['is_image'] = true;
            $background['color'] = null;
        }
        $background['id'] = $backgroundData['id'];

        $board->background = $background;
        $board->save();
        do_action('fluent_boards/board_background_updated', $board_id, $oldBackground);

        return $board->background;
    }


    /**
     * Summary of getStageTaskAvailablePositions
     * @param mixed $board_id
     * @param mixed $stage_slug
     * @return array of available positions of the stage with one increased value because if the stage has 10  tasks than it will have 10 position and +1 as last position of the stage
     */
    public function getStageTaskAvailablePositions($board_id, $stage_id)
    {
        $availablePositions = Task::query()
            ->where('board_id', $board_id)
            ->where('parent_id', null)
            ->where('stage_id', $stage_id)
            ->whereNull('archived_at')
            ->orderBy('position', 'asc')
            ->get()
            ->pluck('position')->toArray();

        $totalPosition = count($availablePositions);
        $availablePositions[$totalPosition] = $totalPosition + 1;

        return $availablePositions;
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
                // If there are already 3 boards, remove the last one
                if (count($recentBoardIds) >= 3) {
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

        $currentUser = User::find($userId);

        if (!PermissionManager::isAdmin($userId)){
            $recentBoardIds = array_intersect($recentBoardIds, $currentUser->whichBoards->pluck('id')->toArray());
        }

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

        return Board::whereIn('id', $recentBoardIds)->withCount('completedTasks')->with(['stages', 'users'])->get();
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

    public function sendInvitationToBoard($boardId, $email)
    {
        $user = User::query()->where('user_email', $email)->first();

        if ($user) {
            return $user;
        }

        $current_user_id = get_current_user_id();

        do_action('fluent_boards/send_invitation', $boardId, $email, $current_user_id);

        return;

    }

    public function getInvitations($boardId)
    {
        return Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_INVITATION)
            ->get();
    }

    public function deleteInvitation($invitationId)
    {
        Meta::findOrFail($invitationId)->delete();
    }

    public function hasDataChanged($boardId)
    {
        $stages = [];
        $labels = [];
        $tasks = [];
        $taskDeleted = false;
        $stageDeleted = false;
        $labelDeleted = false;
        $oneMinuteAgoTimestamp = current_time('timestamp') - 60; // Get the current timestamp and subtract 60 seconds
        $oneMinuteAgo = date_i18n('Y-m-d H:i:s', $oneMinuteAgoTimestamp); // Format the timestamp into the desired format in GMT

        $board = Board::find($boardId);
        if (!$board) {
            throw new \Exception("Board doesn't exists");
        }
        // if stage in this board has been deleted
        $stageDeleted = Activity::where('object_id', $boardId)->where('updated_at', '>=', $oneMinuteAgo)->where('action', 'deleted')->where('column', 'stage')->exists();

        if ($stageDeleted) {
            $stages = $board->stages;
        } else {
            $stages = (new StageService())->getLastOneMinuteUpdatedStages($boardId);
        }

        $labelDeleted = Activity::where('object_id', $boardId)->where('updated_at', '>=', $oneMinuteAgo)->where('action', 'deleted')->where('column', 'label')->exists();
        if ($labelDeleted) {
            $labels = $board->labels;
        } else {
            $labels = (new LabelService())->getLastOneMinuteUpdatedLabels($boardId);
        }

        // if task in this board has been deleted
        $taskDeletedOrMovedFormBoard = Activity::where('object_id', $boardId)
            ->where('updated_at', '>=', $oneMinuteAgo)
            ->where(function($query) {
                $query->where('action', 'deleted')
                    ->orWhere('action', 'moved');
            })
            ->where('column', 'task')
            ->exists();
        if ($taskDeletedOrMovedFormBoard) {
            $tasksQuery = Task::query()
                ->where([
                    'board_id'    => $boardId,
                    'parent_id'   => null,
                    'archived_at' => null
                ])
                ->with(['assignees', 'labels', 'watchers']);

            if (!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                $tasksQuery->with('customFields');
            }

            $tasks = $tasksQuery->orderBy('due_at', 'ASC')->get();
        } else {
            $tasks = (new TaskService())->getLastOneMinuteUpdatedTasks($boardId);
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

        return [
            'board'        => $board,
            'stages'       => $stages,
            'labels'       => $labels,
            'tasks'        => $tasks,
            'taskDeleted'  => $taskDeletedOrMovedFormBoard,
            'stageDeleted' => $stageDeleted,
        ];
    }

    public function getAssociatedBoards($associatedId)
    {

        $boardIds = Meta::query()->where('value', $associatedId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_ASSOCIATED_CRM_CONTACT)
            ->pluck('object_id');

        return Board::query()->whereIn('id', $boardIds)->with('stages', 'users')->get();
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
        $boardData = apply_filters('fluent_boards/before_create_board', $boardData);

        $board = Board::create($boardData);

        $this->setCurrentUserPreferencesOnBoardCreate($board);

        return $board;
    }

    public function getBoardReports($board_id)
    {
        $board = Board::findOrFail($board_id);
        $taskQuery = Task::where('board_id', $board_id)
            ->whereNull('parent_id')
            ->whereNull('archived_at');

        if($board->type == 'roadmap') {
            $pendingStage = $this->getNewIdeaStage($board->id);
            return $this->getIdeaReports($taskQuery, $pendingStage);
        } else {
            return $this->getTaskReports($taskQuery);
        }
    }

    private function getNewIdeaStage($boardId)
    {
        return Stage::where('board_id', $boardId)
            ->where('type', 'stage')
            ->where('archived_at', null)
            ->orderBy('position', 'ASC')
            ->first();
    }

    public function getAllBoardReports(){
        $userId = get_current_user_id();

        $taskQuery = Task::whereNull('parent_id')
            ->whereNull('archived_at')
            ->whereHas('board', function ($query) {
                $query->where('type', 'to-do');
            });

        if (!PermissionManager::isAdmin($userId))
        {
            $currentUser = User::find($userId);
            $relatedBoardIds = $currentUser->whichBoards->where('type', 'to-do')->pluck('id');
            $taskQuery->whereIn('board_id', $relatedBoardIds);
        }

        return $this->getTaskReports($taskQuery);
    }

    private function getTaskReports($taskQuery)
    {
        $totalTasksQuery = clone $taskQuery;
        $completedTaskQuery = clone $taskQuery;
        $openTaskQuery = clone $taskQuery;
        $overDueTaskQuery = clone $taskQuery;

        $completedTaskCount = $completedTaskQuery->where('status', 'closed')->count();
        $openTaskCount = $openTaskQuery->where('status', 'open')->count();
        $overDueTasks = $overDueTaskQuery->overdue(true)->count();
        $totalTasks = $totalTasksQuery->count();

        $taskQuery->where('status', 'open');

        $highQuery = clone $taskQuery;
        $mediumQuery = clone $taskQuery;
        $lowQuery = clone $taskQuery;

        $high = $highQuery->where('priority', 'high')->count();
        $low = $mediumQuery->where('priority', 'low')->count();
        $medium = $lowQuery->where('priority', 'medium')->count();

        $reportData = [
            'completion' => [
                'completed'  => $completedTaskCount,
                'incomplete' => $openTaskCount,
                'overdue'    => $overDueTasks,
                'total'      => $totalTasks
            ],
            'priority'   => [
                'high'   => $high,
                'medium' => $medium,
                'low'    => $low
            ]

        ];
        return $reportData;
    }

    private function getIdeaReports($taskQuery, $pendingStage)
    {
        $pendingIdeaQuery = clone $taskQuery;
        $completedIdeaQuery = clone $taskQuery;
        $openIdeaQueryPage = clone $taskQuery;
        $openIdeaQueryWeb = clone $taskQuery;

        $pendingIdeaCount = $pendingIdeaQuery->where('status', 'open')->where('stage_id', $pendingStage->id)->count();
        $completedIdeaCount = $completedIdeaQuery->where('status', 'closed')->count();
        $openIdeaCountPage = $openIdeaQueryPage->where('status', 'open')->where('source', 'page')->count();
        $openIdeaCountWeb = $openIdeaQueryWeb->where('status', 'open')->where('source', 'web')->count();
        $totalIdeas = $openIdeaCountPage + $openIdeaCountWeb;

        $taskQuery->where('status', 'open');

        $highQuery = clone $taskQuery;
        $mediumQuery = clone $taskQuery;
        $lowQuery = clone $taskQuery;

        $high = $highQuery->where('priority', 'high')->count();
        $low = $mediumQuery->where('priority', 'low')->count();
        $medium = $lowQuery->where('priority', 'medium')->count();

        $reportData = [
            'completion' => [
                'pending' => $pendingIdeaCount,
                'completed'  => $completedIdeaCount,
                'ideaFromPage' => $openIdeaCountPage,
                'total'      => $totalIdeas
            ],
            'priority'   => [
                'high'   => $high,
                'medium' => $medium,
                'low'    => $low
            ]
        ];
        return $reportData;
    }

    public function getStageWiseBoardReports($board_id)
    {
        $stages = Stage::where('board_id', $board_id)
            ->where('type', 'stage')
            ->whereNull('archived_at')
            ->get();

        foreach ($stages as $stage) {
            $completedTaskCount = Task::where('stage_id', $stage->id)
                ->where('status', 'closed')
                ->count();

            $openTaskCount = Task::where('stage_id', $stage->id)
                ->whereNull('due_at')
                ->where('status', 'open')
                ->count();

            $overDue = Task::where('stage_id', $stage->id)
                ->whereNotNull('due_at')
                ->where('status', 'open')
                ->overdue(true)
                ->count();

            $stage->report = [
                'completed'  => $completedTaskCount,
                'incomplete' => $openTaskCount,
                'overdue'    => $overDue
            ];
        }

        return $stages;
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

    public function getPinnedBoards()
    {
        $pinnedBoardMeta = $this->getUserWisePinnedBoards();

        if (!$pinnedBoardMeta) {
            return [];
        } else {
            $ids  = $pinnedBoardMeta->value;

            // Convert to array of integers
            $intIds = array_map('intval', $ids);

            return Board::whereIn('id', $intIds)->whereNull('archived_at')->get();
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
}
