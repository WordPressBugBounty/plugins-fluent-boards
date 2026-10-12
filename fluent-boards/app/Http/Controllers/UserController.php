<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\MCPAccessService;
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

        $perPage = max(1, min(absint($request->getSafe('per_page', 'intval', 15)), 50));
        
        $requestData = [
            'page' => $request->getSafe('page', 'intval', 1),
            'taskType' => $request->getSafe('taskType', 'sanitize_text_field'),
            'boardIds' => $boardIds,
            'orderBy' => $request->getSafe('orderBy', 'sanitize_text_field'),
            'order' => $request->getSafe('order', 'sanitize_text_field'),
            'per_page' => $perPage
        ];
        try {
            return $this->sendSuccess(
                $this->userService->getMemberAssociatedTasks($user_id, $requestData)
                , 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    /**
     * Return profile task counts using the same categories shown on the dashboard.
     */
    public function getMemberTaskCounts(Request $request, $user_id)
    {
        $user_id = absint($user_id);
        $rawBoardIds = $request->getSafe('boardIds');
        $boardIds = is_array($rawBoardIds)
            ? array_filter(array_map('intval', $rawBoardIds))
            : [];

        try {
            return $this->sendSuccess([
                'counts' => $this->userService->getMemberTaskCounts($user_id, $boardIds),
            ], 200);
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

    public function getMemberStats($user_id)
    {
        $user_id = absint($user_id);

        return $this->sendSuccess(
            $this->userService->getMemberStats($user_id),
            200
        );
    }

    /** Update the authenticated member's display name. */
    public function updateDisplayName(Request $request, $user_id)
    {
        try {
            return $this->sendSuccess([
                'message' => __('Display name has been updated', 'fluent-boards'),
                'user' => $this->userService->updateDisplayName($user_id, $request->get('display_name')),
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => $e->getMessage()], $e->getCode() === 403 ? 403 : 400);
        }
    }

    /** Upload a profile photo for the authenticated member. */
    public function updateProfilePhoto(Request $request, $user_id)
    {
        try {
            return $this->sendSuccess([
                'message' => __('Profile photo has been updated', 'fluent-boards'),
                'user' => $this->userService->updateProfilePhoto($user_id, $_FILES['photo'] ?? []),
            ]);
        } catch (\Exception $e) {
            return $this->sendError(['message' => $e->getMessage()], $e->getCode() === 403 ? 403 : 400);
        }
    }

    public function getMcpConnections(Request $request, $id)
    {
        if (!current_user_can('list_app_passwords', (int) $id)) {
            return $this->sendError(['message' => __('You are not allowed to list application passwords.', 'fluent-boards')], 403);
        }

        return $this->sendSuccess(MCPAccessService::getProfileState((int) $id));
    }

    /**
     * Create an MCP connection key. The password is only ever in this response.
     */
    public function createMcpConnection(Request $request, $id)
    {
        $result = MCPAccessService::createConnection((int) $id, (string) $request->get('name'));

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return $this->sendError(['message' => $result->get_error_message()], $data['status'] ?? 400);
        }

        nocache_headers();

        return $this->sendSuccess($result);
    }

    public function revokeMcpConnection(Request $request, $id, $uuid)
    {
        if (!current_user_can('delete_app_password', (int) $id, $uuid)) {
            return $this->sendError(['message' => __('You are not allowed to delete this application password.', 'fluent-boards')], 403);
        }

        $result = MCPAccessService::revokeConnection((int) $id, sanitize_text_field($uuid));

        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            return $this->sendError(['message' => $result->get_error_message()], $data['status'] ?? 400);
        }

        return $this->sendSuccess([
            'message' => __('Connection revoked.', 'fluent-boards'),
        ]);
    }
}
