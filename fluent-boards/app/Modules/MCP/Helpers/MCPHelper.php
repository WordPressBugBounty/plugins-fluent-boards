<?php

namespace FluentBoards\App\Modules\MCP\Helpers;

use DateTimeImmutable;
use Exception;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\DescriptionMarkdownConverter;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;

/**
 * Shared utilities for Fluent Boards MCP tools.
 */
class MCPHelper
{
    const TASK_HISTORY_LIMIT = 20;
    const MAX_MARKDOWN_DESCRIPTION_LENGTH = 65535;
    const BOARD_TASKS_LIMIT = 100;

    /**
     * Warnings raised by the tool that is running. The execute wrapper moves them to
     * meta.warnings of the result.
     *
     * @var string[]
     */
    private static $warnings = [];

    /**
     * Build a tool error. The MCP adapter sends only the message of a WP_Error to the
     * client, so the message is the JSON error envelope:
     * {"error": {"code", "message", "retryable", "fields", "allowed"?, "details"?}}.
     *
     * Plain English, not translated: an AI model reads it.
     *
     * @param string $code    invalid_param, unknown_param, not_found, forbidden, ...
     * @param string $message What to change. Name the parameter and the allowed values.
     * @param array  $data    Optional. `fields` (parameter names at fault), `allowed`,
     *                        `retryable`; every other key goes under `details`.
     * @return \WP_Error
     */
    public static function error($code, $message, $data = [])
    {
        $data = (array) $data;

        $payload = [
            'code'      => (string) $code,
            'message'   => (string) $message,
            'retryable' => !empty($data['retryable']),
            'fields'    => array_values(array_map('strval', (array) ($data['fields'] ?? []))),
        ];

        if (isset($data['allowed'])) {
            $payload['allowed'] = array_values((array) $data['allowed']);
        }

        unset($data['retryable'], $data['fields'], $data['allowed']);

        if ($data) {
            $payload['details'] = $data;
        }

        $json = wp_json_encode(['error' => $payload]);

        return new \WP_Error((string) $code, $json ? $json : (string) $message, $payload);
    }

    /**
     * A wrong, missing or unreadable value of one parameter.
     *
     * @param string|string[] $fields  Parameter name or names.
     * @param string          $message What to change.
     * @param array           $data    Extra error data, see error().
     * @return \WP_Error
     */
    public static function invalid($fields, $message, $data = [])
    {
        return self::error('invalid_param', $message, ['fields' => (array) $fields] + (array) $data);
    }

    /**
     * A value that is not in a fixed list.
     *
     * @param string $field
     * @param array  $allowed
     * @return \WP_Error
     */
    public static function notOneOf($field, $allowed)
    {
        $names = array_map(function ($value) {
            return $value === '' ? '"" (empty)' : (string) $value;
        }, array_values((array) $allowed));

        return self::invalid($field, $field . ' is not one of ' . implode(', ', $names) . '.', [
            'allowed' => array_values((array) $allowed),
        ]);
    }

    /**
     * The value a delete tool hands out in its first call and asks back in the second.
     *
     * It is bound to the tool, the record, the user and a fingerprint of what will be
     * deleted, so it cannot be guessed, reused for another record, or used after the record
     * changed. It needs no storage. A value is valid for 15 to 30 minutes.
     *
     * @param string $action      Tool name.
     * @param int    $id          Record id.
     * @param string $fingerprint What the preview showed, for example the task count.
     * @param int    $windowsBack 0 for the current 15-minute window, 1 for the one before.
     */
    public static function confirmValue($action, $id, $fingerprint, $windowsBack = 0)
    {
        $window = (int) floor(time() / (15 * MINUTE_IN_SECONDS)) - (int) $windowsBack;

        return substr(hash_hmac(
            'sha256',
            implode('|', [$action, (int) $id, get_current_user_id(), (string) $fingerprint, $window]),
            wp_salt('auth')
        ), 0, 10);
    }

    /**
     * @param mixed $given The confirm parameter of the second call.
     * @return bool
     */
    public static function confirmMatches($given, $action, $id, $fingerprint)
    {
        if (!is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::confirmValue($action, $id, $fingerprint), $given)
            || hash_equals(self::confirmValue($action, $id, $fingerprint, 1), $given);
    }

