<?php

namespace FluentBoards\App\Modules\MCP\Tools;

use FluentBoards\App\Modules\MCP\Helpers\MCPHelper;
use FluentBoards\App\Services\CommentService;
use FluentBoards\App\Services\NotificationService;

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
            return MCPHelper::boardForbidden($task->board_id, true);
        }

        $description = isset($params['description']) ? wp_kses_post($params['description']) : '';
        if (trim(wp_strip_all_tags($description)) === '') {
            return MCPHelper::invalid('description', 'description is required: the text of the comment.');
        }

        $privacy = !empty($params['privacy']) ? $params['privacy'] : 'private';
        if (!in_array($privacy, ['private', 'public'], true)) {
            return MCPHelper::notOneOf('privacy', ['private', 'public']);
        }

        $mentionedIds = MCPHelper::sanitizeIdArray($params['mentioned_ids'] ?? []);
        $notificationService = new NotificationService();
        $boardMemberIds = $notificationService->resolveBoardMentionUserIds($task->board_id, $mentionedIds);
        $notMembers = array_values(array_diff($mentionedIds, $boardMemberIds));
        if ($notMembers) {
            return MCPHelper::invalid('mentioned_ids', sprintf(
                'User %s is not a member of board %d and cannot be mentioned. Use ids from the members of get-board.',
                implode(', ', $notMembers),
                (int) $task->board_id
            ), ['non_board_member_user_ids' => $notMembers]);
        }

        $mentionedIds = $boardMemberIds;
        $commentService = new CommentService();

        $comment = $commentService->create([
            'board_id'     => (int) $task->board_id,
            'task_id'      => (int) $task->id,
            'type'         => 'comment',
            'privacy'      => $privacy,
            'status'       => 'published',
            'description'  => $commentService->processMentionAndLink($description, $mentionedIds),
            'settings'     => [
                'mentioned_id' => $mentionedIds,
                'source'       => 'mcp',
            ],
            'created_by'   => get_current_user_id(),
        ], $task->id);

        if ($mentionedIds) {
            $notificationService->mentionInComment($comment, $mentionedIds);
        }

        return [
            'comment' => [
                'id'         => (int) $comment->id,
                'board_id'   => (int) $comment->board_id,
                'task_id'    => (int) $comment->task_id,
                'privacy'    => $comment->privacy,
                'created_at' => MCPHelper::toIso8601($comment->created_at),
            ],
        ];
    }
}
