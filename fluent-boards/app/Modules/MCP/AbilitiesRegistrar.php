<?php

namespace FluentBoards\App\Modules\MCP;

use FluentBoards\App\Modules\MCP\Tools\BoardTools;
use FluentBoards\App\Modules\MCP\Tools\CommentTools;
use FluentBoards\App\Modules\MCP\Tools\ContextTools;
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
                'description' => __('Create a board using Fluent Boards board-creation permission. Adds default labels and stages, and fires native board-created hooks.', 'fluent-boards'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'          => ['type' => 'string'],
                        'description'    => ['type' => 'string'],
                        'type'           => ['type' => 'string', 'enum' => ['to-do', 'roadmap'], 'default' => 'to-do'],
                        'currency'       => ['type' => 'string', 'default' => 'USD'],
                        'crm_contact_id' => ['type' => 'integer'],
                        'folder_id'      => ['type' => 'integer', 'description' => 'Optional Pro folder id. Ignored when Fluent Boards Pro is unavailable.'],
                        'stages'         => [
                            'type'        => 'array',
                            'description' => 'Optional roadmap stages. To-do boards use the normal default stages.',
                            'items'       => [
                                'type'       => 'object',
                                'properties' => [
                                    'title'    => ['type' => 'string'],
                                    'slug'     => ['type' => 'string'],
                                    'position' => ['type' => 'integer'],
                                ],
                                'required' => ['title'],
                            ],
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
                        'priority'       => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
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
                        'priority'       => ['type' => 'string', 'enum' => ['low', 'medium', 'high'], 'description' => 'Omit to leave unchanged. Pass an empty string to clear.'],
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
