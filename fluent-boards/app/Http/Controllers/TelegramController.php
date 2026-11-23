<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\TelegramService;
use RuntimeException;

/** Admin bot configuration and self-service account connections. */
class TelegramController extends Controller
{
    public function settings()
    {
        return $this->sendSuccess(['settings' => (new TelegramService())->displaySettings()]);
    }

    public function saveSettings()
    {
        try {
            return $this->sendSuccess(['settings' => (new TelegramService())->saveSettings([
                'digest_minutes' => $this->request->get('digest_minutes'),
                'token' => $this->request->get('token', ''),
                'enabled' => $this->request->get('enabled', false),
            ])]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function profile()
    {
        return $this->sendSuccess(['profile' => (new TelegramService())->profile(get_current_user_id())]);
    }

    public function connect()
    {
        try {
            return $this->sendSuccess((new TelegramService())->beginConnection(get_current_user_id()));
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function check()
    {
        try {
            return $this->sendSuccess(['profile' => (new TelegramService())->checkConnection(get_current_user_id())]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function disconnect()
    {
        return $this->sendSuccess(['profile' => (new TelegramService())->disconnect(get_current_user_id())]);
    }

    public function preference()
    {
        try {
            return $this->sendSuccess(['profile' => (new TelegramService())->updatePreference(get_current_user_id(), $this->request->get('enabled', false))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function preferences()
    {
        try {
            return $this->sendSuccess(['profile' => (new TelegramService())->updateEventPreferences(get_current_user_id(), $this->request->get('preferences', []))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function test()
    {
        try {
            (new TelegramService())->sendTest(get_current_user_id());
            return $this->sendSuccess(['message' => __('Test notification sent to Telegram.', 'fluent-boards')]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function boardPreferences($board_id)
    {
        return $this->sendSuccess(['settings' => (new TelegramService())->boardPreferences(get_current_user_id(), absint($board_id))]);
    }

    public function saveBoardPreferences($board_id)
    {
        try {
            return $this->sendSuccess(['settings' => (new TelegramService())->updateBoardPreferences(
                get_current_user_id(),
                absint($board_id),
                $this->request->get('same_as_global', false),
                $this->request->get('preferences', [])
            )]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }
}