    /**
     * The first-call result of a delete tool: nothing is deleted yet.
     *
     * @param string $action     Tool name, without the namespace.
     * @param array  $willDelete What the second call removes.
     * @param string $confirm    The value to send back.
     * @param string $instead    The reversible choice, or ''.
     */
    public static function confirmationNeeded($action, $willDelete, $confirm, $instead = '')
    {
        return [
            'deleted'            => false,
            'needs_confirmation' => true,
            'will_delete'        => $willDelete,
            'confirm'            => $confirm,
            'next'               => 'Nothing is deleted yet. Show will_delete to the user. Only after the user agrees, call '
                . $action . ' again with the same ids and this confirm value (valid for 15 minutes). This cannot be undone.'
                . ($instead !== '' ? ' ' . $instead : ''),
        ];
    }

    public static function confirmInvalid($action)
    {
        return self::invalid('confirm', 'confirm is not the value ' . $action . ' gave for this record: it expired, or the record changed since. Call ' . $action . ' without confirm to see what will be deleted and get a new value.');
    }

    /**
     * Linking a FluentCRM contact needs the right to read contacts. The services skip the
     * link or throw when it is missing; checked first, so the call is refused before any
     * write.
     *
     * @return true|\WP_Error
     */
    public static function assertCanLinkContact()
    {
        if (Helper::canViewCrmContacts()) {
            return true;
        }

        return self::error('forbidden', 'You cannot link a FluentCRM contact: FluentCRM is not active, or your user may not read contacts. Leave crm_contact_id out.', [
            'fields' => ['crm_contact_id'],
        ]);
    }

    public static function addWarning($message)
    {
        self::$warnings[] = (string) $message;
    }

    /**
     * Return the warnings and clear them.
     *
     * @return string[]
     */
    public static function takeWarnings()
    {
        $warnings = self::$warnings;
        self::$warnings = [];

        return array_values(array_unique($warnings));
    }

    public static function normalizePagination($params, $defaultPerPage = 20, $maxPerPage = 100)
    {
        $page = isset($params['page']) ? absint($params['page']) : 1;
        $perPage = isset($params['per_page']) ? absint($params['per_page']) : $defaultPerPage;

        if ($perPage < 1) {
            $perPage = $defaultPerPage;
        }

        if ($perPage > $maxPerPage) {
            self::addWarning(sprintf(
                'per_page %d is above the maximum; %d was used. Read the other pages with page.',
                $perPage,
                $maxPerPage
            ));
            $perPage = $maxPerPage;
        }

        return [
            'page'     => max(1, $page),
            'per_page' => $perPage,
        ];
    }

    public static function paginationMeta($total, $page, $perPage)
    {
        $total = (int) $total;
        $perPage = max(1, (int) $perPage);

        return [
            'total'        => $total,
            'current_page' => (int) $page,
            'per_page'     => $perPage,
            'last_page'    => (int) ceil($total / $perPage),
        ];
    }

    /**
     * The site timezone as a name an agent can read: "Asia/Dhaka", or "+06:00" when the
     * site uses a fixed offset.
     */
    public static function siteTimezoneName()
    {
        return wp_timezone_string();
    }

    /**
     * Read a date an agent sent. Accepts only a written date or date and time:
     * YYYY-MM-DD, YYYY-MM-DD HH:MM[:SS], or the same with an offset or Z.
     *
     * PHP's date parser also reads words ("Tuesday 10:00" is today at 10:00, "x" is a
     * timezone), so the format is checked before the value reaches it.
     *
     * A value without an offset is site time. A value with an offset is converted to
     * site time, because the tables store site wall time.
     *
     * @param mixed  $value
     * @param string $param Parameter name, for the error.
     * @return string|\WP_Error "Y-m-d H:i:s" in site time.
     */
    public static function parseDate($value, $param)
    {
        $raw = is_string($value) ? trim($value) : '';

        $withOffset = (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $raw);
        $withoutOffset = (bool) preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $raw);

        if (!$withOffset && !$withoutOffset) {
            return self::dateError($param, $value);
        }

