<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Relation;
use FluentBoards\Framework\Support\Arr;


class UserService
{
    public function allFluentBoardsUsers($boardId = null)
    {
        // Get the global admins first
        $adminUserIds = Meta::query()->where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->get()->pluck('object_id')->toArray();

        $boardObjects = Relation::where('object_type', 'board_user');

        if ($adminUserIds) {
            $boardObjects = $boardObjects->whereNotIn('foreign_id', $adminUserIds);
        }

        if ($boardId) {
            $boardObjects = $boardObjects->where('object_id', $boardId);
        }

        $boardObjects = $boardObjects->whereNotIn('foreign_id', $adminUserIds)
            ->get();

        $boardUserMaps = [];

        $accessBoardIds = [];

        foreach ($boardObjects as $boardObject) {
            if (!isset($boardUserMaps[$boardObject->foreign_id])) {
                $boardUserMaps[$boardObject->foreign_id] = [];
            }

            $accessBoardIds[] = $boardObject->object_id;

            $boardUserMaps[$boardObject->foreign_id][] = [
                'board_id' => $boardObject->object_id,
                'role'     => Arr::get($boardObject->settings, 'is_admin') ? 'admin' : 'member'
            ];
        }

        $accessBoardIds = array_unique($accessBoardIds);
        $allUserIds = array_unique(array_merge($adminUserIds, array_keys($boardUserMaps)));

        $users = get_users([
            'include' => $allUserIds
        ]);

        $boardUsers = [];
        $allBoards = Board::query()->whereIn('id', $accessBoardIds)->get()->keyBy('id');

        foreach ($users as $user) {

            $boards = Arr::get($boardUserMaps, $user->ID, []);

            $formattedBoars = [];

            foreach ($boards as $board) {
                if (empty($allBoards[$board['board_id']])) {
                    continue;
                }

                $boardModel = $allBoards[$board['board_id']];
                $formattedBoars[] = [
                    'id'    => $boardModel->id,
                    'title' => $boardModel->title,
                    'role'  => $board['role']
                ];
            }

            $name = trim($user->first_name . ' ' . $user->last_name);

            if (!$name) {
                $name = $user->display_name;
            }

            $photo = fluent_boards_user_avatar($user->user_email, $name);

            $boardUsers[] = [
                'ID'           => $user->ID,
                'display_name' => $user->display_name,
                'photo'        => $photo,
                'email'        => $user->user_email,
                'boards'       => $formattedBoars,
                'is_super'     => in_array($user->ID, $adminUserIds),
                'is_wpadmin'   => $user->has_cap('manage_options')
            ];
        }

        usort($boardUsers, function ($a, $b) {
            return strcmp($a['display_name'], $b['display_name']);
        });

        return $boardUsers;
    }

    public function memberAssociatedTaskUsers($userId)
    {
        $user = User::find($userId);
        $boards = $user->whichBoards->pluck('id');
        $boardUsers = Relation::whereIn('object_id', $boards)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->pluck('foreign_id')->toArray();
        $uniqueUsersIds = array_unique($boardUsers);
        $uniqueUsers = User::whereIn('ID', $uniqueUsersIds)->with('whichBoards')->get();

        $userWiseBoardDesignation = Relation::query()->whereIn('foreign_id', $uniqueUsersIds)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)->get();

        $data = array();
        $data['userWiseBoardDesignation'] = $userWiseBoardDesignation;

        foreach ($uniqueUsers as &$uniqueUser) {
            if (user_can($uniqueUser['ID'], 'manage_options') && PermissionManager::isFluentBoardsAdmin($uniqueUser['ID'])) {
                $uniqueUser['is_super'] = true;
                $uniqueUser['is_wpadmin'] = true;
            } elseif (user_can($uniqueUser['ID'], 'manage_options')) {
                $uniqueUser['is_wpadmin'] = true;
                $uniqueUser['is_super'] = false;
            } elseif (PermissionManager::isFluentBoardsAdmin($uniqueUser['ID'])) {
                $uniqueUser['is_super'] = true;
                $uniqueUser['is_wpadmin'] = false;
            } else {
                $uniqueUser['all_boards'] = Arr::get($uniqueUser, 'boards');
                $uniqueUser['is_super'] = false;
                $uniqueUser['is_wpadmin'] = false;
            }
        }

        $data['uniqueUsers'] = $uniqueUsers;

