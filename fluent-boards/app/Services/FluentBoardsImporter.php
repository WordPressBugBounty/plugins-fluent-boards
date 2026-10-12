<?php

namespace FluentBoards\App\Services;

use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;

/**
 * Class FluentBoardsImporter
 *
 * Handles the importing of FluentBoards data, including boards, stages, labels, tasks, subtasks, and comments.
 *
 * @package FluentBoards\App\Services
 */
class FluentBoardsImporter
{
    private static $siteUrl;

    /**
     * @param array $data The full import data.
     * @return array ['board' => Board, 'mappers' => [...], 'siteUrl' => string]
     */
    public static function initializeBoard($data)
    {
        if (ini_get('max_execution_time') < 300) {
            ini_set('max_execution_time', '300');
        }

        $boardData = self::sanitizeBoardData($data['board']);

        $stages = Arr::get($data['board'], 'stages', []);
        $labels = Arr::get($data['board'], 'labels', []);
        $users = Arr::get($data['board'], 'users', []);
        $customFields = Arr::get($data['board'], 'custom_fields', []);
        self::$siteUrl = $data['site_url'];

        $board = Board::create($boardData);
        $stageMapper = self::stageCreate($board, $stages);

        if (self::$siteUrl == site_url('/')) {
            self::createMembers($board, $users, self::loadImportedUsers($users));
        }

        $labelMapper = self::labelCreate($board, $labels);
        $customFieldMapper = self::customFieldCreate($board, $customFields);

        return [
            'board'   => $board,
            'mappers' => [
                'stages'       => $stageMapper,
                'labels'       => $labelMapper,
                'customFields' => $customFieldMapper,
            ],
            'siteUrl' => $data['site_url'],
        ];
    }

    /**
     * @param Board $board The board to add tasks to.
     * @param array $mappers ['stages' => [...], 'labels' => [...], 'customFields' => [...]]
     * @param string $siteUrl The source site URL.
     * @param array $tasks The batch of tasks to process.
     */
    public static function processTaskBatch($board, $mappers, $siteUrl, $tasks)
    {
        if (ini_get('max_execution_time') < 300) {
            ini_set('max_execution_time', '300');
        }

        self::$siteUrl = $siteUrl;
        $usersByEmail = self::loadImportedUsers([], $tasks);
        self::createTasks($board, $mappers['stages'], $mappers['labels'], $mappers['customFields'], $tasks, $usersByEmail);
    }

    /**
     * @param int $boardId The board ID.
     * @return Board|null
     */
    public static function finalizeImport($boardId)
    {
        $board = Board::find($boardId);
        if ($board) {
            self::createActivity($board);
            self::updateBoardTaskCount($boardId);
        }
        return $board;
    }

    /**
     * @param array $data The data to import.
     * @return Board The imported board.
     */
    public static function process($data)
    {
        // Increase max execution time to 5 minutes if less than 5 minutes
        if (ini_get('max_execution_time') < 300) {
            ini_set('max_execution_time', '300');
        }

        $boardData = self::sanitizeBoardData($data['board']);

        $stages = Arr::get($data['board'], 'stages', []);
        $labels = Arr::get($data['board'], 'labels', []);
        $tasks = Arr::get($data['board'], 'tasks', []);
        $users = Arr::get($data['board'], 'users', []);
        $customFields = Arr::get($data['board'], 'custom_fields', []);
        self::$siteUrl = $data['site_url'];

        $usersByEmail = self::loadImportedUsers($users, $tasks);

        // Create Board
        $board = Board::create($boardData);
        // Create stages
        $listStageMapper = self::stageCreate($board, $stages);

        //Create Members if import in same site
        if (self::$siteUrl == site_url('/'))
        {
            self::createMembers($board, $users, $usersByEmail);
        }

        // Create labels
        $labelMapper = self::labelCreate($board, $labels);

        $customFieldMapper = self::customFieldCreate($board, $customFields);

        self::createTasks($board, $listStageMapper, $labelMapper, $customFieldMapper, $tasks, $usersByEmail);

        self::createActivity($board);
        self::updateBoardTaskCount($board->id);

        return $board;
    }

