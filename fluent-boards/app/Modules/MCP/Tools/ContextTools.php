<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Modules\MCP\MCPInit;
use FluentBoards\App\Services\PermissionManager;

/**
 * Discovery context for MCP agents: the first call of a session.
 */
class ContextTools
{
    const BOARDS_LIMIT = 30;

    /**
     * Who the agent acts as, the site clock, the boards it can use and the rules.
     *
     * Not cached: the clock must be current, and a board the agent just created must be in
     * the list. The cost is two small queries.
     */
    public static function getContext($params = [])
    {
        $userId = get_current_user_id();
        $user = $userId ? get_user_by('ID', $userId) : null;
        $boardIds = PermissionManager::getBoardIdsForUser($userId);

        $boards = [];
        $boardsTotal = 0;
        if ($boardIds) {
            $query = Board::whereIn('id', $boardIds)->whereNull('archived_at');
            $boardsTotal = (int) (clone $query)->count();

            foreach ($query->orderBy('updated_at', 'DESC')->limit(self::BOARDS_LIMIT)->get(['id', 'title', 'type']) as $board) {
                $boards[] = [
                    'id'    => (int) $board->id,
                    'title' => $board->title,
                    'type'  => $board->type,
                ];
            }
        }

        if ($boardsTotal > count($boards)) {
            MCPHelper::addWarning(sprintf(
                'boards holds the %d most recently changed of %d boards. list-boards returns the rest and has a title search.',
                count($boards),
                $boardsTotal
            ));
        }

        $context = [
            'you'          => [
                'user_id'           => (int) $userId,
                'name'              => $user ? $user->display_name : null,
                'email'             => $user ? $user->user_email : null,
                'is_admin'          => PermissionManager::isAdmin($userId),
                'can_create_boards' => PermissionManager::userHasBoardCreationPermission($userId),
            ],
            'site'         => [
                'url'          => site_url(),
                'timezone'     => MCPHelper::siteTimezoneName(),
                'current_time' => current_datetime()->format('c'),
                'week_starts'  => 'Sunday',
                'version'      => defined('FLUENT_BOARDS_PLUGIN_VERSION') ? FLUENT_BOARDS_PLUGIN_VERSION : null,
            ],
            'boards'       => $boards,
            'boards_total' => $boardsTotal,
            'priorities'   => TaskTools::getAllowedTaskPriorities(),
            'rules'        => MCPInit::rules(),
        ];

        /**
         * Filter the context the first MCP call returns. Fluent Boards Pro adds its part here.
         *
         * @since 2.3.1
         *
         * @param array $context
         */
        return (array) apply_filters('fluent_boards/mcp_context', $context);
    }

    /**
     * Kept for callers of earlier releases. The context is no longer cached.
     *
     * @deprecated 2.3.1
     */
    public static function invalidateCache()
    {
    }
}
