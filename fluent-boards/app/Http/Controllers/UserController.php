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
}
