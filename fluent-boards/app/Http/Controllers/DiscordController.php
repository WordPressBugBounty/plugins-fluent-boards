<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Services\DiscordService;
use RuntimeException;

/** Admin Discord app configuration and self-service account connections. */
class DiscordController extends Controller
{
    public function settings()
    {
        return $this->sendSuccess(['settings' => (new DiscordService())->displaySettings()]);
    }

    public function saveSettings()
    {
        try {
            return $this->sendSuccess(['settings' => (new DiscordService())->saveSettings([
                'enabled' => $this->request->get('enabled', false),
                'application_id' => $this->request->get('application_id', ''),
                'guild_id' => $this->request->get('guild_id', ''),
                'bot_token' => $this->request->get('bot_token', ''),
                'client_secret' => $this->request->get('client_secret', ''),
                'digest_minutes' => $this->request->get('digest_minutes', null),
            ])]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], $exception->getCode() === 409 ? 409 : 422);
        }
    }

    public function profile()
    {
        return $this->sendSuccess(['profile' => (new DiscordService())->profile(get_current_user_id())]);
    }

    public function connect()
    {
        try {
            return $this->sendSuccess((new DiscordService())->beginConnection(get_current_user_id(), $this->request->get('guild_id')));
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function disconnect()
    {
        try {
            return $this->sendSuccess(['profile' => (new DiscordService())->disconnect(get_current_user_id())]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 409);
        }
    }

    public function preference()
    {
        try {
            return $this->sendSuccess(['profile' => (new DiscordService())->updatePreference(get_current_user_id(), $this->request->get('enabled', false))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function preferences()
    {
        try {
            return $this->sendSuccess(['profile' => (new DiscordService())->updateEventPreferences(get_current_user_id(), $this->request->get('preferences', []))]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function test()
    {
        try {
            (new DiscordService())->sendTest(get_current_user_id());
            return $this->sendSuccess(['message' => __('Test notification sent to Discord.', 'fluent-boards')]);
        } catch (RuntimeException $exception) {
            return $this->sendError(['message' => $exception->getMessage()], 422);
        }
    }

    public function boardPreferences($board_id)
    {
        return $this->sendSuccess(['settings' => (new DiscordService())->boardPreferences(get_current_user_id(), absint($board_id))]);
    }

    public function saveBoardPreferences($board_id)
    {
        try {
            return $this->sendSuccess(['settings' => (new DiscordService())->updateBoardPreferences(
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