    /**
     * Create stages for a given board.
     *
     * @param Board $board The board to create stages for.
     * @param array $stages The stages to create.
     * @return array A mapping of old stage IDs to new stage IDs.
     */
    private static function stageCreate($board, $stages)
    {
        $listStageMapper = [];
        foreach ($stages as $stage) {
            $stageData = self::sanitizeStageAndLabelData($stage);
            $newStage = $board->stages()->create($stageData);
            $listStageMapper[(int)$stage['id']] = $newStage->id;
        }
        return $listStageMapper;
    }

    /** Fetch all referenced local users once, including authors and both subtask formats. */
    private static function loadImportedUsers($members, $tasks = [])
    {
        $emails = array_column($members, 'user_email');
        while ($tasks) {
            $task = array_pop($tasks);
            foreach (['assignees', 'watchers'] as $relation) {
                $emails = array_merge($emails, array_column($task[$relation] ?? [], 'user_email'));
            }
            $emails = array_merge($emails, array_column($task['comments'] ?? [], 'author_email'));
            foreach ($task['subtask_group'] ?? [] as $group) {
                $tasks = array_merge($tasks, $group['subtasks'] ?? []);
            }
            $tasks = array_merge($tasks, $task['subtasks'] ?? []);
        }

        $emails = array_values(array_unique(array_filter(array_map([self::class, 'importedEmailKey'], $emails))));
        $usersByEmail = [];
        if ($emails) {
            foreach (User::whereIn('user_email', $emails)->get(['ID', 'user_email']) as $user) {
                $usersByEmail[self::importedEmailKey($user->user_email)] = $user;
            }
        }

        return $usersByEmail;
    }

    /** Use a consistent email key for exported and local user records. */
    private static function importedEmailKey($email)
    {
        return strtolower(sanitize_email($email));
    }

    /** Restore membership using matched local accounts and validated board roles. */
    private static function createMembers($board, $users, $usersByEmail)
    {
        if (!$board) {
            return false;
        }

        $members = [];
        foreach ($users as $user) {
            $boardMember = $usersByEmail[self::importedEmailKey($user['user_email'])] ?? null;

            if(!$boardMember) {
                continue;
            }

            $settings = self::sanitizeImportedMemberSettings($user['pivot']['settings'] ?? []);
            if ($settings === null) {
                continue;
            }

            $members[$boardMember->ID] = [
                'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                'settings'    => maybe_serialize($settings),
                'preferences' => $user['pivot']['preferences']
            ];
        }
        if ($members) {
            $board->users()->attach($members);
        }
    }

    /** Accept only member, admin, or viewer role flags, including legacy serialized exports. */
    private static function sanitizeImportedMemberSettings($settings)
    {
        if (is_string($settings)) {
            // Reject enum tokens before decoding: allowed_classes does not prevent enum autoloading.
            if (!is_serialized($settings) || preg_match('/(?:^|[;{}])E:[0-9]+:"/s', $settings)) {
                return null;
            }
            $settings = @unserialize($settings, ['allowed_classes' => false, 'max_depth' => 5]);
        }

        if (!is_array($settings)) {
            return null;
        }

        $role = [];
        foreach ([Constant::IS_BOARD_ADMIN, 'is_viewer_only'] as $flag) {
            $value = $settings[$flag] ?? false;
            if (!in_array($value, [true, false, 1, 0, '1', '0'], true)) {
                return null;
            }
            $role[$flag] = (bool)$value;
        }

        // A membership cannot be both administrator and viewer-only.
        return $role[Constant::IS_BOARD_ADMIN] && $role['is_viewer_only'] ? null : $role;
    }

    /**
     * Create labels for a given board.
     *
     * @param Board $board The board to create labels for.
     * @param array $labels The labels to create.
     * @return array A mapping of old label IDs to new label IDs.
     */
    private static function labelCreate($board, $labels)
    {
        $labelMapper = [];
        foreach ($labels as $label) {
            $labelData = self::sanitizeStageAndLabelData($label);
            $newLabel = $board->labels()->create($labelData);
            $labelMapper[(string)$label['id']] = $newLabel->id;
        }
        return $labelMapper;
    }

