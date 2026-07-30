<?php

namespace FluentBoards\App\Modules\MCP;

use FluentBoards\App\Modules\MCP\Tools\BoardTools;
use FluentBoards\App\Modules\MCP\Tools\CommentTools;
use FluentBoards\App\Modules\MCP\Tools\ContextTools;
use FluentBoards\App\Modules\MCP\Tools\LabelTools;
use FluentBoards\App\Modules\MCP\Tools\StageTools;
use FluentBoards\App\Modules\MCP\Tools\TaskQueryTools;
use FluentBoards\App\Modules\MCP\Tools\TaskTools;
use FluentBoards\App\Services\PermissionManager;

/**
 * Single source of truth for Fluent Boards MCP abilities.
 */
class AbilitiesRegistrar
{
    public static function getDefinitions()
    {
        return [
            'fluent-boards/get-fluentboards-context' => [
                'label'       => __('Get FluentBoards Context', 'fluent-boards'),
                'description' => __('Discovery. Returns current user, site metadata, permissions, enums, safety levels, rate hints, and usage guidelines. Call once per session.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
                'execute_callback'    => [ContextTools::class, 'getContext'],
                'permission_callback' => function () {
                    return PermissionManager::hasAppAccess();
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/list-boards' => [
                'label'       => __('List Boards', 'fluent-boards'),
                'description' => __('List boards visible to the current user. Supports search, type, archived filter, pagination, and compact counts.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'           => ['type' => 'string'],
                        'type'             => ['type' => 'string', 'enum' => ['to-do', 'roadmap']],
                        'include_archived' => ['type' => 'boolean', 'default' => false],
                        'sort_by'          => ['type' => 'string', 'enum' => ['id', 'title', 'created_at', 'updated_at'], 'default' => 'created_at'],
                        'sort_type'        => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'page'             => ['type' => 'integer', 'default' => 1],
                        'per_page'         => ['type' => 'integer', 'default' => 20, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [BoardTools::class, 'listBoards'],
                'permission_callback' => function () {
                    return PermissionManager::userHasAnyBoardAccess();
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/get-board' => [
                'label'       => __('Get Board', 'fluent-boards'),
                'description' => __('Board details. Includes stages, labels, members, and optional task summary for one board.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'      => ['type' => 'integer'],
                        'include_tasks' => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [BoardTools::class, 'getBoard'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canReadBoard($params);
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/create-board' => [
                'label'       => __('Create Board', 'fluent-boards'),
                'description' => __('Create a board using Fluent Boards board-creation permission. Accepts custom stages, labels and members; falls back to the default stages and labels when they are omitted. Fires native board-created hooks.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string'],
                        'type'           => ['type' => 'string', 'enum' => ['to-do', 'roadmap'], 'default' => 'to-do'],
                        'currency'       => ['type' => 'string', 'default' => 'USD'],
                        'crm_contact_id' => ['type' => 'integer'],
                        'folder_id'      => ['type' => 'integer', 'description' => 'Optional folder id.'],
                        'stages'         => [
                            'type'        => 'array',
                            'description' => 'Optional stages, in order, for both board types. Omit to get the default stages.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'               => ['type' => 'string'],
                                    'slug'                => ['type' => 'string'],
                                    'position'            => ['type' => 'integer'],
                                    'default_task_status' => [
                                        'type'        => 'string',
                                        'enum'        => ['open', 'closed'],
                                        'description' => 'Status given to tasks landing in this stage. Defaults to open, or closed for stages titled Completed/Done. Ignored for roadmap boards.',
                                    ],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                        'labels'         => [
                            'type'        => 'array',
                            'description' => 'Optional named labels. Omit to get the five default colour labels.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'    => ['type' => 'string'],
                                    'bg_color' => ['type' => 'string', 'description' => 'Hex background colour. Defaults to #f3f4f6.'],
                                    'color'    => ['type' => 'string', 'description' => 'Hex text colour. Defaults to #1B2533.'],
                                ],
                                'required' => ['title'],
                            ],
                        ],
                        'member_ids'     => [
                            'type'        => 'array',
                            'description' => 'Optional WordPress user ids to add as board members. Max 50. The creator is always a member.',
                            'items'       => ['type' => 'integer'],
                            'maxItems'    => 50,
                        ],
                    ],
                    'required' => ['title'],
                ],
                'execute_callback'    => [BoardTools::class, 'createBoard'],
                'permission_callback' => function () {
                    return PermissionManager::userHasBoardCreationPermission();
                },
            ],

            'fluent-boards/list-tasks' => [
                'label'       => __('List Tasks', 'fluent-boards'),
                'description' => __('List/filter tasks in a board. Supports search, stage, status, priority, assignee, labels, due date, archived filter, and pagination.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'         => ['type' => 'integer'],
                        'search'           => ['type' => 'string'],
                        'stage'            => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
                        'task_status'      => ['type' => 'array', 'items' => ['type' => 'string']],
                        'priority'         => ['type' => 'array', 'items' => ['type' => 'string']],
                        'assignee'         => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
                        'labels'           => ['type' => 'array', 'items' => ['type' => ['string', 'integer']]],
                        'due_date'         => ['type' => 'array', 'items' => ['type' => 'string']],
                        'include_archived' => ['type' => 'boolean', 'default' => false],
                        'sort_by'          => ['type' => 'string', 'enum' => ['title', 'status', 'created_at', 'position'], 'default' => 'position'],
                        'sort_direction'   => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'asc'],
                        'page'             => ['type' => 'integer', 'default' => 1],
                        'per_page'         => ['type' => 'integer', 'default' => 20, 'description' => 'Max 100.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'listTasks'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canReadBoard($params);
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/get-task' => [
                'label'       => __('Get Task', 'fluent-boards'),
                'description' => __('Full task details. Includes board, stage, labels, assignees, watchers, comments, and recent activities.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => ['type' => 'integer'],
                        'task_id'  => ['type' => 'integer'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'getTask'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canReadBoard($params);
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/create-task' => [
                'label'       => __('Create Task', 'fluent-boards'),
                'description' => __('Create a task in a board stage. Fires native Fluent Boards task-created hooks and default assignee/watchers logic.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'       => ['type' => 'integer'],
                        'stage_id'       => ['type' => 'integer'],
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string'],
                        'priority'       => ['type' => 'string', 'description' => 'Priority key. Built-ins: urgent, high, medium, low. Custom priority keys registered via fluent_boards/task_priorities are allowed. Pass an empty string for no priority.'],
                        'due_at'         => ['type' => 'string', 'description' => 'Date/time parseable by WordPress. Site timezone.'],
                        'started_at'     => ['type' => 'string', 'description' => 'Date/time parseable by WordPress. Site timezone.'],
                        'crm_contact_id' => ['type' => 'integer'],
                    ],
                    'required' => ['board_id', 'stage_id', 'title'],
                ],
                'execute_callback'    => [TaskTools::class, 'createTask'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/update-task' => [
                'label'       => __('Update Task', 'fluent-boards'),
                'description' => __('Update task fields: title, description, status, priority, due_at, started_at, assignees, crm_contact_id, or settings.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'       => ['type' => 'integer'],
                        'task_id'        => ['type' => 'integer'],
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string'],
                        'status'         => ['type' => 'string', 'enum' => ['open', 'closed']],
                        'priority'       => ['type' => 'string', 'description' => 'Omit to leave unchanged. Pass an empty string to clear. Custom priority keys registered via fluent_boards/task_priorities are allowed.'],
                        'due_at'         => ['type' => 'string', 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
                        'started_at'     => ['type' => 'string', 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
                        'assignees'      => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'crm_contact_id' => ['type' => 'integer', 'description' => 'Omit to leave unchanged. Pass 0 to clear.'],
                        'settings'       => ['type' => 'object'],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'updateTask'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/move-task' => [
                'label'       => __('Move Task', 'fluent-boards'),
                'description' => __('Move a task to another stage or board. Provide position or neighboring task ids for ordering.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'         => ['type' => 'integer', 'description' => 'Current board id.'],
                        'task_id'          => ['type' => 'integer'],
                        'target_board_id'  => ['type' => 'integer', 'description' => 'Optional. Defaults to current board.'],
                        'target_stage_id'  => ['type' => 'integer'],
                        'position'         => ['type' => 'integer', 'description' => '1-based fallback position.'],
                        'previous_task_id' => ['type' => 'integer'],
                        'next_task_id'     => ['type' => 'integer'],
                    ],
                    'required' => ['board_id', 'task_id', 'target_stage_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'moveTask'],
                'permission_callback' => function ($params = []) {
                    return TaskTools::canMoveTask($params);
                },
            ],

            'fluent-boards/archive-task' => [
                'label'       => __('Archive or Restore Task', 'fluent-boards'),
                'description' => __('Archive or restore one task. Set archived=true to archive, false to restore.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'  => ['type' => 'integer'],
                        'task_id'   => ['type' => 'integer'],
                        'archived'  => ['type' => 'boolean', 'default' => true],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'archiveTask'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/add-comment' => [
                'label'       => __('Add Comment', 'fluent-boards'),
                'description' => __('Add a private or public comment to a task. Fires native comment-created hooks and notifications.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'     => ['type' => 'integer'],
                        'task_id'      => ['type' => 'integer'],
                        'description'  => ['type' => 'string'],
                        'privacy'      => ['type' => 'string', 'enum' => ['private', 'public'], 'default' => 'private'],
                        'mentioned_ids'=> ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                    'required' => ['board_id', 'task_id', 'description'],
                ],
                'execute_callback'    => [CommentTools::class, 'addComment'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/list-labels' => [
                'label'       => __('List Labels', 'fluent-boards'),
                'description' => __('List the labels of a board, optionally only those already used by tasks.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'  => ['type' => 'integer'],
                        'only_used' => ['type' => 'boolean', 'default' => false, 'description' => 'Return only labels currently attached to at least one task.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'listLabels'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canReadBoard($params);
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/save-label' => [
                'label'       => __('Create or Update Label', 'fluent-boards'),
                'description' => __('Create a board label, or update an existing one when label_id is given. Only supplied fields change. Fires native board-label hooks.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => ['type' => 'integer'],
                        'label_id' => ['type' => 'integer', 'description' => 'Omit to create a new label.'],
                        'title'    => ['type' => 'string', 'description' => 'Required when creating unless bg_color is given.'],
                        'bg_color' => ['type' => 'string', 'description' => 'Hex background colour. Defaults to #f3f4f6 on create.'],
                        'color'    => ['type' => 'string', 'description' => 'Hex text colour. Defaults to #1B2533 on create.'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'saveLabel'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/delete-label' => [
                'label'       => __('Delete Label', 'fluent-boards'),
                'description' => __('Delete a board label and detach it from every task that carries it.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => ['type' => 'integer'],
                        'label_id' => ['type' => 'integer'],
                    ],
                    'required' => ['board_id', 'label_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'deleteLabel'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/add-task-label' => [
                'label'       => __('Add Task Label', 'fluent-boards'),
                'description' => __('Attach one or more existing board labels to a task, by id or by title.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'     => ['type' => 'integer'],
                        'task_id'      => ['type' => 'integer'],
                        'label_id'     => ['type' => 'integer', 'description' => 'Single label id. Combine with label_ids/label_titles if needed.'],
                        'label_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50],
                        'label_titles' => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'maxItems'    => 50,
                            'description' => 'Label titles on the same board, matched case-insensitively. Unknown titles are an error; create them with create-label first.',
                        ],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'addTaskLabel'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/remove-task-label' => [
                'label'       => __('Remove Task Label', 'fluent-boards'),
                'description' => __('Detach one or more labels from a task, by id or by title. The board label itself is kept.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'     => ['type' => 'integer'],
                        'task_id'      => ['type' => 'integer'],
                        'label_id'     => ['type' => 'integer'],
                        'label_ids'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50],
                        'label_titles' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 50],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [LabelTools::class, 'removeTaskLabel'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/list-my-tasks' => [
                'label'       => __('List My Tasks', 'fluent-boards'),
                'description' => __('List tasks across every board the caller can see, grouped by the dashboard categories: assigned, mentioned, upcoming, due today, overdue, completed, or others (no due date).', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'user_id'   => ['type' => 'integer', 'description' => 'Defaults to the current user. Results stay limited to boards the caller can access.'],
                        'task_type' => [
                            'type'    => 'string',
                            'enum'    => TaskQueryTools::TASK_TYPES,
                            'default' => 'assigned',
                        ],
                        'board_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional board filter.'],
                        'order_by'  => ['type' => 'string', 'enum' => TaskQueryTools::ORDER_BY, 'default' => 'due_at'],
                        'order'     => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'ASC'],
                        'page'      => ['type' => 'integer', 'default' => 1],
                        'per_page'  => ['type' => 'integer', 'default' => 20, 'description' => 'Max 50.'],
                    ],
                ],
                'execute_callback'    => [TaskQueryTools::class, 'listMyTasks'],
                'permission_callback' => function () {
                    return PermissionManager::hasAppAccess();
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/search-tasks' => [
                'label'       => __('Search Tasks', 'fluent-boards'),
                'description' => __('Search tasks by title across every board the caller can see. Terms need at least three characters. Supports the "id:123" exact lookup and "archived:term" prefixes used by the product search box.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'            => ['type' => 'string', 'minLength' => 3, 'description' => 'At least three characters, or an "id:123" lookup.'],
                        'board_ids'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Optional board filter.'],
                        'include_archived' => ['type' => 'boolean', 'default' => false],
                        'page'             => ['type' => 'integer', 'default' => 1],
                        'per_page'         => ['type' => 'integer', 'default' => 20, 'description' => 'Max 50.'],
                    ],
                    'required' => ['query'],
                ],
                'execute_callback'    => [TaskQueryTools::class, 'searchTasks'],
                'permission_callback' => function () {
                    return PermissionManager::hasAppAccess();
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-boards/save-stage' => [
                'label'       => __('Create or Update Stage', 'fluent-boards'),
                'description' => __('Create a stage, or update an existing one when stage_id is given. Only supplied fields change.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id'            => ['type' => 'integer'],
                        'stage_id'            => ['type' => 'integer', 'description' => 'Omit to create a new stage.'],
                        'title'               => ['type' => 'string', 'description' => 'Required when creating.'],
                        'position'            => ['type' => 'integer', 'description' => '1-based position on the board.'],
                        'default_task_status' => ['type' => 'string', 'enum' => ['open', 'closed'], 'description' => 'Status given to tasks landing in this stage.'],
                        'bg_color'            => ['type' => 'string'],
                        'color'               => ['type' => 'string'],
                    ],
                    'required' => ['board_id'],
                ],
                'execute_callback'    => [StageTools::class, 'saveStage'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/archive-stage' => [
                'label'       => __('Archive Stage', 'fluent-boards'),
                'description' => __('Archive or restore a stage. Archived stages keep their tasks; set archived=false to restore at the end of the board.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => ['type' => 'integer'],
                        'stage_id' => ['type' => 'integer'],
                        'archived' => ['type' => 'boolean', 'default' => true],
                    ],
                    'required' => ['board_id', 'stage_id'],
                ],
                'execute_callback'    => [StageTools::class, 'archiveStage'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],

            'fluent-boards/assign-task' => [
                'label'       => __('Assign Task', 'fluent-boards'),
                'description' => __('Add, remove, or sync task assignees. User ids must belong to existing WordPress users. Adding someone already assigned is a no-op. Assignees also become task watchers.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'board_id' => ['type' => 'integer'],
                        'task_id'  => ['type' => 'integer'],
                        'user_id'  => ['type' => 'integer', 'description' => 'Single user id. Ignored when user_ids is provided.'],
                        'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'maxItems' => 50],
                        'mode'     => [
                            'type'        => 'string',
                            'enum'        => ['add', 'remove', 'sync'],
                            'default'     => 'add',
                            'description' => 'sync makes the assignee list exactly match the ids given. Pass user_ids: [] with sync to clear all assignees.',
                        ],
                    ],
                    'required' => ['board_id', 'task_id'],
                ],
                'execute_callback'    => [TaskTools::class, 'assignTask'],
                'permission_callback' => function ($params = []) {
                    return BoardTools::canWriteBoard($params);
                },
            ],
        ];
    }

    public static function register()
    {
        foreach (self::getDefinitions() as $name => $definition) {
            $args = [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => 'fluent-boards',
                'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback']),
                'permission_callback' => $definition['permission_callback'],
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

    private static function wrapExecuteCallback($toolName, $callback)
    {
        return function ($params) use ($toolName, $callback) {
            try {
                return call_user_func($callback, is_array($params) ? $params : []);
            } catch (\Throwable $e) {
                do_action('fluent_boards/mcp_tool_exception', $e, $toolName, $params);

                $details = [
                    'tool'      => $toolName,
                    'exception' => get_class($e),
                ];

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    $details['file'] = $e->getFile() . ':' . $e->getLine();
                    $details['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
                }

                return new \WP_Error('failed', $e->getMessage(), $details);
            }
        };
    }
}
