<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\NotificationService;
use FluentBoards\Framework\Http\Request\Request;

class NotificationController extends Controller
{
    private $notificationService;

    public function __construct(NotificationService  $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }
    public function getAllNotifications(Request $request)
    {
        try {
            $per_page = $request->getSafe('per_page', 'intval', 20);
            $page = $request->getSafe('page', 'intval', 1);
            $action = $request->getSafe('action', 'sanitize_text_field', 'all');
            $data = $this->notificationService->getAllNotifications($per_page, $page, $action);

            return $this->sendSuccess($data, 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
    public function getAllUnreadNotifications(Request $request)
    {
        try {
            $per_page = $request->getSafe('per_page', 'intval', 20);
            $page = $request->getSafe('page', 'intval', 1);
            return $this->sendSuccess(
                $this->notificationService->getAllUnreadNotifications($per_page, $page),
                200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function newNotificationNumber()
    {
        try {
            $unread_notifications = $this->notificationService->newNotificationNumber();

            return $this->sendSuccess([
                'total_unread' => $unread_notifications,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getBoardNotificationSettings($board_id)
    {
        try {
            $userId = get_current_user_id();
            $currentSettings = [];
            $boardSettings = $this->notificationService->getBoardNotificationSettingsOfUser($board_id, $userId);
            if ($boardSettings && $boardSettings->preferences) {
                $unserializedData = maybe_unserialize($boardSettings->preferences);
                // Validate unserialized data is an array
                if (is_array($unserializedData)) {
                    $currentSettings = $unserializedData;
                }
            }

            return $this->sendSuccess([
                'currentSettings' => $currentSettings,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function updateBoardNotificationSettings(Request $request, $board_id)
    {
        try {
            $newSettings = $request->getSafe('updatedSettings', 'sanitize_text_field');
            $this->notificationService->updateBoardNotificationSettings($newSettings, $board_id);
            return $this->sendSuccess([
                'message' => __('Board notification settings have been updated', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function readNotification(Request $request)
    {
        try {
            $notificationId = $request->getSafe('notification_id');

            if ($notificationId) {
                $notification = $this->notificationService->markNotificationRead($notificationId);
            } else {
                $notification = $this->notificationService->markAllRead();
            }

            return $this->sendSuccess([
                'message' => __('Notification is updated', 'fluent-boards'),
                'notification' => $notification
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
}