    /** Create custom field definitions and map their imported IDs. */
    private static function customFieldCreate($board, $customFields)
    {
        $customFieldMapper = [];
        foreach ($customFields as $customField)
        {
            $customFieldData = self::sanitizeStageAndLabelData($customField);
            $newCustomField = $board->customFields()->create($customFieldData);
            $customFieldMapper[(string)$customField['id']] = $newCustomField->id;
        }

        return $customFieldMapper;
    }

    /**
     * Create tasks and restore their users from the shared batch map.
     *
     * @param Board $board The board to create tasks for.
     * @param array $listStageMapper A mapping of old stage IDs to new stage IDs.
     * @param array $labelMapper A mapping of old label IDs to new label IDs.
     * @param array $tasks The tasks to create.
     */
    private static function createTasks($board, $listStageMapper, $labelMapper, $customFieldMapper, $tasks, $usersByEmail)
    {
        foreach ($tasks as $task) {
            try {
                $task['stage_id'] = $listStageMapper[(int) $task['stage_id']] ??
                                    reset($listStageMapper); // if task is missing the stage id then it included in first stage
                $task['board_id'] = $board->id;
                $taskData         = self::sanitizeTaskData($task);
                $newTask = new Task();
                $newTask->title = $taskData['title'];
                $newTask->slug = $taskData['slug'];
                $newTask->parent_id = $taskData['parent_id'];
                $newTask->type = $taskData['type'];
                $newTask->status = $taskData['status'];
                $newTask->stage_id = $taskData['stage_id'];
                $newTask->source = $taskData['source'];
                $newTask->source_id = $taskData['source_id'];
                $newTask->priority = $taskData['priority'];
                $newTask->description = $taskData['description'];
                $newTask->lead_value = $taskData['lead_value'];
                $newTask->position = $taskData['position'];
                $newTask->issue_number = $taskData['issue_number'];
                $newTask->reminder_type = $taskData['reminder_type'];
                $newTask->remind_at = $taskData['remind_at'];
                $newTask->started_at = $taskData['started_at'];
                $newTask->due_at = $taskData['due_at'];
                $newTask->last_completed_at = $taskData['last_completed_at'];
                $newTask->archived_at = $taskData['archived_at'];
                $newTask->settings = $taskData['settings'];
                $newTask->board_id = $taskData['board_id'];
                $newTask->save();
                self::restoreTimeEstimate($task, $newTask);

                $templateMeta     = $task['meta']['is_template'] ?? 'no';
                $newTask->updateMeta('is_template', $templateMeta);
                // map labels
                self::associateLabelsWithTask($task['labels'], $newTask,
                    $labelMapper);

                //map customFields
                self::associateCustomFieldsWithTask($task['custom_fields'],
                    $newTask, $customFieldMapper);

                if (self::$siteUrl == site_url('/')) {
                    // attach assignees
                    self::attachAssigneeToTask($task['assignees'], $newTask, $usersByEmail);
                    //attach wathers
                    self::attachWatchersToTask($task['watchers'], $newTask, $usersByEmail);
                }

                // Create subtasks
                if (!empty($task['subtask_group'])) {
                    self::addSubtasks($task['subtask_group'], $newTask, $board, $usersByEmail);
                } else if (!empty($task['subtasks'])) {
                    self::addSubtasksFromOldJson($task['subtasks'], $newTask, $board, $usersByEmail);
                }
                // Create comments
                self::createComments($task['comments'], $newTask, $usersByEmail);
            } catch (\Exception $e) {
                error_log('Error while importing task: old '. $task['id'] . ' new: '.  $newTask->id. $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Add grouped subtasks using the shared batch user map.
     *
     * @param array $subtasks The subtasks to add.
     * @param Task $task The task to add subtasks to.
     * @param Board $board The board to associate the subtasks with.
     */
    private static function addSubtasks($subtaskGroups, $task, $board, $usersByEmail)
    {
        $subTaskCounter = 0;
        $subTaskCompletedCounter = 0;
        foreach ($subtaskGroups as $group) {
            $subtaskGroup = self::createSubtaskGroup($task, $group);
            foreach ($group['subtasks'] as $subtask) {
                $subtask['stage_id'] = $task->stage_id;
                $subtask['parent_id'] = $task->id;
                $subtask['board_id'] = $board->id;
                $subTaskData = self::sanitizeTaskData($subtask);
                $newSubtask = $board->tasks()->create($subTaskData);
                self::restoreTimeEstimate($subtask, $newSubtask);
                self::attachAssigneeToTask($subtask['assignees'], $newSubtask, $usersByEmail);
                $subTaskCounter++;
                if ($newSubtask['status'] == 'closed') {
                    $subTaskCompletedCounter++;
                }
                TaskMeta::create([
                    'task_id' => $newSubtask->id,
                    'key' => Constant::SUBTASK_GROUP_CHILD,
                    'value' => $subtaskGroup->id
                ]);
            }
        }
        $taskSettings = $task->settings ?? [];
        $taskSettings['subtask_count'] = $subTaskCounter;
        $taskSettings['subtask_completed_count'] = $subTaskCompletedCounter;
        $task->settings = $taskSettings;
        $task->save();
    }

    //if someone want to import a board from old version's .json file,
    // there are some code repetitions, but will improve later
    /** Restore legacy subtasks and assignees using the shared batch user map. */
    private static function addSubtasksFromOldJson($subtasks, $task, $board, $usersByEmail)
    {
        $subTaskCounter = 0;
        $subTaskCompletedCounter = 0;
        $subtaskGroup = self::createSubtaskGroup($task);

        foreach ($subtasks as $subtask) {
            $subtask['stage_id'] = $task->stage_id;
            $subtask['parent_id'] = $task->id;
            $subtask['board_id'] = $board->id;
            $subTaskData = self::sanitizeTaskData($subtask);
            $newSubtask = $board->tasks()->create($subTaskData);
            self::restoreTimeEstimate($subtask, $newSubtask);
            self::attachAssigneeToTask($subtask['assignees'], $newSubtask, $usersByEmail);
            $subTaskCounter++;
            if ($newSubtask['status'] == 'closed') {
                $subTaskCompletedCounter++;
            }
            TaskMeta::create([
                'task_id' => $newSubtask->id,
                'key' => Constant::SUBTASK_GROUP_CHILD,
                'value' => $subtaskGroup->id
            ]);
        }
        $taskSettings = $task->settings ?? [];
        $taskSettings['subtask_count'] = $subTaskCounter;
        $taskSettings['subtask_completed_count'] = $subTaskCompletedCounter;
        $task->settings = $taskSettings;
        $task->save();
    }

    /** Restore nonnegative integer estimates without copying unrelated task metadata. */
    private static function restoreTimeEstimate($data, $task)
    {
        $value = $data['meta'][Constant::TASK_ESTIMATED_MINUTES] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return;
        }

        $minutes = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($minutes !== false) {
            $task->updateMeta(Constant::TASK_ESTIMATED_MINUTES, $minutes);
        }
    }

    /** Find or create the imported subtask group. */
    private static function createSubtaskGroup($task, $subtaskGroup = null)
    {
        if (!$subtaskGroup) {
            return TaskMeta::create([
                'task_id' => $task->id,
                'key' => Constant::SUBTASK_GROUP_NAME,
                'value' => 'Default Subtask Group'
            ]);
        }
        $taskMeta = TaskMeta::where('task_id', $task->id)
            ->where('key', Constant::SUBTASK_GROUP_NAME)
            ->where('value', $subtaskGroup['value'])
            ->first();
        if (!$taskMeta) {
            return TaskMeta::create([
                'task_id' => $task->id,
                'key' => Constant::SUBTASK_GROUP_NAME,
                'value' => $subtaskGroup['value']
            ]);
        }

        return $taskMeta;
    }

    /**
     * Create comments and replies using creators resolved once for the batch.
     *
     * @param array $commentsAndReply The comments and replies to create.
     * @param Task $task The task to associate the comments with.
     */
    private static function createComments($commentsAndReply, $task, $usersByEmail)
    {
        $comments = [];
        $replies = [];
        $commentMapper = [];
        $commentCounter = 0;

        foreach ($commentsAndReply as $data) {
            $commentCreator = $usersByEmail[self::importedEmailKey($data['author_email'])] ?? null;
            if (!$commentCreator) {
                continue;
            }
            $data['created_by'] = $commentCreator->ID;
            $data['task_id'] = $task->id;
            $data['board_id'] = $task->board_id;
            if ($data['type'] == 'reply') {
                $replies[] = $data;
            } else {
                $comments[] = $data;
            }
        }

        foreach ($comments as $comment) {
            $commentData = self::sanitizeCommentAndReply($comment);
            $newComment = Comment::create($commentData);
            $commentMapper[(int)$comment['id']] = $newComment->id;
            $commentCounter++;
        }
        foreach ($replies as $reply) {
            $replyData = self::sanitizeCommentAndReply($reply);
            if(!$commentMapper[(int)$reply['parent_id']]) {
                $reply['type'] = 'comment';
                Comment::create($replyData);
                $commentCounter++;
            } else {
                $replyData['parent_id'] = $commentMapper[(int)$reply['parent_id']];
                Comment::create($replyData);
            }
        }

        $task['comments_count'] = $commentCounter;
        $task->save();
    }

    /**
     * Create an activity log for a given board.
     *
     * @param Board $board The board to create an activity log for.
     */
    private static function createActivity($board)
    {
        Activity::create([
            'created_by' => get_current_user_id(),
            'action' => 'created',
            'object_id' => $board->id,
            'object_type' => Constant::ACTIVITY_BOARD,
            'old_value' => null,
            'new_value' => $board->title,
            'column' => 'board',
            'description' => 'imported from FluentBoards',
        ]);
    }

    /**
     * Sanitize Board data
     *
     * @param array $data The board data to sanitize.
     * @return array The sanitized board data.
     */
    private static function sanitizeBoardData($data)
    {
        $boardSettings = [];
        $boardBackground = [];
        foreach ($data['settings'] as $key => $value) {
            $boardSettings[$key] = sanitize_text_field($value);
        }

        $background = is_array($data['background'] ?? null) ? $data['background'] : [];

        foreach ($background as $key => $value) {
            switch ($key) {
                case 'image_url':
                    $boardBackground['image_url'] = sanitize_url($value);
                    break;
                case 'id':
                    $boardBackground['id'] = sanitize_text_field($value);
                    break;
                case 'is_image':
                    $boardBackground['is_image'] = rest_sanitize_boolean($value);
                    break;
                case 'color':
                    $boardBackground['color'] = sanitize_text_field($value);
                    break;
                default:
                    break;
            }
        }

        return [
            'title' => sanitize_text_field($data['title']),
            'description' => wp_kses_post($data['description'] ?? ''),
            'type' => sanitize_text_field($data['type']),
            'parent_id' => intval($data['parent_id']),
            'currency' => sanitize_text_field($data['currency']),
            'background' => $boardBackground,
            'settings' => $boardSettings,
        ];
    }

    /**
     * Sanitize Stage and Label data
     *
     * @param array $data The stage or label data to sanitize.
     * @return array The sanitized data.
     */
    private static function sanitizeStageAndLabelData($data)
    {
        $settings = [];
        if (isset($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                if ($key == 'is_template') {
                    $settings[$key] = rest_sanitize_boolean($value);
                } else if ($key == 'default_task_assignees') {
                    $settings[$key] = [];
                } else if ($key == Constant::LABEL_COLOR_PRESET_SETTING) {
                    $presetId = sanitize_key($value);
                    if (Constant::getLabelColorPreset($presetId)) {
                        $settings[$key] = $presetId;
                    }
                } else {
                    $settings[$key] = $value ? sanitize_text_field($value) : null;
                }
            }
        }

        return [
            'title' => sanitize_text_field($data['title']),
            'slug' => sanitize_text_field($data['slug']),
            'type' => sanitize_text_field($data['type']),
            'position' => $data['position'] ? intval($data['position']) : null,
            'color' => $data['color'] ? sanitize_text_field($data['color']) : null,
            'bg_color' => $data['bg_color'] ? sanitize_text_field($data['bg_color']) : null,
            'archived_at' => $data['archived_at'] ? sanitize_text_field($data['archived_at']) : null,
            'settings' => $settings,
        ];
    }

    /**
     * Sanitize task and subtask data without retaining counts for unsupported attachments.
     *
     * @param array $data The task or subtask data to sanitize.
     * @return array The sanitized data.
     */
    private static function sanitizeTaskData($data)
    {
        $settings = [];
        if (isset($data['settings'])) {
            foreach ($data['settings'] as $key => $value) {
                if ($key == 'cover') {
                    $cover = [];
                    foreach ($value as $coverKey => $coverValue) {
                        $cover[$coverKey] = $coverValue ? sanitize_text_field($coverValue) : null;
                    }
                    $settings[$key] = $cover;
                } else {
                    $settings[$key] = $value ? sanitize_text_field($value) : null;
                }
            }
        }


        // JSON imports do not restore attachments, so the source count is not valid here.
        $settings['attachment_count'] = 0;

        return [
            'board_id' => intval($data['board_id']),
            'title' => sanitize_text_field($data['title']),
            'slug' => sanitize_text_field($data['slug']),
            'parent_id' => $data['parent_id'] ? intval($data['parent_id']) : null,
            'type' => $data['type'] ? sanitize_text_field($data['type']) : 'task',
            'status' => $data['status'] ? sanitize_text_field($data['status']) : 'open',
            'stage_id' => intval($data['stage_id']),
            'source' => sanitize_text_field($data['source']),
            'source_id' => $data['source_id'] ? intval($data['source_id']) : null,
            'priority' => sanitize_text_field($data['priority'] ?? ''),
            'description' => wp_kses_post($data['description'] ?? ''),
            'lead_value' => $data['lead_value'] ? doubleval($data['lead_value']) : null,
            'position' => intval($data['position']),
            'issue_number' => $data['issue_number'] ? intval($data['issue_number']) : null,
            'reminder_type' => $data['reminder_type'] ? sanitize_text_field($data['reminder_type']) : null,
            'remind_at' => $data['remind_at'] ? sanitize_text_field($data['remind_at']) : null,
            'started_at' => $data['started_at'] ? sanitize_text_field($data['started_at']) : null,
            'due_at' => $data['due_at'] ? sanitize_text_field($data['due_at']) : null,
            'last_completed_at' => $data['last_completed_at'] ? sanitize_text_field($data['last_completed_at']) : null,
            'archived_at' => $data['archived_at'] ? sanitize_text_field($data['archived_at']) : null,
            'settings' => $settings,
        ];
    }


    /**
     * Sanitize Comment and Reply data
     *
     * This method takes an array of comment or reply data and sanitizes each field to ensure it is safe for storage and display.
     *
     * @param array $data The comment or reply data to sanitize.
     * @return array The sanitized data.
     */
    private static function sanitizeCommentAndReply($data)
    {
        return [
            'task_id' => intval($data['task_id']),
            'board_id' => intval($data['board_id']),
            'description' => wp_kses_post($data['description'] ?? ''),
            'type' => sanitize_text_field($data['type']),
            'privacy' => sanitize_text_field($data['privacy']),
            'status' => sanitize_text_field($data['status']),
            'author_name' => sanitize_text_field($data['author_name']),
            'author_email' => sanitize_email($data['author_email']),
            'author_ip' => sanitize_text_field($data['author_ip']),
            'created_by' => intval($data['created_by']),
            'settings' => $data['settings'],
        ];
    }


    /**
     * Update the task count for a given board.
     *
     * @param int $boardId The ID of the board to update.
     */
    private static function updateBoardTaskCount($boardId)
    {
        $board = Board::find($boardId);
        $settings = $board->settings ?? [];
        $settings['tasks_count'] = $board->tasks()->where('parent_id', null)->whereNull('archived_at')->count();
        $board->settings = $settings;
        $board->save();
    }

    /**
     * Associate labels with a given task.
     *
     * @param array $taskAssociatedLabels The labels to associate.
     * @param Task $task The task to associate labels with.
     * @param array $labelMapper A mapping of label IDs to new label IDs.
     */
    private static function associateLabelsWithTask($taskAssociatedLabels, $task, $labelMapper)
    {
        foreach ($taskAssociatedLabels as $label) {
            Relation::create([
                'object_id' => $task->id,
                'object_type' => Constant::OBJECT_TYPE_TASK_LABEL,
                'foreign_id' => $labelMapper[(string)$label['id']]
            ]);
        }
    }

    /** Restore sanitized custom field values using the imported ID map. */
    private static function associateCustomFieldsWithTask($taskCustomFields, $task, $customFieldMapper)
    {
        foreach ($taskCustomFields as $customField) {
            $customFieldId = (string)intval($customField['id'] ?? 0);

            if (!$customFieldId || !isset($customFieldMapper[$customFieldId])) {
                continue;
            }

            $settings = self::sanitizeImportedCustomFieldSettings(
                $customField['pivot']['settings'] ?? null
            );

            if ($settings === null) {
                continue;
            }

            Relation::create([
                'object_id' => $task->id,
                'object_type' => Constant::TASK_CUSTOM_FIELD,
                'foreign_id' => $customFieldMapper[$customFieldId],
                'settings' => $settings
            ]);
        }
    }

    /**
     * Decode and sanitize custom-field settings from an imported board.
     *
     * Legacy exports store these settings as serialized arrays. Object creation
     * must remain disabled because the import document is attacker-controlled.
     *
     * @param mixed $settings
     * @return array|null
     */
    private static function sanitizeImportedCustomFieldSettings($settings)
    {
        if (is_string($settings)) {
            $settings = trim($settings);

            if (!is_serialized($settings)) {
                return null;
            }

            // PHP does not apply allowed_classes to enum tokens and may autoload
            // the attacker-selected enum class before returning from unserialize().
            if (preg_match('/(?:^|[;{}])E:[0-9]+:"/s', $settings)) {
                return null;
            }

            $settings = @unserialize($settings, [
                'allowed_classes' => false,
                'max_depth' => 20,
            ]);
        }

        if (!is_array($settings) || !array_key_exists('value', $settings)) {
            return null;
        }

        if (!self::isValidImportedCustomFieldValue($settings['value'])) {
            return null;
        }

        return [
            'value' => self::sanitizeImportedCustomFieldValue($settings['value']),
        ];
    }

    /**
     * Ensure an imported custom-field value contains only bounded primitive data.
     *
     * @param mixed $value
     * @param int $depth
     * @return bool
     */
    private static function isValidImportedCustomFieldValue($value, $depth = 0)
    {
        if ($depth > 20) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isValidImportedCustomFieldValue($item, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return is_null($value) || is_string($value) || is_bool($value) ||
            is_int($value) || is_float($value);
    }

    /**
     * Recursively sanitize strings within a validated custom-field value.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sanitizeImportedCustomFieldValue($value)
    {
        if (is_array($value)) {
            $sanitizedValue = [];

            foreach ($value as $key => $item) {
                $sanitizedKey = is_string($key) ? sanitize_text_field($key) : $key;
                $sanitizedValue[$sanitizedKey] = self::sanitizeImportedCustomFieldValue($item);
            }

            return $sanitizedValue;
        }

        return is_string($value) ? sanitize_text_field($value) : $value;
    }

    /** Restore task assignees from the batch map with one pivot insert. */
    private static function attachAssigneeToTask($assignees, $task, $usersByEmail)
    {
        $members = [];
        foreach ($assignees as $assignee) {
            $user = $usersByEmail[self::importedEmailKey($assignee['user_email'])] ?? null;
            if ($user) {
                $members[$user->ID] = ['object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE];
            }
        }
        if ($members) {
            $task->assignees()->attach($members);
        }
    }

    /** Restore task watchers from the batch map with one pivot insert. */
    private static function attachWatchersToTask($watchers, $task, $usersByEmail)
    {
        $members = [];
        foreach ($watchers as $watcher) {
            $user = $usersByEmail[self::importedEmailKey($watcher['user_email'])] ?? null;
            if ($user) {
                $members[$user->ID] = ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH];
            }
        }
        if ($members) {
            $task->watchers()->attach($members);
        }
    }

}