        // DateTime rolls an impossible date or time over to the next one, so check the range.
        preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $raw, $part);
        $hour = isset($part[4]) ? (int) $part[4] : 0;
        $minute = isset($part[5]) ? (int) $part[5] : 0;
        $second = isset($part[6]) ? (int) $part[6] : 0;

        if (!checkdate((int) $part[2], (int) $part[3], (int) $part[1]) || $hour > 23 || $minute > 59 || $second > 59) {
            return self::dateError($param, $value);
        }

        // The date columns are MySQL TIMESTAMP: they hold 1970-01-01 to 2038-01-19. A date
        // outside that range fails in the database, or is stored as zero.
        if ((int) $part[1] < 1971 || (int) $part[1] > 2037) {
            return self::invalid($param, sprintf('%s must be a date from 1971 to 2037. Fluent Boards cannot store a date outside that range.', $param), [
                'received' => $raw,
            ]);
        }

        $siteTimezone = wp_timezone();

        try {
            $date = $withOffset ? new DateTimeImmutable($raw) : new DateTimeImmutable($raw, $siteTimezone);
        } catch (Exception $e) {
            return self::dateError($param, $value);
        }

        return $date->setTimezone($siteTimezone)->format('Y-m-d H:i:s');
    }

    private static function dateError($param, $value)
    {
        return self::invalid($param, sprintf(
            '%s is not a date. Send YYYY-MM-DD or YYYY-MM-DD HH:MM:SS; without an offset it is read in the site timezone (%s). Words such as "tomorrow" or "Friday" are refused: work out the date from site.current_time in get-fluentboards-context.',
            $param,
            self::siteTimezoneName()
        ), [
            'received' => is_scalar($value) ? (string) $value : gettype($value),
        ]);
    }

    /**
     * Read due_at / started_at of a write call.
     *
     * @param array $params      Tool input.
     * @param bool  $allowClear  True on update: an empty string clears the date.
     * @return array|\WP_Error   Only the keys that were sent. A cleared date is null.
     */
    public static function readTaskDates($params, $allowClear = false)
    {
        $dates = [];

        foreach (['started_at', 'due_at'] as $field) {
            if (!array_key_exists($field, $params)) {
                continue;
            }

            $value = $params[$field];

            if ($value === '' || $value === null) {
                if ($allowClear) {
                    $dates[$field] = null;
                }
                continue;
            }

            $parsed = self::parseDate($value, $field);
            if (is_wp_error($parsed)) {
                return $parsed;
            }

            $dates[$field] = $parsed;
        }

        return $dates;
    }

    /**
     * Read a from/to pair of date filters. A "to" date without a time includes that whole
     * day. A reversed range is refused.
     *
     * @param array  $params
     * @param string $fromKey
     * @param string $toKey
     * @return array|\WP_Error ['from' => "Y-m-d H:i:s"|null, 'to' => "Y-m-d H:i:s"|null]
     */
    public static function readDateRange($params, $fromKey, $toKey)
    {
        $range = ['from' => null, 'to' => null];

        foreach (['from' => $fromKey, 'to' => $toKey] as $side => $key) {
            if (!isset($params[$key]) || $params[$key] === '') {
                continue;
            }

            $parsed = self::parseDate($params[$key], $key);
            if (is_wp_error($parsed)) {
                return $parsed;
            }

            if ($side === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $params[$key]))) {
                $parsed = substr($parsed, 0, 10) . ' 23:59:59';
            }

            $range[$side] = $parsed;
        }

        if ($range['from'] && $range['to'] && strcmp($range['from'], $range['to']) > 0) {
            return self::invalid([$fromKey, $toKey], sprintf(
                '%s (%s) is after %s (%s). Swap them.',
                $fromKey,
                self::toIso8601($range['from']),
                $toKey,
                self::toIso8601($range['to'])
            ));
        }

        return $range;
    }

    /**
     * Refuse a start date after the due date.
     *
     * @param string|null $startedAt "Y-m-d H:i:s" or null.
     * @param string|null $dueAt     "Y-m-d H:i:s" or null.
     * @return true|\WP_Error
     */
    public static function assertDateOrder($startedAt, $dueAt)
    {
        if ($startedAt && $dueAt && strcmp(substr((string) $startedAt, 0, 19), substr((string) $dueAt, 0, 19)) > 0) {
            return self::invalid(['started_at', 'due_at'], sprintf(
                'started_at (%s) is after due_at (%s). Send both dates in one call with started_at on or before due_at.',
                self::toIso8601($startedAt),
                self::toIso8601($dueAt)
            ));
        }

        return true;
    }

    public static function sanitizeMarkdown($value)
    {
        $value = wp_check_invalid_utf8((string) $value);
        $value = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ""], $value);
        $value = self::descriptionToMarkdown($value);

        if (strlen($value) <= self::MAX_MARKDOWN_DESCRIPTION_LENGTH) {
            return $value;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($value, 0, self::MAX_MARKDOWN_DESCRIPTION_LENGTH);
        }

        return substr($value, 0, self::MAX_MARKDOWN_DESCRIPTION_LENGTH);
    }

    public static function resolveBoard($params)
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        if (!$boardId) {
            return self::invalid('board_id', 'board_id is required: the id of a board from list-boards.');
        }

        $board = Board::with(['stages', 'labels', 'users'])->find($boardId);
        if (!$board) {
            return self::error('not_found', sprintf('No board has id %d. list-boards shows the boards you can use.', $boardId), [
                'fields' => ['board_id'],
            ]);
        }

        return $board;
    }

    public static function resolveTask($params)
    {
        $taskId = isset($params['task_id']) ? absint($params['task_id']) : 0;
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;

        if (!$taskId || !$boardId) {
            return self::invalid(
                array_values(array_filter([$boardId ? '' : 'board_id', $taskId ? '' : 'task_id'])),
                'board_id and task_id are both required. search-tasks and list-tasks return both ids.'
            );
        }

        $task = Task::with(self::taskDetailRelations())->find($taskId);
        if (!$task || (int) $task->board_id !== $boardId) {
            return self::taskNotFound($taskId, $boardId);
        }

        return $task;
    }

    /**
     * The task does not exist, or it is on another board. Both give the same error, so the
     * call does not show whether a task exists on a board the caller cannot read.
     */
    public static function taskNotFound($taskId, $boardId)
    {
        return self::error('not_found', sprintf(
            'Board %d has no task with id %d. Find the task and its board_id with search-tasks (query "id:%d").',
            (int) $boardId,
            (int) $taskId,
            (int) $taskId
        ), ['fields' => ['task_id', 'board_id']]);
    }

    /**
     * The caller may not read, or may not change, this board.
     *
     * @param int  $boardId
     * @param bool $write True when the call changes data.
     */
    public static function boardForbidden($boardId, $write = false)
    {
        if ($write && self::canReadBoard($boardId)) {
            return self::error('forbidden', sprintf(
                'You can read board %d but your role on it does not allow changes. Ask a board manager for a role that can edit.',
                (int) $boardId
            ), ['fields' => ['board_id']]);
        }

        return self::error('forbidden', sprintf(
            'You do not have access to board %d, or it does not exist. list-boards shows the boards you can use.',
            (int) $boardId
        ), ['fields' => ['board_id']]);
    }

    public static function loadTaskDetails($task)
    {
        return $task->load(self::taskDetailRelations());
    }

    public static function taskDetailRelations()
    {
        return [
            'board',
            'stage',
            'labels',
            'assignees',
            'watchers',
            'comments'   => function ($query) {
                $query->without(['images', 'replies'])
                    ->orderBy('id', 'DESC')
                    ->limit(self::TASK_HISTORY_LIMIT);
            },
            'activities' => function ($query) {
                $query->limit(self::TASK_HISTORY_LIMIT);
            },
        ];
    }

    public static function canReadBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission(absint($boardId), 'GET');
    }

    public static function canWriteBoard($boardId)
    {
        return PermissionManager::userHasBoardPermission(absint($boardId), 'POST');
    }

    public static function assertStageBelongsToBoard($stageId, $boardId, $field = 'stage_id')
    {
        if (!absint($stageId)) {
            return self::invalid($field, $field . ' is required: the id of a stage on the board. get-board lists the stages.');
        }

        $stage = Stage::where('id', absint($stageId))
            ->where('board_id', absint($boardId))
            ->whereNull('archived_at')
            ->first();

        if (!$stage) {
            return self::stageNotFound($stageId, $boardId, $field);
        }

        return $stage;
    }

    public static function stageNotFound($stageId, $boardId, $field = 'stage_id')
    {
        $stages = [];
        foreach (Stage::where('board_id', absint($boardId))->whereNull('archived_at')->orderBy('position', 'asc')->get() as $stage) {
            $stages[] = ['id' => (int) $stage->id, 'title' => $stage->title];
        }

        return self::error('not_found', sprintf(
            'Board %d has no active stage with id %d. Use a stage id from details.stages.',
            absint($boardId),
            absint($stageId)
        ), ['fields' => [$field], 'stages' => $stages]);
    }

    /**
     * One board in a list. Empty values are left out.
     */
    public static function formatBoardSummary($board)
    {
        return self::withoutEmpty([
            'id'            => (int) $board->id,
            'title'         => $board->title,
            'type'          => $board->type,
            'description'   => self::descriptionToMarkdown($board->description),
            'stages_count'  => isset($board->stages) ? count($board->stages) : 0,
            'labels_count'  => isset($board->labels) ? count($board->labels) : 0,
            'members_count' => isset($board->users) ? count($board->users) : 0,
            'updated_at'    => self::toIso8601($board->updated_at),
            'archived_at'   => self::toIso8601($board->archived_at),
        ], ['stages_count', 'labels_count', 'members_count']);
    }

    public static function formatBoard($board, $includeTasks = false)
    {
        $data = self::formatBoardSummary($board);
        unset($data['stages_count'], $data['labels_count'], $data['members_count']);

        $data['currency'] = $board->currency;
        $data['created_at'] = self::toIso8601($board->created_at);
        $data['stages'] = self::formatStageList($board->stages ?? []);
        $data['labels'] = self::formatLabelList($board->labels ?? []);
        $data['members'] = self::formatUserList($board->users ?? [], $board->id);

        if ($includeTasks) {
            $total = (int) Task::where('board_id', $board->id)
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->count();

            $tasks = Task::with(['stage', 'labels', 'assignees'])
                ->where('board_id', $board->id)
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->orderBy('stage_id', 'asc')
                ->orderBy('position', 'asc')
                ->limit(self::BOARD_TASKS_LIMIT)
                ->get();

            $data['tasks'] = self::formatTaskList($tasks);
            $data['tasks_total'] = $total;

            if ($total > self::BOARD_TASKS_LIMIT) {
                self::addWarning(sprintf(
                    'tasks holds the first %d of %d active tasks. Read the rest with list-tasks (page, per_page) and its filters.',
                    self::BOARD_TASKS_LIMIT,
                    $total
                ));
            }
        }

        return $data;
    }

    /**
     * One task in a list or in a write result. Small on purpose: get-task returns the
     * description, comments and watchers. Empty values are left out.
     *
     * Accepts a Task model or the plain array UserService returns.
     *
     * @param mixed $task
     * @param bool  $withBoardTitle Add the board title, for lists that cross boards.
     */
    public static function formatTaskRow($task, $withBoardTitle = false)
    {
        $get = function ($key) use ($task) {
            if (is_array($task)) {
                return $task[$key] ?? null;
            }
            return $task->{$key} ?? null;
        };

        $nested = function ($value, $key) {
            if (is_array($value)) {
                return $value[$key] ?? null;
            }
            if (is_object($value)) {
                return $value->{$key} ?? null;
            }
            return null;
        };

        $labels = [];
        foreach (($get('labels') ?: []) as $label) {
            $title = (string) $nested($label, 'title');
            // A label without a title is a colour only; its colour names it.
            $labels[] = $title !== '' ? $title : (string) $nested($label, 'bg_color');
        }

        $assignees = [];
        foreach (($get('assignees') ?: []) as $user) {
            $name = trim((string) $nested($user, 'display_name'));
            if ($name === '') {
                $name = trim((string) $nested($user, 'first_name') . ' ' . (string) $nested($user, 'last_name'));
            }
            $assignees[] = [
                'id'   => (int) ($nested($user, 'ID') ?: $nested($user, 'id')),
                'name' => $name,
            ];
        }

        $row = [
            'id'       => (int) $get('id'),
            'title'    => $get('title'),
            'board_id' => (int) $get('board_id'),
        ];

        if ($withBoardTitle) {
            $row['board'] = $nested($get('board'), 'title');
        }

        $row += [
            'stage_id'       => $get('stage_id') ? (int) $get('stage_id') : null,
            'stage'          => $nested($get('stage'), 'title'),
            'parent_id'      => $get('parent_id') ? (int) $get('parent_id') : null,
            'status'         => $get('status'),
            'priority'       => $get('priority'),
            'due_at'         => self::toIso8601($get('due_at')),
            'started_at'     => self::toIso8601($get('started_at')),
            'assignees'      => $assignees,
            'labels'         => $labels,
            'comments_count' => (int) $get('comments_count'),
            'crm_contact_id' => $get('crm_contact_id') ? (int) $get('crm_contact_id') : null,
            'archived_at'    => self::toIso8601($get('archived_at')),
        ];

        return self::withoutEmpty($row, ['id', 'board_id']);
    }

    /**
     * Kept for Fluent Boards Pro releases that call it. Same as formatTaskRow().
     */
    public static function formatTaskSummary($task)
    {
        return self::formatTaskRow($task);
    }

    /**
     * Full task for get-task.
     *
     * @param \FluentBoards\App\Models\Task $task
     * @param bool                          $includeActivities
     */
    public static function formatTask($task, $includeActivities = false)
    {
        $data = self::formatTaskRow($task);
        $settings = is_array($task->settings) ? $task->settings : [];

        $data['board'] = $task->board ? $task->board->title : null;
        $data['description'] = self::descriptionToMarkdown($task->description);
        $data['created_at'] = self::toIso8601($task->created_at);
        $data['updated_at'] = self::toIso8601($task->updated_at);
        $data['last_completed_at'] = self::toIso8601($task->last_completed_at);
        $data['cover_color'] = (string) ($settings['cover']['backgroundColor'] ?? '');
        $data['subtask_count'] = (int) ($settings['subtask_count'] ?? 0);
        $data['attachment_count'] = (int) ($settings['attachment_count'] ?? 0);
        $data['watchers'] = self::formatUserList($task->watchers ?? [], $task->board_id);

        $data = self::withoutEmpty($data, ['id', 'board_id', 'description']);

        // Stored newest first; an agent reads a thread oldest to newest.
        $data['comments'] = array_reverse(self::formatCommentList(self::limitItems($task->comments ?? [], self::TASK_HISTORY_LIMIT)));

        $commentsTotal = (int) $task->comments_count;
        if ($commentsTotal > count($data['comments'])) {
            self::addWarning(sprintf(
                'comments holds the latest %d of %d comments.',
                count($data['comments']),
                $commentsTotal
            ));
        }

        if ($includeActivities) {
            $data['activities'] = self::formatActivityList(self::limitItems($task->activities ?? [], self::TASK_HISTORY_LIMIT));
        }

        return $data;
    }

    public static function descriptionToMarkdown($description)
    {
        return DescriptionMarkdownConverter::normalize($description);
    }

    public static function formatTaskList($tasks, $withBoardTitle = false)
    {
        $items = [];
        foreach ($tasks as $task) {
            $items[] = self::formatTaskRow($task, $withBoardTitle);
        }
        return $items;
    }

    public static function formatStage($stage)
    {
        // No position: it is an internal sort key (3.5 sits between 3 and 4). A stage list
        // is in board order.
        return self::withoutEmpty([
            'id'                  => (int) $stage->id,
            'title'               => $stage->title,
            'default_task_status' => method_exists($stage, 'defaultTaskStatus') ? $stage->defaultTaskStatus() : 'open',
            'archived_at'         => self::toIso8601($stage->archived_at),
        ], ['id', 'title']);
    }

    public static function formatStageList($stages)
    {
        $items = [];
        foreach ($stages as $stage) {
            $items[] = self::formatStage($stage);
        }
        return $items;
    }

    public static function formatLabelList($labels)
    {
        $items = [];
        foreach ($labels as $label) {
            $items[] = self::withoutEmpty([
                'id'           => (int) $label->id,
                'title'        => (string) $label->title,
                'bg_color'     => $label->bg_color,
                'color'        => $label->color,
                'color_preset' => \FluentBoards\Framework\Support\Arr::get(
                    (array) $label->settings,
                    Constant::LABEL_COLOR_PRESET_SETTING
                ),
                'archived_at'  => self::toIso8601($label->archived_at),
            ], ['id', 'title']);
        }
        return $items;
    }

    /**
     * People as {id, name, email}. The email is left out when the caller's role may not
     * see it (Helper::sanitizeUsersArray() decides).
     */
    public static function formatUserList($users, $boardId)
    {
        $items = [];
        foreach ($users as $user) {
            $name = trim((string) ($user->display_name ?? ''));
            if (!$name) {
                $name = trim((string) (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
            }

            $items[] = [
                'id'           => isset($user->ID) ? (int) $user->ID : (int) ($user->id ?? 0),
                'display_name' => $name,
                'email'        => $user->user_email ?? '',
            ];
        }
        // Reuse the board permission lookup across user lists in this request.
        static $boardManagerResults = [];
        $isBoardManager = null;
        if (!current_user_can('list_users')) {
            $cacheKey = get_current_blog_id() . ':' . get_current_user_id() . ':' . (int) $boardId;
            if (!array_key_exists($cacheKey, $boardManagerResults)) {
                $boardManagerResults[$cacheKey] = PermissionManager::isBoardManager($boardId);
            }
            $isBoardManager = $boardManagerResults[$cacheKey];
        }

        $people = [];
        foreach (Helper::sanitizeUsersArray($items, $boardId, $isBoardManager) as $index => $item) {
            $person = [
                'id'   => (int) $item['id'],
                'name' => $item['display_name'],
            ];

            // An obfuscated address is of no use to an agent; send only a real one.
            if ($item['email'] !== '' && $item['email'] === $items[$index]['email']) {
                $person['email'] = $item['email'];
            }

            $people[] = $person;
        }

        return $people;
    }

    public static function formatCommentList($comments)
    {
        $items = [];
        foreach ($comments as $comment) {
            $items[] = self::withoutEmpty([
                'id'         => (int) $comment->id,
                'parent_id'  => $comment->parent_id ? (int) $comment->parent_id : null,
                'author'     => $comment->author_name,
                'author_id'  => $comment->created_by ? (int) $comment->created_by : null,
                'privacy'    => $comment->privacy,
                'text'       => self::descriptionToMarkdown($comment->description),
                'created_at' => self::toIso8601($comment->created_at),
            ], ['id', 'text']);
        }
        return $items;
    }

    public static function formatActivityList($activities)
    {
        $items = [];
        foreach ($activities as $activity) {
            $items[] = self::withoutEmpty([
                'action'     => $activity->action ?? '',
                'text'       => trim(wp_strip_all_tags((string) ($activity->description ?? ''))),
                'by'         => isset($activity->created_by) ? (int) $activity->created_by : null,
                'created_at' => self::toIso8601($activity->created_at ?? null),
            ]);
        }
        return $items;
    }

    /**
     * Drop null, empty-string, empty-array and zero values. An agent pays for every key of
     * every row, and a missing key reads as "not set".
     *
     * @param array    $row
     * @param string[] $keep Keys that stay whatever their value.
     */
    public static function withoutEmpty($row, $keep = [])
    {
        foreach ($row as $key => $value) {
            if (in_array($key, $keep, true)) {
                continue;
            }

            if ($value === null || $value === '' || $value === [] || $value === 0) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    /**
     * Read a list of ids. WordPress accepts a comma-separated string where a schema asks
     * for a list ("12,40"), and does not turn it into one, so it is split here. Cast to an
     * array as it is, the string would become the single id 12.
     */
    public static function sanitizeIdArray($values)
    {
        if (is_string($values)) {
            $values = wp_parse_list($values);
        }

        return array_values(array_filter(array_map('absint', (array) $values)));
    }

    /**
     * The fingerprint of what a delete preview showed. The confirm value is bound to it,
     * so a confirm value stops working when anything in the preview changes.
     *
     * @param array $willDelete The will_delete block of the preview.
     */
    public static function previewFingerprint($willDelete)
    {
        return md5((string) wp_json_encode($willDelete));
    }

    private static function limitItems($items, $limit)
    {
        if (is_array($items)) {
            return array_slice($items, 0, $limit);
        }

        $limited = [];
        foreach ($items as $item) {
            if (count($limited) >= $limit) {
                break;
            }

            $limited[] = $item;
        }

        return $limited;
    }

    public static function currentUser()
    {
        $userId = get_current_user_id();
        $user = $userId ? get_user_by('ID', $userId) : null;

        return [
            'wp_user_id'             => (int) $userId,
            'name'                   => $user ? $user->display_name : null,
            'email'                  => $user ? $user->user_email : null,
            'is_wp_admin'            => $user ? user_can($user, 'manage_options') : false,
            'is_fluent_boards_admin' => PermissionManager::isAdmin($userId),
            'can_create_boards'      => PermissionManager::userHasBoardCreationPermission($userId),
        ];
    }

    public static function toIso8601($value)
    {
        if (!$value) {
            return null;
        }

        try {
            // Stored wall times use the site timezone; explicit offsets remain authoritative.
            return (new DateTimeImmutable((string) $value, wp_timezone()))->format('c');
        } catch (Exception $e) {
            return (string) $value;
        }
    }
}
