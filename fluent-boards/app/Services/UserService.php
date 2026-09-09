<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Notification;
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
                'role' => Arr::get($boardObject->settings, 'is_admin')
                    ? 'admin'
                    : (Arr::has($boardObject->settings, 'is_viewer_only') && Arr::get($boardObject->settings, 'is_viewer_only')
                        ? 'viewer'
                        : 'member')
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

    /**
     * Return associated users and roles only from boards shared with the requester.
     */
    public function memberAssociatedTaskUsers($userId)
    {
        $user = User::find($userId);
        $memberBoardIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $user->whichBoards->pluck('id')->toArray()
        ))));
        $requesterBoardIds = array_values(array_unique(array_filter(array_map(
            'intval',
            PermissionManager::getBoardIdsForUser(get_current_user_id())
        ))));
        $sharedBoardIds = array_values(array_intersect($memberBoardIds, $requesterBoardIds));

        if (!$sharedBoardIds) {
            return [
                'uniqueUsers'               => [],
                'userWiseBoardDesignation' => [],
            ];
        }

        $boardUsers = Relation::whereIn('object_id', $sharedBoardIds)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->pluck('foreign_id')->toArray();
        $uniqueUsersIds = array_unique($boardUsers);
        $boardTable = (new Board())->getTable();
        $uniqueUsers = User::whereIn('ID', $uniqueUsersIds)
            ->with(['whichBoards' => function ($query) use ($boardTable, $sharedBoardIds) {
                $query->whereIn($boardTable . '.id', $sharedBoardIds);
            }])
            ->get();

        $userWiseBoardDesignation = Relation::query()->whereIn('foreign_id', $uniqueUsersIds)
            ->whereIn('object_id', $sharedBoardIds)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->get();

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
                $uniqueUser['all_boards'] = $uniqueUser->getRelation('whichBoards');
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
    

    public function getMemberAssociatedTasks($user_id, $requestData)
    {
        $taskType = $requestData['taskType'] ?? null;
        $boardIds = !empty($requestData['boardIds']) ? $requestData['boardIds'] : [];
        $orderBy = $requestData['orderBy'] ?? 'created_at';
        $order = strtoupper($requestData['order'] ?? 'ASC');
        $per_page = $requestData['per_page'] ?? 15;
        $page = $requestData['page'] ?? 1;
        $user = User::find($user_id);

        $loggedInUserId = get_current_user_id();

        $allowedBoardIds = PermissionManager::getBoardIdsForUser($loggedInUserId);

        if (!$user || empty($allowedBoardIds)) {
            return [
                'tasks' => [],
                'paginationInfo' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => (int) $per_page,
                    'total' => 0,
                ],
            ];
        }

        // Table view needs the labels column; list view ignores the extra relation.
        $taskRelations = ['stage', 'board', 'labels'];

        if ($taskType == 'assigned') {
            $tasksQuery = $user->assignedTasks()
                ->with($taskRelations)
                ->whereNull('archived_at')
                ->whereNull('parent_id')
                ->whereIn('board_id', $allowedBoardIds)
                ->where('status', '!=', 'closed')
                ->onActiveAvailableBoards();
        } else if ($taskType == 'mentioned') {
            $tasksQuery = $user->mentionedTasks()
                ->with($taskRelations)
                ->whereNull('archived_at')
                ->whereNull('parent_id')
                ->whereIn('board_id', $allowedBoardIds)
                ->onActiveAvailableBoards();
        } else {
            // Watched tasks power the date-based and completed profile tabs.
            $tasksQuery = $user->tasks()
                ->with($taskRelations)
                ->whereNull('archived_at')
                ->whereIn('board_id', $allowedBoardIds)
                ->onActiveAvailableBoards();

            switch ($taskType) {
                case 'upcoming':
                    $tasksQuery->upcoming()
                        ->whereIn('board_id', $allowedBoardIds);
                    break;
                case 'due_today':
                    $tasksQuery->dueToday()
                        ->whereIn('board_id', $allowedBoardIds);
                    break;
                case 'overdue':
                    $tasksQuery->overdue()
                        ->whereIn('board_id', $allowedBoardIds);
                    break;
                case 'completed':
                    $tasksQuery->where('status', 'closed')
                        ->whereIn('board_id', $allowedBoardIds);
                    break;
                default:
                    $tasksQuery->whereNull('due_at')
                        ->whereIn('board_id', $allowedBoardIds);
                    break;
            }
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
            throw new \Exception(esc_html__('Invalid sort or orderBy parameter', 'fluent-boards'));
        }

        // Apply ordering based on the specified order and orderBy
        if ($orderBy === 'priority') {
            $tasksQuery->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low') {$order}");
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
                'per_page'     => (int) $tasks->perPage(),
                'total'        => $tasks->total(),
            ],
        ];
    }

    /**
     * Get totals for each task category available in the profile task tabs.
     */
    public function getMemberTaskCounts($userId, $boardIds = [])
    {
        $user = User::find($userId);
        $currentUserId = get_current_user_id();
        $allowedBoardIds = PermissionManager::getBoardIdsForUser($currentUserId);

        if (!$user || empty($allowedBoardIds)) {
            return array_fill_keys(
                ['due_today', 'assigned', 'upcoming', 'overdue', 'mentioned', 'completed', 'others'],
                0
            );
        }

        if ($currentUserId != $user->ID && !PermissionManager::isAdmin()) {
            $currentUser = User::find($currentUserId);
            $currentUserBoardIds = $currentUser->boards->pluck('id')->toArray();
            $boardIds = empty($boardIds)
                ? $currentUserBoardIds
                : array_intersect($boardIds, $currentUserBoardIds);
        }

        // Keep the count queries aligned with the profile list without loading task models or relations.
        $applyTaskScope = function ($query) use ($allowedBoardIds, $boardIds) {
            $query->whereNull('archived_at')
                ->whereNull('parent_id')
                ->whereIn('board_id', $allowedBoardIds)
                ->onActiveAvailableBoards();

            if (!empty($boardIds)) {
                $query->whereIn('board_id', $boardIds);
            }

            return $query;
        };
        $watchedTasks = function () use ($user, $applyTaskScope) {
            return $applyTaskScope($user->tasks());
        };

        return [
            'due_today' => (int) $watchedTasks()->dueToday()->count(),
            'assigned'  => (int) $applyTaskScope($user->assignedTasks())
                ->where('status', '!=', 'closed')
                ->count(),
            'upcoming'  => (int) $watchedTasks()->upcoming()->count(),
            'overdue'   => (int) $watchedTasks()->overdue()->count(),
            'mentioned' => (int) $applyTaskScope($user->mentionedTasks())->count(),
            'completed' => (int) $watchedTasks()->where('status', 'closed')->count(),
            'others'    => (int) $watchedTasks()->whereNull('due_at')->count(),
        ];
    }


    /**
     * Get a member's activities scoped to boards the requesting user can access.
     */
    public function getMemberRelatedAcitivies($user_id, $page)
    {
        $perPage = 40;
        $user_id = absint($user_id);
        $page = max(1, absint($page));
        $allowedBoardIds = array_values(array_filter(array_map(
            'intval',
            PermissionManager::getBoardIdsForUser(get_current_user_id())
        )));

        if (empty($allowedBoardIds)) {
            return [
                'activities'  => [],
                'pagination'  => $this->getEmptyPaginationInfo($page, $perPage),
            ];
        }

        $allowedTaskIds = Task::query()
            ->select('id')
            ->whereIn('board_id', $allowedBoardIds);

        $activities = Activity::query()
            ->where('created_by', $user_id)
            ->where(function ($query) use ($allowedBoardIds, $allowedTaskIds) {
                $query->where(function ($boardQuery) use ($allowedBoardIds) {
                    $boardQuery->where('object_type', Constant::ACTIVITY_BOARD)
                        ->whereIn('object_id', $allowedBoardIds);
                })->orWhere(function ($taskQuery) use ($allowedTaskIds) {
                    $taskQuery->where('object_type', Constant::ACTIVITY_TASK)
                        ->whereIn('object_id', $allowedTaskIds);
                });
            })
            ->orderBy('created_at', 'desc')
            ->with('user')
            ->paginate($perPage, ['*'], 'page', $page);

        $activitiesToShow = [];

        foreach ($activities as $activity) {
            if ($activity->object_type == Constant::ACTIVITY_BOARD) {
                $activity->load('board');
                $activitiesToShow[] = $activity;
            } elseif ($activity->object_type == Constant::ACTIVITY_TASK) {
                $activity->load('task');
                $activitiesToShow[] = $activity;
            }
        }

        return [
            'activities'  => $activitiesToShow,
            'pagination'  => $this->getPaginationInfo($activities),
        ];
    }

    /**
     * Return pagination metadata without duplicating serialized row data.
     */
    private function getPaginationInfo($paginator)
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => (int) $paginator->perPage(),
            'total'        => $paginator->total(),
        ];
    }

    /**
     * Return an empty pagination payload for callers without accessible boards.
     */
    private function getEmptyPaginationInfo($page, $perPage)
    {
        return [
            'current_page' => max(1, (int) $page),
            'last_page'    => 1,
            'per_page'     => (int) $perPage,
            'total'        => 0,
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
        $user = User::find($user_id);
        $currentUserId = get_current_user_id();

        if ($currentUserId == $user->ID || PermissionManager::isAdmin($currentUserId)) {
            return $user->whichBoards;
        }

        $currentUser = User::find($currentUserId);
        $boardIds = $currentUser ? $currentUser->whichBoards->pluck('id')->toArray() : [];

        if (empty($boardIds)) {
            return [];
        }

        return $user->whichBoards()->whereIn('fbs_boards.id', $boardIds)->get();
    }

    /**
     * Returns four aggregate counts for a member's stats widget.
     *
     * Cross-user access is scoped to boards shared with the requesting user,
     * matching the contract of getMemberBoards() and getMemberAssociatedTasks().
     * Unread notifications are personal and only returned for self or admin.
     */
    public function getMemberStats(int $user_id): array
    {
        $empty = ['assigned_tasks' => 0, 'completed_tasks' => 0, 'total_boards' => 0, 'unread_notifications' => 0];

        $user = User::find($user_id);
        if (!$user) {
            return $empty;
        }

        $currentUserId = get_current_user_id();
        $isSelfOrAdmin = ($currentUserId === $user_id) || PermissionManager::isAdmin($currentUserId);

        // For cross-user access, restrict counts to boards the requesting user can also see.
        $allowedBoardIds = null;
        if (!$isSelfOrAdmin) {
            $currentUser     = User::find($currentUserId);
            $allowedBoardIds = $currentUser ? $currentUser->whichBoards->pluck('id')->toArray() : [];
            if (empty($allowedBoardIds)) {
                return $empty;
            }
        }

        $assignedBase = $user->assignedTasks()->whereNull('archived_at')->whereNull('parent_id');
        if ($allowedBoardIds !== null) {
            $assignedBase->whereIn('board_id', $allowedBoardIds);
        }

        $boardsQuery = $user->whichBoards();
        if ($allowedBoardIds !== null) {
            $boardsQuery->whereIn('fbs_boards.id', $allowedBoardIds);
        }

        // Use Notification model with object_type guard — matches NotificationService::newNotificationNumber().
        // Notifications are personal; return 0 when a non-admin views another member's profile.
        $unreadNotifications = 0;
        if ($isSelfOrAdmin) {
            $unreadNotifications = Notification::query()
                ->where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION)
                ->whereHas('users', function ($q) use ($user_id) {
                    $q->where('user_id', $user_id)->whereNull('marked_read_at');
                })
                ->count();
        }

        return [
            'assigned_tasks'       => (int) (clone $assignedBase)->where('status', '!=', 'closed')->count(),
            'completed_tasks'      => (int) (clone $assignedBase)->where('status', 'closed')->count(),
            'total_boards'         => (int) $boardsQuery->count(),
            'unread_notifications' => (int) $unreadNotifications,
        ];
    }
}
