<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\UserService;
use FluentBoards\Framework\Http\Request\Request;
use FluentCrm\App\Models\Subscriber;


class UserController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        parent::__construct();
        $this->userService = $userService;
    }

    public function allFluentBoardsUsers()
    {
        return [
            'users'  => $this->userService->allFluentBoardsUsers(),
            'boards' => Board::select('id', 'title')->orderBy('title', 'ASC')->get()
        ];
    }

    public function memberAssociatedTaskUsers($user_id)
    {
        $user_id = absint($user_id);
        try {
            $uniqueUsers = $this->userService->memberAssociatedTaskUsers($user_id);

            return $this->sendSuccess([
                'users'                    => $uniqueUsers['uniqueUsers'],
                'userWiseBoardDesignation' => $uniqueUsers['userWiseBoardDesignation'],
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function searchFluentBoardsUser(Request $request)
    {
        try {
            $search_input = $request->getSafe('searchInput', 'sanitize_text_field', '');

            $boardUsers = $this->userService->searchFluentBoardsUser($search_input);

            return $this->sendSuccess($boardUsers, 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function searchMemberUser(Request $request, $user_id)
    {
        $user_id = absint($user_id);
        try {
            $search_input = $request->getSafe('searchInput', 'sanitize_text_field', '');
            $searchResult = $this->userService->searchMemberUser($search_input, $user_id);

            return $this->sendSuccess([
                'users' => $searchResult,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getMemberAssociatedTasks(Request $request, $user_id)
    {
        $user_id = absint($user_id);
        // Sanitize boardIds array
        $rawBoardIds = $request->getSafe('boardIds');
        $boardIds = [];
        if (is_array($rawBoardIds)) {
            $boardIds = array_filter(array_map('intval', $rawBoardIds));
        }
        
        $requestData = [
            'page' => $request->getSafe('page', 'intval', 1),
            'taskType' => $request->getSafe('taskType', 'sanitize_text_field'),
            'boardIds' => $boardIds,
            'orderBy' => $request->getSafe('orderBy', 'sanitize_text_field'),
            'order' => $request->getSafe('order', 'sanitize_text_field'),
        ];
        try {
            return $this->sendSuccess(
                $this->userService->getMemberAssociatedTasks($user_id, $requestData)
                , 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getMemberRelatedAcitivies(Request $request, $user_id)
    {
        $user_id = absint($user_id);
        $page = $request->getSafe('page', 'intval', 1);
        try {
            return $this->sendSuccess(
                $this->userService->getMemberRelatedAcitivies($user_id, $page)
                , 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getMemberInfo($user_id)
    {
        if(!PermissionManager::isFluentBoardsUser($user_id)) {
            return $this->sendError(
                [
                    'message' => __('You are not authorized to access this resource', 'fluent-boards'),
                    'code' => 'fluent_boards_unauthorized',
                    'status' => 403
                ],
                403
            );
        }

        $user_id = absint($user_id);
        $user = User::findOrFail($user_id);

        $user = Helper::sanitizeUserCollections($user);

        $user->fbs_role = PermissionManager::isFluentBoardsAdmin($user_id) ? 'fbs_admin' : 'member';

        $user->is_wp_admin = user_can($user_id, 'manage_options') ? 'yes' : 'no';

        if (defined('FLUENTCRM')) {
            $subscriber = Subscriber::where('user_id', $user_id)->first();
            $user->fluentcrm_subscriber = $subscriber ?? null;
        }

        return [
            'user' => $user
        ];
    }

    public function getMemberBoards($user_id)
    {
        $user_id = absint($user_id);
        try {
            return $this->sendSuccess(
                [
                    'boards' => $this->userService->getMemberBoards($user_id)
                ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updateDisplayName(Request $request, $user_id)
    {
        $user_id = absint($user_id);
        $currentUserId = get_current_user_id();

        if ($currentUserId !== $user_id && !PermissionManager::isAdmin($currentUserId)) {
            return $this->sendError(
                __('You do not have permission to update this display name', 'fluent-boards'),
                403
            );
        }

        $displayName = $request->getSafe('display_name', 'sanitize_text_field');

        if(!$displayName) {
            return $this->sendError('Display name is required', 400);
        }

        $updateResult = wp_update_user([
            'ID' => $user_id,
            'display_name' => $displayName,
        ]);

        if (is_wp_error($updateResult)) {
        return $this->sendError(
            $updateResult->get_error_message(),
            400
        );
        }

        $user = User::findOrFail($user_id);
        $user = Helper::sanitizeUserCollections($user);
        $user->fbs_role = PermissionManager::isFluentBoardsAdmin($user_id) ? 'fbs_admin' : 'member';
        $user->is_wp_admin = user_can($user_id, 'manage_options') ? 'yes' : 'no';

        if (defined('FLUENTCRM')) {
        $subscriber = Subscriber::where('user_id', $user_id)->first();
        $user->fluentcrm_subscriber = $subscriber ?? null;
        }

        return $this->sendSuccess([
            'message' => __('Display name has been updated', 'fluent-boards'),
            'user'    => $user,
        ], 200);

    }

    public function updateProfilePhoto(Request $request, $user_id)
{
    $user_id = absint($user_id);
    $currentUserId = get_current_user_id();

    // Only the user themself OR an admin can change the profile picture
    if ($currentUserId !== $user_id && !PermissionManager::isAdmin($currentUserId)) {
        return $this->sendError(
            __('You do not have permission to update this profile photo', 'fluent-boards'),
            403
        );
    }

    // We’ll use native WordPress upload handling
    if (empty($_FILES['photo']) || !empty($_FILES['photo']['error'])) {
        return $this->sendError(
            __('No photo uploaded or upload error', 'fluent-boards'),
            400
        );
    }

    // Limit file size to 2MB for profile photos
    $maxSize = 2 * 1024 * 1024;
    if ($_FILES['photo']['size'] > $maxSize) {
        return $this->sendError(
            __('Photo must be under 2MB', 'fluent-boards'),
            400
        );
    }

    $file = $_FILES['photo'];

    // Validate MIME type server-side (client-sent type is spoofable)
    $fileType = wp_check_filetype($file['name'], [
        'jpg|jpeg|jpe' => 'image/jpeg',
        'gif'          => 'image/gif',
        'png'          => 'image/png',
        'webp'         => 'image/webp',
    ]);

    if (!$fileType['type']) {
        return $this->sendError(
            __('Invalid image type', 'fluent-boards'),
            400
        );
    }

    // Load WordPress upload helpers
    if (!function_exists('wp_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $overrides = [
        'test_form' => false,
        'mimes'     => [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'gif'          => 'image/gif',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        ],
    ];

    $uploaded = wp_handle_upload($file, $overrides);

    if (isset($uploaded['error'])) {
        return $this->sendError(
            $uploaded['error'],
            400
        );
    }

    $photoUrl = esc_url_raw($uploaded['url']);

    // Store custom profile photo in user meta
    update_user_meta($user_id, 'fbs_profile_photo', $photoUrl);

    // Reload user and sanitize same as in getMemberInfo()
    $user = User::findOrFail($user_id);
    $user = Helper::sanitizeUserCollections($user);

    // Override photo field if your sanitizer does not already use the meta
    $user->photo = $photoUrl;

    $user->fbs_role = PermissionManager::isFluentBoardsAdmin($user_id) ? 'fbs_admin' : 'member';
    $user->is_wp_admin = user_can($user_id, 'manage_options') ? 'yes' : 'no';

    if (defined('FLUENTCRM')) {
        $subscriber = Subscriber::where('user_id', $user_id)->first();
        $user->fluentcrm_subscriber = $subscriber ?? null;
    }

    return $this->sendSuccess([
        'message'   => __('Profile photo has been updated', 'fluent-boards'),
        'photo_url' => $photoUrl,
        'user'      => $user,
    ], 200);
}
}
