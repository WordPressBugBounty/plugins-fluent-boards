<?php

namespace FluentBoards\App\Modules\MCP;

use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Modules\MCP\Tools\BoardTools;
use FluentBoards\App\Modules\MCP\Tools\CommentTools;
use FluentBoards\App\Modules\MCP\Tools\ContextTools;
use FluentBoards\App\Modules\MCP\Tools\LabelTools;
use FluentBoards\App\Modules\MCP\Tools\StageTools;
use FluentBoards\App\Modules\MCP\Tools\TaskQueryTools;
use FluentBoards\App\Modules\MCP\Tools\TaskTools;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;

/**
 * Single source of truth for Fluent Boards MCP abilities.
 *
 * Labels, descriptions and schema text are plain English and are not translated: an AI
 * model reads them, and a translated description makes the tool worse on a non-English
 * site. The first sentence of each description is complete on its own (at most 160
 * characters), because some clients show only that sentence in their tool index.
 */
class AbilitiesRegistrar
{
    const DUE_DATE_FILTERS = ['overdue', 'today', 'this-week', 'next-week', 'this-month', 'upcoming', 'no-dates'];
    const TASK_SORT = ['position', 'title', 'status', 'priority', 'due_at', 'created_at', 'updated_at'];

    /**
     * Hints of a read tool. All four are always sent: a client reads a missing hint as the
     * unsafe default (destructive, and able to reach people).
     */
    const READ_HINTS = ['readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false];

    /**
     * Hints of a write tool.
     *
     * @param bool $destructive True when the tool deletes or overwrites stored data.
     * @param bool $idempotent  True when the same call again changes nothing more.
     * @param bool $openWorld   True when the tool can email or notify a person.
     */
    public static function writeHints($destructive, $idempotent, $openWorld)
    {
        return [
            'readonly'      => false,
            'destructive'   => (bool) $destructive,
            'idempotent'    => (bool) $idempotent,
            'openWorldHint' => (bool) $openWorld,
        ];
    }

    public static function getDefinitions()
    {
        // board_id and task_id need no description: the server rules say where ids come from,
        // and every tool repeats them.
        $boardId = ['type' => 'integer'];
        $taskId = ['type' => 'integer'];
        $page = ['type' => 'integer', 'default' => 1, 'description' => 'Page number, from 1.'];
        $perPage = function ($max) {
            return ['type' => 'integer', 'default' => 20, 'description' => 'Rows per page. Max ' . $max . '.'];
        };
        $dateIn = 'YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, read in the site timezone unless it has an offset. A date without a time means the whole day.';

        return [
            'fluent-boards/get-fluentboards-context' => [
                'label'       => 'Get FluentBoards Context',
                'description' => 'Returns who you are, the site time and timezone, your boards with their ids, the task priorities and the rules to follow. Call it first, once per session.',
                // No `properties`: an empty stdClass makes the WordPress schema check fatal on any argument.
                'input_schema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                ],
                'execute_callback'    => [ContextTools::class, 'getContext'],
                'permission_callback' => [self::class, 'appAccess'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/list-boards' => [
                'label'       => 'List Boards',
                'description' => 'Lists the boards you can use with id, title, type and counts of stages, labels and members. Filter by title text or type. Archived boards are left out unless include_archived is true.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'           => ['type' => 'string', 'description' => 'Text in the board title.'],
                        'type'             => ['type' => 'string', 'enum' => ['to-do', 'roadmap'], 'description' => 'to-do is a task board. roadmap is a public idea board with votes.'],
                        'include_archived' => ['type' => 'boolean', 'default' => false],
                        'sort_by'          => ['type' => 'string', 'enum' => ['id', 'title', 'created_at', 'updated_at'], 'default' => 'created_at'],
                        'sort_type'        => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'page'             => $page,
                        'per_page'         => $perPage(100),
                    ],
                ],
                'execute_callback'    => [BoardTools::class, 'listBoards'],
                'permission_callback' => [self::class, 'anyBoardAccess'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/get-board' => [
                'label'       => 'Get Board',
                'description' => 'Returns one board with its stages, labels and members, each with its id. Call it before you create, move, label or assign tasks on a board you do not know.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'      => $boardId,
                        'include_tasks' => ['type' => 'boolean', 'default' => false, 'description' => 'Also return the first 100 active tasks as compact rows, ordered by stage and position. For filters or more rows use list-tasks.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [BoardTools::class, 'getBoard'],
                'permission_callback' => [self::class, 'readBoard'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/create-board' => [
                'label'       => 'Create Board',
                'description' => 'Creates a board and returns it with the ids of its stages, labels and members. Leave stages and labels out to get the default ones.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string', 'description' => 'Markdown.'],
                        'type'           => ['type' => 'string', 'enum' => ['to-do', 'roadmap'], 'default' => 'to-do', 'description' => 'to-do is a task board. roadmap is a public idea board with votes.'],
                        'currency'       => ['type' => 'string', 'default' => 'USD', 'description' => 'ISO 4217 code used for money values on the board.'],
                        'crm_contact_id' => ['type' => 'integer', 'description' => 'FluentCRM contact id to link to the board.'],
                        'folder_id'      => ['type' => 'integer', 'description' => 'Board folder id to place the board in.'],
                        'stages'         => [
                            'type'        => 'array',
                            'description' => 'Stages (columns) in order. Omit for the defaults: Open, In Progress, Completed on a to-do board.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'               => ['type' => 'string'],
                                    'slug'                => ['type' => 'string', 'description' => 'Optional. Made from the title when omitted.'],
                                    'position'            => ['type' => 'integer', 'description' => '1-based. Defaults to the order in the list.'],
                                    'default_task_status' => [
                                        'type'        => 'string',
                                        'enum'        => ['open', 'closed'],
                                        'description' => 'Status a task gets when it enters this stage. Defaults to open, or closed for a stage titled Completed or Done. Ignored on a roadmap board.',
                                    ],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                        'labels'         => [
                            'type'        => 'array',
                            'description' => 'Labels to create. Omit for the five default colour labels.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'    => ['type' => 'string'],
                                    'bg_color' => ['type' => 'string', 'description' => 'Hex background colour. Defaults to #f3f4f6.'],
                                    'color'    => ['type' => 'string', 'description' => 'Hex text colour. Defaults to #1B2533.'],
                                    Constant::LABEL_COLOR_PRESET_SETTING => [
                                        'type'        => 'string',
                                        'enum'        => self::getLabelColorPresetIds(),
                                        'description' => 'A predefined colour, used in place of bg_color and color.',
                                    ],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                        'member_ids'     => [
                            'type'        => 'array',
                            'description' => 'WordPress user ids to add as board members. Max 50. You are always a member.',
                            'items'       => ['type' => 'integer'],
                            'maxItems'    => 50,
                        ],
                    ],
                    'required' => ['title'],
                ],
                'execute_callback'    => [BoardTools::class, 'createBoard'],
                'permission_callback' => [self::class, 'createBoardAccess'],
                'annotations' => self::writeHints(false, false, true),
            ],

            'fluent-boards/delete-board' => [
                'label'       => 'Delete Board',
                'description' => 'Deletes a board for good, with all its tasks, stages, labels and files, in two calls. The first call deletes nothing: it returns what will be deleted and a confirm value. Only a Fluent Boards admin can delete a board.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'confirm'  => ['type' => 'string', 'description' => 'The confirm value from the first call. Send it only after the user agreed to what will_delete showed.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [BoardTools::class, 'deleteBoard'],
                'permission_callback' => [self::class, 'deleteBoardAccess'],
                'annotations' => self::writeHints(true, false, false),
            ],

            'fluent-boards/list-tasks' => [
                'label'       => 'List Tasks',
                'description' => 'Lists the tasks of one board as compact rows, with filters for stage, status, priority, assignee, label and due date. Filters combine with AND; several values in one filter combine with OR. Subtasks are not listed. For the description and comments of a task call get-task.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'         => $boardId,
                        'search'           => ['type' => 'string', 'description' => 'Text in the task title. "id:123" finds a task by id.'],
                        'stage'            => ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'description' => 'Stage ids from get-board. "archived" matches tasks in archived stages.'],
                        'task_status'      => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['open', 'closed', 'archived']], 'description' => 'open is not completed, closed is completed, archived is archived tasks.'],
                        'priority'         => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Priority keys: urgent, high, medium, low, or a custom key from get-fluentboards-context.'],
                        'assignee'         => ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'description' => 'User ids. "me" is you. "no-assignee" matches unassigned tasks.'],
                        'labels'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Label ids from get-board or list-labels.'],
                        'due_date'         => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::DUE_DATE_FILTERS], 'description' => 'Due date windows in site time. overdue and upcoming leave completed tasks out. this-month is from now to the end of the month. no-dates is tasks without a due date.'],
                        'due_from'         => ['type' => 'string', 'description' => 'Tasks due on or after this moment. ' . $dateIn],
                        'due_to'           => ['type' => 'string', 'description' => 'Tasks due on or before this moment. A date without a time includes that whole day.'],
                        'include_archived' => ['type' => 'boolean', 'default' => false, 'description' => 'Also list archived tasks.'],
                        'sort_by'          => ['type' => 'string', 'enum' => self::TASK_SORT, 'default' => 'position', 'description' => 'position is the board order: by stage, then top to bottom.'],
                        'sort_direction'   => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                        'page'             => $page,
                        'per_page'         => $perPage(100),
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'listTasks'],
                'permission_callback' => [self::class, 'readBoard'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/get-task' => [
                'label'       => 'Get Task',
                'description' => 'Returns one task with its description (Markdown), stage, assignees, labels, watchers and its latest 20 comments, oldest first.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'           => $boardId,
                        'task_id'            => $taskId,
                        'include_activities' => ['type' => 'boolean', 'default' => false, 'description' => 'Also return the latest 20 entries of the change history.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'getTask'],
                'permission_callback' => [self::class, 'readBoard'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/create-task' => [
                'label'       => 'Create Task',
                'description' => 'Creates a task in a stage of a board and returns its row. The stage\'s default assignees are added, and assignees and watchers are notified as in the app.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'       => $boardId,
                        'stage_id'       => ['type' => 'integer', 'description' => 'Stage id on this board, from get-board.'],
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string', 'description' => 'Markdown.'],
                        'priority'       => ['type' => 'string', 'description' => 'urgent, high, medium, low, or a custom key from get-fluentboards-context. Omit for no priority.'],
                        'due_at'         => ['type' => 'string', 'description' => 'Due date. ' . $dateIn],
                        'started_at'     => ['type' => 'string', 'description' => 'Start date, on or before due_at. Same format as due_at.'],
                        'assignees'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'User ids to assign. Each must be a board member or a Fluent Boards admin.'],
                        'label_ids'      => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Ids of board labels to attach.'],
                        'crm_contact_id' => ['type' => 'integer', 'description' => 'FluentCRM contact id to link to the task.'],
                    ],
                    'required' => ['board_id', 'stage_id', 'title'],
                ],
                'execute_callback'    => [TaskTools::class, 'createTask'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(false, false, true),
            ],

            'fluent-boards/update-task' => [
                'label'       => 'Update Task',
                'description' => 'Changes fields of a task and returns its row. Only the fields you send change. To change the stage use move-task; to add or remove single assignees use assign-task.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'       => $boardId,
                        'task_id'        => $taskId,
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string', 'description' => 'Markdown. Replaces the whole description.'],
                        'status'         => ['type' => 'string', 'enum' => ['open', 'closed'], 'description' => 'closed completes the task. open reopens it.'],
                        'priority'       => ['type' => 'string', 'description' => 'urgent, high, medium, low, or a custom key from get-fluentboards-context. An empty string clears it.'],
                        'due_at'         => ['type' => 'string', 'description' => 'Due date. ' . $dateIn . ' An empty string clears it. Changing the due date of a completed task reopens it, unless status closed is sent in the same call.'],
                        'started_at'     => ['type' => 'string', 'description' => 'Start date, on or before due_at. Same format as due_at. An empty string clears it.'],
                        'assignees'      => [
                            'type'        => 'array',
                            'items'       => ['type' => 'integer'],
                            'description' => 'User ids. Replaces all current assignees; an empty list removes them all. New ids must be board members or Fluent Boards admins.',
                        ],
                        'crm_contact_id' => ['type' => 'integer', 'description' => 'FluentCRM contact id. 0 removes the link.'],
                        'cover_color'    => ['type' => 'string', 'description' => 'Hex colour of the card cover, for example #FFE4B5. It replaces a cover image. An empty string removes the colour.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'updateTask'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, true, true),
            ],

            'fluent-boards/move-task' => [
                'label'       => 'Move Task',
                'description' => 'Moves a task to another stage, or to a stage of another board, and returns its row. A stage whose default_task_status is closed completes the task; an open stage reopens it.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'         => ['type' => 'integer', 'description' => 'Board the task is on now.'],
                        'task_id'          => $taskId,
                        'target_stage_id'  => ['type' => 'integer', 'description' => 'Stage to move to, from get-board. Must be on target_board_id, or on board_id when that is omitted.'],
                        'target_board_id'  => ['type' => 'integer', 'description' => 'Other board to move the task to. You need write access to both boards.'],
                        'position'         => ['type' => 'integer', 'description' => '1-based place in the stage. Default 1, the top. Ignored when previous_task_id or next_task_id is given.'],
                        'previous_task_id' => ['type' => 'integer', 'description' => 'Place the task directly below this task.'],
                        'next_task_id'     => ['type' => 'integer', 'description' => 'Place the task directly above this task.'],
                    ],
                    'required' => ['board_id', 'task_id', 'target_stage_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'moveTask'],
                'permission_callback' => [self::class, 'moveTaskAccess'],
                'annotations' => self::writeHints(false, true, true),
            ],

            'fluent-boards/archive-task' => [
                'label'       => 'Archive or Restore Task',
                'description' => 'Archives a task, or restores it with archived=false. An archived task leaves the board but is not deleted.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'task_id'  => $taskId,
                        'archived' => ['type' => 'boolean', 'default' => true, 'description' => 'true archives, false restores.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'archiveTask'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(false, true, true),
            ],

            'fluent-boards/delete-task' => [
                'label'       => 'Delete Task',
                'description' => 'Deletes a task for good, with its subtasks, comments and files, in two calls. The first call deletes nothing: it returns what will be deleted and a confirm value. Only a board manager can delete; archive-task is the choice that can be undone.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'task_id'  => $taskId,
                        'confirm'  => ['type' => 'string', 'description' => 'The confirm value from the first call. Send it only after the user agreed to what will_delete showed.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'deleteTask'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, false, false),
            ],

            'fluent-boards/add-comment' => [
                'label'       => 'Add Comment',
                'description' => 'Adds a comment to a task as the connected user and notifies the people who follow the task. Mentioned users get a mention notification.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'      => $boardId,
                        'task_id'       => $taskId,
                        'description'   => ['type' => 'string', 'description' => 'The comment text. Plain text or simple HTML.'],
                        'privacy'       => ['type' => 'string', 'enum' => ['private', 'public'], 'default' => 'private', 'description' => 'private is for board members only. public also shows on a public roadmap or shared board.'],
                        'mentioned_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'User ids of board members to mention.'],
                    ],
                    'required' => ['board_id', 'task_id', 'description'],
                ],
                'execute_callback'    => [CommentTools::class, 'addComment'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(false, false, true),
            ],

            'fluent-boards/list-labels' => [
                'label'       => 'List Labels',
                'description' => 'Lists the labels of a board with id, title and colours. get-board returns the same labels with the stages and members.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'  => $boardId,
                        'only_used' => ['type' => 'boolean', 'default' => false, 'description' => 'Only labels attached to at least one task.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'listLabels'],
                'permission_callback' => [self::class, 'readBoard'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/save-label' => [
                'label'       => 'Create or Update Label',
                'description' => 'Creates a board label, or updates one when label_id is given. On update only the fields you send change.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'label_id' => ['type' => 'integer', 'description' => 'Label to update. Omit to create a label.'],
                        'title'    => ['type' => 'string', 'description' => 'Required to create a label, unless bg_color is given (a colour-only label).'],
                        'bg_color' => ['type' => 'string', 'description' => 'Hex background colour. Defaults to #f3f4f6 on create.'],
                        'color'    => ['type' => 'string', 'description' => 'Hex text colour. Defaults to #1B2533 on create.'],
                        Constant::LABEL_COLOR_PRESET_SETTING => [
                            'type'        => 'string',
                            'enum'        => self::getLabelColorPresetIds(),
                            'description' => 'A predefined colour. Omit to keep the current one; an empty string clears it.',
                        ],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'saveLabel'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, false, false),
            ],

            'fluent-boards/delete-label' => [
                'label'       => 'Delete Label',
                'description' => 'Deletes a board label and removes it from every task that has it. This cannot be undone.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'label_id' => ['type' => 'integer', 'description' => 'Label id from get-board or list-labels.'],
                    ],
                    'required' => ['board_id', 'label_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'deleteLabel'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, true, false),
            ],

            'fluent-boards/add-task-label' => [
                'label'       => 'Add Task Label',
                'description' => 'Attaches existing board labels to a task, by id or by title. A title that no label or more than one label has is refused; create a missing label with save-label first.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'     => $boardId,
                        'task_id'      => $taskId,
                        'label_id'     => ['type' => 'integer', 'description' => 'One label id. Can be combined with label_ids and label_titles.'],
                        'label_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Label ids from get-board or list-labels.'],
                        'label_titles' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50, 'description' => 'Label titles on this board. Letter case is ignored.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'addTaskLabel'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(false, true, false),
            ],

            'fluent-boards/remove-task-label' => [
                'label'       => 'Remove Task Label',
                'description' => 'Removes labels from a task, by id or by title. The board keeps the labels.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'     => $boardId,
                        'task_id'      => $taskId,
                        'label_id'     => ['type' => 'integer', 'description' => 'One label id. Can be combined with label_ids and label_titles.'],
                        'label_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'Label ids from get-task or get-board.'],
                        'label_titles' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50, 'description' => 'Label titles on this board. Letter case is ignored.'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'removeTaskLabel'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, true, false),
            ],

            'fluent-boards/list-my-tasks' => [
                'label'       => 'List My Tasks',
                'description' => 'Lists a person\'s tasks across all boards you can read, as compact rows with the board and stage titles. The person is you unless user_id is given.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'user_id'   => ['type' => 'integer', 'description' => 'Another user\'s id. Defaults to you. Only boards you can read are included.'],
                        'task_type' => [
                            'type'        => 'string',
                            'enum'        => TaskQueryTools::TASK_TYPES,
                            'default'     => 'assigned',
                            'description' => 'assigned: open tasks assigned to the person. mentioned: tasks where the person was mentioned. The other values cover tasks the person watches (assignees are watchers): upcoming is due later and not completed, due_today, overdue, completed, and others is tasks without a due date.',
                        ],
                        'due_from'  => ['type' => 'string', 'description' => 'Only tasks due on or after this moment. ' . $dateIn],
                        'due_to'    => ['type' => 'string', 'description' => 'Only tasks due on or before this moment. A date without a time includes that whole day.'],
                        'board_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these boards.'],
                        'order_by'  => ['type' => 'string', 'enum' => TaskQueryTools::ORDER_BY, 'default' => 'due_at', 'description' => 'due_at puts tasks without a due date last.'],
                        'order'     => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'ASC'],
                        'page'      => $page,
                        'per_page'  => $perPage(50),
                    ],
                ],
                'execute_callback'    => [TaskQueryTools::class, 'listMyTasks'],
                'permission_callback' => [self::class, 'appAccess'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/search-tasks' => [
                'label'       => 'Search Tasks',
                'description' => 'Finds tasks by text in the title across all boards you can read, newest change first. Returns compact rows with board_id, so the result feeds get-task and the write tools.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'               => ['type' => 'string', 'minLength' => 3, 'description' => 'At least three characters, matched as one phrase. "id:123" finds one task by id. "archived:text" searches archived tasks only.'],
                        'search_descriptions' => ['type' => 'boolean', 'default' => false, 'description' => 'Also match the text in task descriptions. Slower on a large site.'],
                        'board_ids'           => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these boards.'],
                        'include_archived'    => ['type' => 'boolean', 'default' => false, 'description' => 'Also return archived tasks.'],
                        'page'                => $page,
                        'per_page'            => $perPage(50),
                    ],
                    'required' => ['query'],
                ],
                'execute_callback'    => [TaskQueryTools::class, 'searchTasks'],
                'permission_callback' => [self::class, 'appAccess'],
                'annotations' => self::READ_HINTS,
            ],

            'fluent-boards/save-stage' => [
                'label'       => 'Create or Update Stage',
                'description' => 'Creates a stage (column) on a board, or updates one when stage_id is given. On update only the fields you send change.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'            => $boardId,
                        'stage_id'            => ['type' => 'integer', 'description' => 'Stage to update. Omit to create a stage.'],
                        'title'               => ['type' => 'string', 'description' => 'Required to create a stage.'],
                        'position'            => ['type' => 'integer', 'description' => '1-based place on the board. A new stage goes last when omitted.'],
                        'default_task_status' => ['type' => 'string', 'enum' => ['open', 'closed'], 'description' => 'Status a task gets when it enters this stage. closed makes this a "done" stage.'],
                        'bg_color'            => ['type' => 'string', 'description' => 'Hex background colour of the stage.'],
                        'color'               => ['type' => 'string', 'description' => 'Hex text colour of the stage.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [StageTools::class, 'saveStage'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, false, false),
            ],

            'fluent-boards/archive-stage' => [
                'label'       => 'Archive or Restore Stage',
                'description' => 'Archives a stage together with its tasks, or restores it with archived=false. A restored stage goes to the end of the board.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'stage_id' => ['type' => 'integer', 'description' => 'Stage id from get-board.'],
                        'archived' => ['type' => 'boolean', 'default' => true, 'description' => 'true archives, false restores.'],
                    ],
                    'required' => ['board_id', 'stage_id'],
                ],
                'execute_callback'    => [StageTools::class, 'archiveStage'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(false, true, true),
            ],

            'fluent-boards/assign-task' => [
                'label'       => 'Assign Task',
                'description' => 'Adds, removes or replaces the assignees of a task and returns the new list. An assignee must be a board member or a Fluent Boards admin, and also becomes a watcher of the task.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => $boardId,
                        'task_id'  => $taskId,
                        'user_id'  => ['type' => 'integer', 'description' => 'One user id. Ignored when user_ids is given.'],
                        'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50, 'description' => 'User ids, from the members of get-board.'],
                        'mode'     => [
                            'type'        => 'string',
                            'enum'        => ['add', 'remove', 'sync'],
                            'default'     => 'add',
                            'description' => 'add keeps the current assignees. remove takes the given users off. sync makes the list exactly the given users; user_ids [] with sync removes everyone.',
                        ],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'assignTask'],
                'permission_callback' => [self::class, 'writeBoard'],
                'annotations' => self::writeHints(true, true, true),
            ],
        ];
    }

    public static function register()
    {
        self::registerDefinitions(self::getDefinitions());
    }

    /**
     * Register ability definitions with the shared wrappers. Public so Fluent Boards Pro
     * registers its tools the same way.
     *
     * @param array $definitions Ability name => definition, as in getDefinitions().
     */
    public static function registerDefinitions($definitions)
    {
        foreach ($definitions as $name => $definition) {
            $args = [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => 'fluent-boards',
                'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback']),
                'permission_callback' => self::wrapPermissionCallback($name, $definition['permission_callback']),
                'meta'                => [
                    'show_in_rest' => true,
                    'mcp'          => [
                        'public' => true,
                    ],
                ],
            ];

            if (!empty($definition['input_schema'])) {
                $args['input_schema'] = $definition['input_schema'];
            }

            if (!empty($definition['annotations'])) {
                $args['meta']['annotations'] = $definition['annotations'];
            }

            wp_register_ability($name, $args);
        }
    }

    /**
     * Run a tool. Adds meta.warnings to the result, and turns an exception into an error
     * that carries no SQL, file path or stack trace.
     */
    public static function wrapExecuteCallback($toolName, $callback)
    {
        return function ($params = []) use ($toolName, $callback) {
            // Clear anything a previous tool in this request left behind.
            MCPHelper::takeWarnings();

            try {
                $result = call_user_func($callback, is_array($params) ? $params : []);
            } catch (\Throwable $e) {
                MCPHelper::takeWarnings();

                /**
                 * Fires when a Fluent Boards MCP tool throws. Use it to log the exception;
                 * the client gets a generic error.
                 *
                 * @param \Throwable $e
                 * @param string     $toolName
                 * @param mixed      $params
                 */
                do_action('fluent_boards/mcp_tool_exception', $e, $toolName, $params);

                return MCPHelper::error(
                    'internal_error',
                    'The tool stopped with an unexpected error, and a write may be half done. Read the record again before you repeat the call.'
                );
            }

            $warnings = MCPHelper::takeWarnings();
            if ($warnings && is_array($result)) {
                $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
                $meta['warnings'] = array_values(array_merge((array) ($meta['warnings'] ?? []), $warnings));
                $result['meta'] = $meta;
            }

            return $result;
        };
    }

    /**
     * Check the input, then the permission.
     *
     * The MCP adapter checks permission before WordPress checks the input. Without this
     * order a call with a missing or misspelled board_id is answered "Permission denied".
     * A denied permission is answered with the error envelope, not with false.
     */
    public static function wrapPermissionCallback($toolName, $callback)
    {
        return function ($params = []) use ($toolName, $callback) {
            $problem = self::inputProblem($toolName, $params);
            if (is_wp_error($problem)) {
                return $problem;
            }

            $allowed = call_user_func($callback, is_array($params) ? $params : []);

            if ($allowed === true || is_wp_error($allowed)) {
                return $allowed;
            }

            return MCPHelper::error('forbidden', 'Your user may not run this tool.');
        };
    }

    /**
     * Find an unknown parameter or a schema failure in a tool's input.
     *
     * WordPress lets a parameter the schema does not list through. A misspelled filter that
     * is ignored returns the unfiltered result as if it were the answer, so it is refused.
     *
     * @param string $abilityName
     * @param mixed  $input
     * @return true|\WP_Error
     */
    public static function inputProblem($abilityName, $input)
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($abilityName) : null;
        $schema = $ability ? (array) $ability->get_input_schema() : [];

        if (!$schema) {
            return true;
        }

        if ($input === null || $input === '') {
            $input = [];
        }

        if (is_object($input)) {
            $input = (array) $input;
        }

        if (!is_array($input)) {
            return MCPHelper::invalid([], 'The tool input must be a JSON object of parameters.');
        }

        $allowed = array_map('strval', array_keys((array) ($schema['properties'] ?? [])));
        $unknown = array_values(array_diff(array_map('strval', array_keys($input)), $allowed));

        if ($unknown) {
            return MCPHelper::error('unknown_param', sprintf(
                $allowed
                    ? 'This tool has no parameter named %s. Use the names in allowed.'
                    : 'This tool takes no parameters. Remove %s.',
                implode(', ', $unknown)
            ), [
                'fields'  => $unknown,
                'allowed' => $allowed,
            ]);
        }

        if (!$allowed) {
            return true;
        }

        // The same check one level down, for a list of objects such as create-board stages:
        // a misspelled key there is dropped as silently as a misspelled parameter.
        foreach ((array) $schema['properties'] as $name => $property) {
            $itemProperties = isset($property['items']['properties']) ? (array) $property['items']['properties'] : [];
            if (!$itemProperties || !isset($input[$name]) || !is_array($input[$name])) {
                continue;
            }

            $itemAllowed = array_map('strval', array_keys($itemProperties));
            foreach ($input[$name] as $index => $item) {
                if (is_object($item)) {
                    $item = (array) $item;
                }
                if (!is_array($item)) {
                    continue;
                }

                $itemUnknown = array_values(array_diff(array_map('strval', array_keys($item)), $itemAllowed));
                if ($itemUnknown) {
                    return MCPHelper::error('unknown_param', sprintf(
                        '%s[%s] has no property named %s. Use the names in allowed.',
                        $name,
                        $index,
                        implode(', ', $itemUnknown)
                    ), [
                        'fields'  => [(string) $name],
                        'allowed' => $itemAllowed,
                    ]);
                }
            }
        }

        $valid = rest_validate_value_from_schema($input, $schema, 'input');
        if (!is_wp_error($valid)) {
            return true;
        }

        // WordPress names the value "input[stage][0]", or "board_id is a required property of input".
        $reason = $valid->get_error_message();
        $fields = [];
        if (preg_match('/input\[([^\]]+)\]/', $reason, $match) || preg_match('/^(\S+) is (?:a required property|not a valid property)/', $reason, $match)) {
            $fields[] = $match[1];
        }

        $reason = preg_replace('/\binput\[([^\]]+)\]/', '$1', $reason);
        $reason = str_replace([' of input.', ' of input'], ['.', ''], $reason);

        return MCPHelper::invalid($fields, $reason);
    }

    // ---------------------------------------------------------------------
    // Permission callbacks. Each returns true or an error that says what to change.
    // ---------------------------------------------------------------------

    public static function appAccess()
    {
        if (PermissionManager::hasAppAccess()) {
            return true;
        }

        return MCPHelper::error('forbidden', 'Your WordPress user has no access to Fluent Boards. A Fluent Boards admin can add you to a board.');
    }

    public static function anyBoardAccess()
    {
        if (PermissionManager::userHasAnyBoardAccess()) {
            return true;
        }

        return MCPHelper::error('forbidden', 'Your WordPress user is not a member of any board. A Fluent Boards admin can add you to a board.');
    }

    public static function createBoardAccess()
    {
        if (PermissionManager::userHasBoardCreationPermission()) {
            return true;
        }

        return MCPHelper::error('forbidden', 'Your user may not create boards. A Fluent Boards admin can allow it in the member settings.');
    }

    public static function deleteBoardAccess($params = [])
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        if (!$boardId) {
            return MCPHelper::invalid('board_id', 'board_id is required: the id of a board from list-boards.');
        }

        // The same rule as the app: only an admin deletes a board.
        if (!PermissionManager::isAdmin()) {
            return MCPHelper::error('forbidden', 'Only a Fluent Boards admin can delete a board. Ask an admin to delete it in the app.', [
                'fields' => ['board_id'],
            ]);
        }

        return true;
    }

    public static function readBoard($params = [])
    {
        return self::boardAccess($params, false);
    }

    public static function writeBoard($params = [])
    {
        return self::boardAccess($params, true);
    }

    public static function moveTaskAccess($params = [])
    {
        $source = self::boardAccess($params, true);
        if ($source !== true) {
            return $source;
        }

        $targetBoardId = !empty($params['target_board_id']) ? absint($params['target_board_id']) : 0;
        if ($targetBoardId && !MCPHelper::canWriteBoard($targetBoardId)) {
            $error = MCPHelper::boardForbidden($targetBoardId, true);
            $data = $error->get_error_data();

            return MCPHelper::error('forbidden', $data['message'], ['fields' => ['target_board_id']]);
        }

        return true;
    }

    /**
     * @param array $params
     * @param bool  $write
     * @return true|\WP_Error
     */
    public static function boardAccess($params, $write)
    {
        $boardId = isset($params['board_id']) ? absint($params['board_id']) : 0;
        if (!$boardId) {
            return MCPHelper::invalid('board_id', 'board_id is required: the id of a board from list-boards.');
        }

        $allowed = $write ? MCPHelper::canWriteBoard($boardId) : MCPHelper::canReadBoard($boardId);

        return $allowed ? true : MCPHelper::boardForbidden($boardId, $write);
    }

    /**
     * Return every supported preset id plus the explicit value that clears one.
     *
     * @return array
     */
    private static function getLabelColorPresetIds()
    {
        return array_merge([''], array_column(Constant::LABEL_COLOR_PRESETS, 'id'));
    }
}
