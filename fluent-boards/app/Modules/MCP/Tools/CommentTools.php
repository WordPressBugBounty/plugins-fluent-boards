<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\CommentService;

/**
 * Comment write tools.
 */
class CommentTools
{
    public static function addComment($params = [])
    {
        $task = MCPHelper::resolveTask($params);
        if (is_wp_error($task)) {
            return $task;
        }

        if (!MCPHelper::canWriteBoard($task->board_id)) {
            return MCPHelper::error('forbidden', __('You do not have permission to comment on this task', 'fluent-boards'));
        }

        $description = isset($params['description']) ? wp_kses_post($params['description']) : '';
        if ($description === '') {
            return MCPHelper::error('invalid_param', __('Comment description is required', 'fluent-boards'));
        }

        $privacy = !empty($params['privacy']) && $params['privacy'] === 'public' ? 'public' : 'private';
        $mentionedIds = MCPHelper::sanitizeIdArray($params['mentioned_ids'] ?? []);
        $commentService = new CommentService();

        $comment = $commentService->create([
            'board_id'     => (int) $task->board_id,
            'task_id'      => (int) $task->id,
            'type'         => 'comment',
            'privacy'      => $privacy,
            'status'       => 'published',
            'description'  => $commentService->processMentionAndLink($description, $mentionedIds),
            'settings'     => [
                'mentioned_users' => $mentionedIds,
                'source'          => 'mcp',
            ],
            'created_by'   => get_current_user_id(),
        ], $task->id);

        return [
            'comment' => [
                'id'          => (int) $comment->id,
                'board_id'    => (int) $comment->board_id,
                'task_id'     => (int) $comment->task_id,
                'privacy'     => $comment->privacy,
                'status'      => $comment->status,
                'description' => $comment->description,
                'created_by'  => $comment->created_by ? (int) $comment->created_by : null,
                'created_at'  => MCPHelper::toIso8601($comment->created_at),
            ],
            'message' => __('Comment has been added', 'fluent-boards'),
        ];
    }
}
