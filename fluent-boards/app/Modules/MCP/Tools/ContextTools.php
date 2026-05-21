<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;

/**
 * Discovery context for MCP agents.
 */
class ContextTools
{
    const CACHE_TTL = 60;

    public static function getContext($params = [])
    {
        $userId = get_current_user_id();
        $cacheKey = 'fluent_boards_mcp_context_' . $userId;

        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $context = self::buildContext();
        set_transient($cacheKey, $context, self::CACHE_TTL);

        return $context;
    }

    public static function invalidateCache()
    {
        global $wpdb;

        $like = $wpdb->esc_like('_transient_fluent_boards_mcp_context_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));

        $like = $wpdb->esc_like('_transient_timeout_fluent_boards_mcp_context_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    }

    private static function buildContext()
    {
        $boardIds = \FluentBoards\App\Services\PermissionManager::getBoardIdsForUser();

        return [
            'you'             => MCPHelper::currentUser(),
            'site'            => [
                'site_url'              => site_url(),
                'fluent_boards_version' => defined('FLUENT_BOARDS_PLUGIN_VERSION') ? FLUENT_BOARDS_PLUGIN_VERSION : null,
                'timezone'              => wp_timezone_string(),
                'current_time'          => current_time('mysql'),
                'mcp_endpoint'          => \FluentBoards\App\Modules\MCP\MCPInit::getEndpointUrl(),
            ],
            'stats'           => [
                'accessible_boards' => count($boardIds),
                'active_boards'     => $boardIds ? (int) Board::whereIn('id', $boardIds)->whereNull('archived_at')->count() : 0,
                'active_stages'     => $boardIds ? (int) Stage::whereIn('board_id', $boardIds)->whereNull('archived_at')->count() : 0,
                'active_tasks'      => $boardIds ? (int) Task::whereIn('board_id', $boardIds)->whereNull('archived_at')->whereNull('parent_id')->count() : 0,
            ],
            'enums'           => [
                'board_types'      => ['to-do', 'roadmap'],
                'task_statuses'    => ['open', 'closed', 'archived'],
                'stage_statuses'   => ['open', 'closed'],
                'priorities'       => ['low', 'medium', 'high'],
                'comment_privacy'  => ['private', 'public'],
                'sort_directions'  => ['ASC', 'DESC', 'asc', 'desc'],
            ],
            'safety_levels'   => self::buildSafetyLevels(),
            'rate_hints'      => [
                'fluent-boards/list-boards' => ['max_per_page' => 100],
                'fluent-boards/list-tasks'  => ['max_per_page' => 100],
                'fluent-boards/get-board'   => ['include_tasks_limited_to' => 100],
            ],
            'mcp_capabilities'=> [
                'version' => '1.0.0',
                'supports' => [
                    'dedicated_mcp_server',
                    'board_access_scoping',
                    'board_create',
                    'viewer_only_write_block',
                    'task_create_update_move_archive',
                    'task_comments',
                    'compact_context',
                ],
            ],
            'guidelines'      => [
                'Call get-fluentboards-context once per session before choosing write tools.',
                'Use list-boards before board-scoped tools unless the user already gave a board_id.',
                'Write tools follow Fluent Boards permissions: viewer-only board users can read but cannot mutate.',
                'Use get-task before risky edits when the requested task identity is ambiguous.',
            ],
        ];
    }

    private static function buildSafetyLevels()
    {
        return [
            'fluent-boards/get-fluentboards-context' => 'readonly',
            'fluent-boards/list-boards'        => 'readonly',
            'fluent-boards/get-board'          => 'readonly',
            'fluent-boards/create-board'       => 'creates_or_mutates',
            'fluent-boards/list-tasks'         => 'readonly',
            'fluent-boards/get-task'           => 'readonly',
            'fluent-boards/create-task'        => 'creates_or_mutates',
            'fluent-boards/update-task'        => 'creates_or_mutates',
            'fluent-boards/move-task'          => 'creates_or_mutates',
            'fluent-boards/archive-task'       => 'creates_or_mutates',
            'fluent-boards/add-comment'        => 'creates_or_mutates',
        ];
    }
}