        return $data;
    }

    public function searchFluentBoardsUser($search_input)
    {

        $boardUsers = User::whereHas('whichBoards', function ($query) use ($search_input) {
            $query->where('display_name', 'like', '%' . $search_input . '%');
        })
            ->where('display_name', 'like', '%' . $search_input . '%')
            ->with('whichBoards')
            ->get()
            ->toArray();

        foreach ($boardUsers as &$boardUser) {
            if (user_can($boardUser['ID'], 'manage_options') && PermissionManager::isFluentBoardsAdmin($boardUser['ID'])) {
                $boardUser['is_super'] = true;
                $boardUser['is_wpadmin'] = true;
            } elseif (user_can($boardUser['ID'], 'manage_options')) {
                $boardUser['is_wpadmin'] = true;
                $boardUser['is_super'] = false;
            } elseif (PermissionManager::isFluentBoardsAdmin($boardUser['ID'])) {
                $boardUser['is_super'] = true;
                $boardUser['is_wpadmin'] = false;
            } else {
                $boardUser['all_boards'] = Arr::get($boardUser, 'boards');
                $boardUser['is_super'] = false;
                $boardUser['is_wpadmin'] = false;
            }
        }

        return $boardUsers;
    }

    public function searchMemberUser($search_input, $userId)
    {
        $user = User::find($userId);
        $boards = $user->boards->pluck('id');
        $boardUsers = Relation::whereIn('board_id', $boards)->orWhere('board_id', null)->where('status', 'ACTIVE')->pluck('user_id')->toArray();
        $uniqueUsersIds = array_unique($boardUsers);
        //		$uniqueUsers = User::whereIn('ID', $uniqueUsersIds)->with('boards')->get();

        $searchResult = User::query()->whereIn('ID', $uniqueUsersIds)->with('boards')
            ->where('display_name', 'like', '%' . $search_input . '%')
            ->take(20)->get();

        foreach ($searchResult as &$uniqueUser) {
            if (PermissionManager::isAdmin($uniqueUser['ID'])) {
                $uniqueUser['all_boards'] = Board::query()->where('is_archived', 0)->get();
                $isFluentBoardsAdmin = Relation::where('user_id', $uniqueUser['ID'])
                    ->whereNull('board_id')
                    ->where('status', 'ACTIVE')
                    ->first();
                if ($isFluentBoardsAdmin) {
                    $uniqueUser['is_super'] = true;
                    $uniqueUser['is_wpadmin'] = false;
                } else {
                    $uniqueUser['is_super'] = false;
                    $uniqueUser['is_wpadmin'] = true;
                }
            } else {
                $uniqueUser['all_boards'] = $uniqueUser['boards'];
                $uniqueUser['is_super'] = false;
                $uniqueUser['is_wpadmin'] = false;
            }
        }

        return $searchResult;
    }

