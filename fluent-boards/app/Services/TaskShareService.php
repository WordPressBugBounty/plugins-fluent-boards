<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;

/**
 * Task share links: a per-task bearer link that shows one task read-only to anyone holding it,
 * independent of WordPress accounts and of the board's public view.
 *
 * The link token is "{task_code}.{secret}". The task code is the base64url-encoded task id, so the
 * URL doesn't show the id while the lookup stays an indexed read; the secret (random, stored in
 * task meta) is what grants access and is compared in constant time.
 */
class TaskShareService
{
    const TOKEN_META = 'share_token';

    const SETTINGS_META = 'share_settings';

    const SECRET_LENGTH = 40;

    /** What a share link shows by default: the task card (without assignees) plus its description. */
    const DEFAULT_SETTINGS = [
        'show_assignees'   => false,
        'show_labels'      => true,
        'show_due_dates'   => true,
        'show_priority'    => true,
        'task_description' => true,
        'task_subtasks'    => false,
        'task_attachments' => false,
        'task_comments'    => false,
    ];

    /**
     * Current share state for the admin UI: enabled flag, URL and settings.
     */
    public function getShare(Task $task)
    {
        $secret = (string)$task->getMeta(self::TOKEN_META, '');
        $storedSettings = $task->getMeta(self::SETTINGS_META);

        return [
            'enabled'  => $secret !== '',
            'url'      => $secret !== '' ? $this->shareUrl($task, $secret) : '',
            'settings' => $this->normalizeSettings($storedSettings),
        ];
    }

    /**
     * Save share settings, creating the link if the task has none. An existing secret is kept,
     * so changing what is shown never breaks a link that was already sent.
     */
    public function saveShare(Task $task, $input)
    {
        if (!$task->getMeta(self::TOKEN_META)) {
            $secret = wp_generate_password(self::SECRET_LENGTH, false, false);
            $task->updateMeta(self::TOKEN_META, $secret);
        }

        $settings = $this->normalizeSettings($input);
        $task->updateMeta(self::SETTINGS_META, $settings);

        do_action('fluent_boards/task_share_link_updated', $task, $settings);

        return $this->getShare($task);
    }

    /**
     * Disable the link. Sharing again generates a new secret, so the old URL never works again.
     */
    public function revokeShare(Task $task)
    {
        TaskMeta::where('task_id', $task->id)
            ->whereIn('key', [self::TOKEN_META, self::SETTINGS_META])
            ->delete();

        do_action('fluent_boards/task_share_link_revoked', $task);

        return $this->getShare($task);
    }

    /**
     * Resolve a link token to ['task' => Task, 'settings' => array], or null when the token is
     * malformed or unknown, or the task or its board is archived or deleted.
     */
    public function findSharedTask($token)
    {
        $token = is_string($token) ? $token : '';
        if (!preg_match('/^([A-Za-z0-9_-]{1,16})\.([A-Za-z0-9]{' . self::SECRET_LENGTH . '})$/', $token, $matches)) {
            return null;
        }

        $taskId = $this->decodeTaskId($matches[1]);
        if (!$taskId) {
            return null;
        }

        $task = Task::where('id', $taskId)->whereNull('archived_at')->first();
        if (!$task) {
            return null;
        }

        $secret = (string)$task->getMeta(self::TOKEN_META, '');
        if ($secret === '' || !hash_equals($secret, $matches[2])) {
            return null;
        }

        $board = Board::find($task->board_id);
        if (!$board || $board->archived_at) {
            return null;
        }

        $stage = $task->stage_id ? Stage::find($task->stage_id) : null;
        if ($stage && $stage->archived_at) {
            return null;
        }

        $storedSettings = $task->getMeta(self::SETTINGS_META);

        return [
            'task'     => $task,
            'settings' => $this->normalizeSettings($storedSettings),
        ];
    }

    /**
     * Whether the task's active share link exposes the given section (e.g. task_comments).
     */
    public function isTaskFeatureVisible(Task $task, $feature)
    {
        $secret = (string)$task->getMeta(self::TOKEN_META, '');
        if ($secret === '') {
            return false;
        }

        $token = $this->buildToken($task, $secret);
        $shared = $this->findSharedTask($token);

        return $shared && !empty($shared['settings'][$feature]);
    }

    private function shareUrl(Task $task, $secret)
    {
        $token = $this->buildToken($task, $secret);

        return add_query_arg([
            'fbs'             => 1,
            'fbs_shared_task' => $token,
        ], site_url('/'));
    }

    private function buildToken(Task $task, $secret)
    {
        $taskCode = $this->encodeTaskId($task->id);

        return $taskCode . '.' . $secret;
    }

    /** Task id -> base64url code, only to keep the raw id out of the URL. */
    private function encodeTaskId($taskId)
    {
        $encoded = base64_encode((string)(int)$taskId);

        return rtrim(strtr($encoded, '+/', '-_'), '=');
    }

    /** Back to the task id; anything that is not the canonical code of a positive id is rejected. */
    private function decodeTaskId($taskCode)
    {
        $decoded = base64_decode(strtr($taskCode, '-_', '+/'), true);
        if ($decoded === false || !ctype_digit($decoded)) {
            return 0;
        }

        $taskId = (int)$decoded;
        if ($taskId < 1 || $this->encodeTaskId($taskId) !== $taskCode) {
            return 0;
        }

        return $taskId;
    }

    private function normalizeSettings($input)
    {
        $input = is_array($input) ? $input : [];
        $settings = [];

        foreach (self::DEFAULT_SETTINGS as $key => $default) {
            $value = array_key_exists($key, $input) ? $input[$key] : $default;
            $settings[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $settings;
    }
}
