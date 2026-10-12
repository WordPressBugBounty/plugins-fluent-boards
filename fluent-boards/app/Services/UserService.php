<?php

namespace FluentBoards\App\Services;

use Exception;
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
    /** Save only the current member's public display name. */
    public function updateDisplayName($userId, $displayName)
    {
        $userId = absint($userId);
        $this->assertCanUpdateProfile($userId);
        $displayName = is_string($displayName) ? trim(sanitize_text_field($displayName)) : '';
        if ($displayName === '') {
            throw new Exception(__('Display name is required', 'fluent-boards'), 400);
        }
        // WordPress treats "0" as empty and replaces it with the login name.
        if ($displayName === '0') {
            throw new Exception(__('Display name cannot be "0"', 'fluent-boards'), 400);
        }

        if (mb_strlen($displayName, 'UTF-8') > Constant::PROFILE_DISPLAY_NAME_MAX_LENGTH) {
            throw new Exception(__('Display name must be 250 characters or fewer', 'fluent-boards'), 400);
        }

        $expectedName = null;
        // Validate the final, unslashed storage value after WordPress and plugin filters.
        $validateStorage = function ($data, $update, $targetUserId) use ($userId, &$expectedName) {
            if (!$update || (int) $targetUserId !== $userId) {
                return $data;
            }
            $expectedName = $data['display_name'] ?? null;
            if (!is_string($expectedName) || $expectedName === '') {
                throw new Exception(__('Display name is required', 'fluent-boards'), 400);
            }
            if (mb_strlen($expectedName, 'UTF-8') > Constant::PROFILE_DISPLAY_NAME_MAX_LENGTH) {
                throw new Exception(__('Display name must be 250 characters or fewer', 'fluent-boards'), 400);
            }
            return $data;
        };
        add_filter('wp_pre_insert_user_data', $validateStorage, PHP_INT_MAX, 3);
        try {
            $result = wp_update_user(['ID' => $userId, 'display_name' => wp_slash($displayName)]);
        } finally {
            remove_filter('wp_pre_insert_user_data', $validateStorage, PHP_INT_MAX);
        }
        if (is_wp_error($result)) {
            throw new Exception($result->get_error_message(), 400);
        }

        // WordPress can return a user ID after a failed database update.
        clean_user_cache($userId);
        $user = get_userdata($userId);
        if (!$user) {
            throw new Exception(__('Could not load the updated profile', 'fluent-boards'), 400);
        }
        if ($expectedName === null || $user->display_name !== $expectedName) {
            throw new Exception(__('Could not save the display name. Please try again.', 'fluent-boards'), 400);
        }
        $displayName = $user->display_name;
        do_action('fluent_boards/profile_name_updated', $userId, $displayName);
        return ['display_name' => $displayName];
    }

    /** Validate and store a custom avatar without requiring media-library access. */
    public function updateProfilePhoto($userId, $file)
    {
        $userId = absint($userId);
        $this->assertCanUpdateProfile($userId);
        if (!is_array($file) || !isset($file['error'], $file['size'], $file['tmp_name'], $file['name'])
            || $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_string($file['name'])
            || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception(__('No photo uploaded or upload error', 'fluent-boards'), 400);
        }
        if (filesize($file['tmp_name']) <= 0 || filesize($file['tmp_name']) > Constant::PROFILE_PHOTO_MAX_BYTES) {
            throw new Exception(__('Photo must be 2 MB or smaller', 'fluent-boards'), 400);
        }

        $mimes = ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        // Inspect the file contents, not the browser MIME or filename alone.
        if (!in_array(wp_get_image_mime($file['tmp_name']), $mimes, true)) {
            throw new Exception(__('Choose a JPG, PNG, GIF or WebP image', 'fluent-boards'), 400);
        }
        $dimensions = wp_getimagesize($file['tmp_name']);
        if (!$dimensions || $dimensions[0] < 1 || $dimensions[1] < 1
            || max($dimensions[0], $dimensions[1]) > Constant::PROFILE_PHOTO_MAX_DIMENSION
            || $dimensions[0] * $dimensions[1] > Constant::PROFILE_PHOTO_MAX_PIXELS) {
            throw new Exception(__('Photo dimensions must not exceed 4096 pixels per side or 12 megapixels', 'fluent-boards'));
        }
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $mimes]);
        if (isset($uploaded['error'])) {
            throw new Exception($uploaded['error'], 400);
        }

        $uploaded = $this->createProfileAvatar($uploaded);
        $this->replaceProfilePhoto($userId, $uploaded);
        $photoUrl = esc_url_raw($uploaded['url']);
        do_action('fluent_boards/profile_photo_updated', $userId, $photoUrl);
        return ['photo' => fluent_boards_user_avatar(wp_get_current_user()->user_email)];
    }

    /** Re-encode a bounded avatar and discard the source before persisting profile changes. */
    private function createProfileAvatar(array $uploaded)
    {
        $directory = dirname($uploaded['file']);
        $filename = wp_unique_filename($directory, pathinfo($uploaded['file'], PATHINFO_FILENAME) . '-avatar.png');
        $avatarPath = $directory . '/' . $filename;

        try {
            $editor = wp_get_image_editor($uploaded['file']);
            if (is_wp_error($editor)) {
                throw new Exception($editor->get_error_message());
            }
            $size = $editor->get_size();
            if (max($size['width'], $size['height']) > Constant::PROFILE_AVATAR_SIZE) {
                $resized = $editor->resize(Constant::PROFILE_AVATAR_SIZE, Constant::PROFILE_AVATAR_SIZE, false);
                if (is_wp_error($resized)) {
                    throw new Exception($resized->get_error_message());
                }
            }
            $avatar = $editor->save($avatarPath, 'image/png');
            if (is_wp_error($avatar)) {
                throw new Exception($avatar->get_error_message());
            }

            return [
                'file' => $avatar['path'],
                'url' => trailingslashit(dirname($uploaded['url'])) . wp_basename($avatar['path']),
                'type' => $avatar['mime-type'],
            ];
        } catch (Exception $e) {
            wp_delete_file($avatarPath);
            throw $e;
        } finally {
            wp_delete_file($uploaded['file']);
        }
    }

    /** Serialize replacement across requests sharing the same WordPress user table. */
    private function replaceProfilePhoto($userId, array $uploaded)
    {
        global $wpdb;

        $lockName = Constant::USER_PROFILE_PHOTO_UPLOAD . ':' . md5(DB_NAME . ':' . $wpdb->users . ':' . $userId);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lockName)) !== 1) {
            wp_delete_file($uploaded['file']);
            throw new Exception(__('The profile photo is being updated. Please try again.', 'fluent-boards'));
        }

        try {
            // A waiting request may have cached metadata from before the previous upload.
            wp_cache_delete($userId, 'user_meta');
            $this->persistProfilePhoto($userId, $uploaded);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** Track uploads we own; legacy or externally supplied avatar URLs never authorize deletion. */
    private function persistProfilePhoto($userId, array $uploaded)
    {
        $previousUrl = get_user_meta($userId, Constant::USER_PROFILE_PHOTO, true);
        $previousUpload = get_user_meta($userId, Constant::USER_PROFILE_PHOTO_UPLOAD, true);
        $ownedUpload = [
            'file' => $uploaded['file'],
            'url' => esc_url_raw($uploaded['url']),
            'blog_id' => get_current_blog_id(),
        ];

        if (!update_user_meta($userId, Constant::USER_PROFILE_PHOTO_UPLOAD, $ownedUpload)) {
            wp_delete_file($uploaded['file']);
            throw new Exception(__('The profile photo could not be saved. Please try again.', 'fluent-boards'));
        }
        if (!update_user_meta($userId, Constant::USER_PROFILE_PHOTO, $ownedUpload['url'])) {
            if ($previousUpload) {
                update_user_meta($userId, Constant::USER_PROFILE_PHOTO_UPLOAD, $previousUpload);
            } else {
                delete_user_meta($userId, Constant::USER_PROFILE_PHOTO_UPLOAD);
            }
            wp_delete_file($uploaded['file']);
            throw new Exception(__('The profile photo could not be saved. Please try again.', 'fluent-boards'));
        }

        // Only a matching, locally tracked upload can be retired after both writes succeed.
        if (!is_array($previousUpload)
            || ($previousUpload['url'] ?? null) !== $previousUrl
            || ($previousUpload['blog_id'] ?? null) !== get_current_blog_id()
            || !is_string($previousUpload['file'] ?? null)
            || $previousUpload['file'] === $ownedUpload['file']) {
            return;
        }

        $uploads = wp_get_upload_dir();
        $previousFile = realpath($previousUpload['file']);
        $uploadsDirectory = realpath($uploads['basedir']);
        if (!$uploads['error'] && $previousFile && $uploadsDirectory
            && strpos(wp_normalize_path($previousFile), trailingslashit(wp_normalize_path($uploadsDirectory))) === 0
            && is_file($previousFile)) {
            wp_delete_file($previousFile);
        }
    }

    /** Profile editing requires Fluent Boards membership and remains self-service for administrators. */
    private function assertCanUpdateProfile($userId)
    {
        if (!$userId || $userId !== get_current_user_id() || !PermissionManager::isFluentBoardsUser($userId)) {
            throw new Exception(__('You can only update your own profile', 'fluent-boards'), 403);
        }
    }

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
        $per_page = Sanitize::perPage($requestData['per_page'] ?? 15, 50);
        $page = max(1, (int) ($requestData['page'] ?? 1));
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