//    public function getMemberAssociatedTasks($user_id, $requestData)
//    {
//        $taskType = $requestData['taskType'];
//        $boardIds = !empty($requestData['boardIds']) ? $requestData['boardIds'] : [];
//        $orderBy = $requestData['orderBy'];
//        $order = $requestData['order'];
//        $per_page = 15;
//        $user = User::find($user_id);
//
//        // get the task assigned to the user
//        $tasksQuery = $user->tasks()->with(['stage', 'board'])->whereNull('archived_at');
//        if ($taskType == 'upcoming') {
//            $tasksQuery = $tasksQuery->upcoming();
//        } elseif ($taskType == 'overdue') {
//            $tasksQuery = $tasksQuery->overdue();
//        } elseif ($taskType == 'completed') {
//            $tasksQuery = $tasksQuery->where('status', 'closed');
//        } else {
//            $tasksQuery = $tasksQuery->whereNull('due_at');
//        }
//
//        $currentUserId = get_current_user_id();
//        if($currentUserId != $user->ID) {
//            if(!PermissionManager::isAdmin()){
//                $currentUser = User::find($currentUserId);
//                $currentUserBoardIds = $currentUser->whichBoards->pluck('id')->toArray();
//                if (empty($boardIds)) {
//                    $boardIds = $currentUserBoardIds;
//                } else {
//                    // Get the intersection of the two arrays
//                    $boardIds = array_intersect($boardIds, $currentUserBoardIds);
//                }
//            }
//        }
//
//        if($boardIds){
//            $tasksQuery = $tasksQuery->whereIn('board_id', $boardIds);
//        }
//
//        $sortOptions = ['priority', 'due_at', 'position', 'created_at', 'title'];
//        $orderOptions = ['ASC', 'DESC'];
//
//        // Validate order and orderBy parameters
//        if (!in_array($order, $sortOptions) || !in_array($orderBy, $orderOptions)) {
//            throw new \Exception(__('Invalid sort or orderBy parameter', 'fluent-boards'));
//        }
//
//        // Apply ordering based on the specified order and orderBy
//        switch ($order) {
//            case 'priority':
//                $tasksQuery->orderByRaw("FIELD(priority, 'High', 'Medium', 'Low') {$orderBy}");
//                break;
//
//            case 'due_at':
//                if ($orderBy === 'ASC') {
//                    // Separate ordering for tasks with and without due dates
//                    $tasksQuery = $tasksQuery->orderBy('due_at');
//                } else {
//                    $tasksQuery->orderBy('due_at', $orderBy);
//                }
//                break;
//
//            default:
//                $tasksQuery->orderBy($order, $orderBy);
//                break;
//        }
//
//        $tasks = $tasksQuery->paginate($per_page, ['*'], 'page', $requestData->page);
//        return [
//            'tasks'          => $tasks->values()->toArray(),
//            'paginationInfo' => [
//                'current_page' => $tasks->currentPage(),
//                'last_page'    => $tasks->lastPage(),
//                'total'        => $tasks->total(),
//            ],
//        ];
//
//    }
    public function getMemberAssociatedTasks($user_id, $requestData)
    {
        $taskType = $requestData['taskType'] ?? null;
        $boardIds = !empty($requestData['boardIds']) ? $requestData['boardIds'] : [];
        $orderBy = $requestData['orderBy'] ?? 'created_at';
        $order = strtoupper($requestData['order'] ?? 'ASC');
        $per_page = $requestData['per_page'] ?? 15;
        $page = $requestData['page'] ?? 1;
        $user = User::find($user_id);

        if (!$user) {
            return [
                'tasks' => [],
                'paginationInfo' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                ],
            ];
        }

        // Get the task assigned to the user
        $tasksQuery = $user->tasks()->with(['stage', 'board'])->whereNull('archived_at');

        switch ($taskType) {
            case 'upcoming':
                $tasksQuery->upcoming();
                break;
            case 'overdue':
                $tasksQuery->overdue();
                break;
            case 'completed':
                $tasksQuery->where('status', 'closed');
                break;
            default:
                $tasksQuery->whereNull('due_at');
                break;
        }

        $currentUserId = get_current_user_id();
        if ($currentUserId != $user->ID && !PermissionManager::isAdmin()) {
            $currentUser = User::find($currentUserId);
            $currentUserBoardIds = $currentUser->boards->pluck('id')->toArray();

            if (empty($boardIds)) {
                $boardIds = $currentUserBoardIds;
            } else {
                // Get the intersection of the two arrays
                $boardIds = array_intersect($boardIds, $currentUserBoardIds);
            }
        }

        if (!empty($boardIds)) {
            $tasksQuery->whereIn('board_id', $boardIds);
        }

        $sortOptions = ['priority', 'due_at', 'position', 'created_at', 'title'];
        $orderOptions = ['ASC', 'DESC'];

        // Validate order and orderBy parameters
        if (!in_array($order, $orderOptions) || !in_array($orderBy, $sortOptions)) {
            throw new \Exception(__('Invalid sort or orderBy parameter', 'fluent-boards'));
        }

        // Apply ordering based on the specified order and orderBy
        if ($orderBy === 'priority') {
            $tasksQuery->orderByRaw("FIELD(priority, 'High', 'Medium', 'Low') {$order}");
        } else if ($orderBy === 'due_at') {
            $tasksQuery->orderByRaw("ISNULL(due_at), due_at {$order}");
        } else {
            $tasksQuery->orderBy($orderBy, $order);
        }

        $tasks = $tasksQuery->paginate($per_page, ['*'], 'page', $page);

        return [
            'tasks'          => $tasks->values()->toArray(),
            'paginationInfo' => [
                'current_page' => $tasks->currentPage(),
                'last_page'    => $tasks->lastPage(),
                'total'        => $tasks->total(),
            ],
        ];
    }


    public function getMemberRelatedAcitivies($user_id, $page)
    {
        $activities = Activity::query()->where('created_by', $user_id)
            ->orderBy('created_at', 'desc')
            ->with('user')->paginate(40, ['*'], 'page', $page);

        $activitiesToShow = array();

        foreach ($activities as $activity) {
            if ($activity->object_type == Constant::ACTIVITY_BOARD) {
                $activity->load('board');
//                if($activity->settings && $activity->settings['task_id']){
//                    $activity->task = Task::findOrFail($activity->settings['task_id']);
//                }
                if (PermissionManager::userHasPermission($activity->board_id, get_current_user_id())) {
                    $activitiesToShow[] = $activity;
                }
            } elseif ($activity->object_type == Constant::ACTIVITY_TASK) {
                $activity->load('task');
                if ($activity->task && PermissionManager::userHasPermission($activity->task->board_id, get_current_user_id())) {
                    $activitiesToShow[] = $activity;
                }
            }
        }
        return [
            'activities' => $activitiesToShow,
            'pagination' => $activities->toArray(),
        ];
    }

    private function getTaskById($taskId)
    {
        return Task::findOrFail($taskId);
    }

    public function getMemberBoards($user_id)
    {
        if(!$user_id) {
            return [];
        }
        $boardIds = [];
        $boards = [];
        $user = User::find($user_id);
        $currentUserId = get_current_user_id();
        if($currentUserId != $user->ID) {
            if(!PermissionManager::isAdmin()){
                $currentUser = User::find($currentUserId);
                $currentUserBoardIds = $currentUser->whichBoards->pluck('id')->toArray();
                $boardIds = $currentUserBoardIds;
            }
        }
        if (!empty($boardIds)) {
            $boards = $user->whichBoards()->whereIn('fbs_boards.id', $boardIds)->get();
        } else {
            $boards = $user->whichBoards;
        }
        return $boards;
    }
}
