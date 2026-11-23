<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\SlackService;
use RuntimeException;

/** Admin Slack app configuration and self-service account connections. */
class SlackController extends Controller
{
    public function settings()
    {
        return $this->sendSuccess(['settings' => (new SlackService())->displaySettings()]);
    }

    public function saveSettings()
    {
        try {
            return $this->sendSuccess(['settings' => (new SlackService())->saveSettings([
                'enabled' => $this->request->get('enabled', false),
                'client_id' => $this->request->get('client_id', ''),
                'bot_token' => $this->request->get('bot_token', ''),
                'client_secret' => $this->request->get('client_secret', ''),
                'digest_minutes' => $this->request->get('digest_minutes', null),
            ])]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function profile()
    {
        return $this->sendSuccess(['profile' => (new SlackService())->profile(get_current_user_id())]);
    }

    public function connect()
    {
        try {
            return $this->sendSuccess((new SlackService())->beginConnection(get_current_user_id()));
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function disconnect()
    {
        try {
            return $this->sendSuccess(['profile' => (new SlackService())->disconnect(get_current_user_id())]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 503);
        }
    }

    public function preference()
    {
        try {
            return $this->sendSuccess(['profile' => (new SlackService())->updatePreference(get_current_user_id(), $this->request->get('enabled', false))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function preferences()
    {
        try {
            return $this->sendSuccess(['profile' => (new SlackService())->updateEventPreferences(get_current_user_id(), $this->request->get('preferences', []))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function test()
    {
        try {
            (new SlackService())->sendTest(get_current_user_id());
            return $this->sendSuccess(['message' => __('Test notification sent to Slack.', 'fluent-boards')]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function boardPreferences($board_id)
    {
        return $this->sendSuccess(['settings' => (new SlackService())->boardPreferences(get_current_user_id(), absint($board_id))]);
    }

    public function saveBoardPreferences($board_id)
    {
        try {
            return $this->sendSuccess(['settings' => (new SlackService())->updateBoardPreferences(
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
