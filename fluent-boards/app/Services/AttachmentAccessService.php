<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;

class AttachmentAccessService
{
    /**
     * Return the attachment's trusted board ID if the current visitor may read it, or zero.
     * URL hashes and legacy signatures identify images; they never grant access.
     */
    public function getAccessibleBoardId(Attachment $attachment)
    {
        $boardId = $this->getBoardId($attachment);
        if (!$boardId) {
            return 0;
        }

        $board = Board::find($boardId);
        if (!$board) {
            return 0;
        }

        // Unsaved uploads remain private to their uploader, who must still have board access.
        if (empty($attachment->object_id)) {
            $settings = $attachment->settings;
            if (absint($settings[Constant::ATTACHMENT_UPLOAD_USER_ID] ?? 0) !== get_current_user_id()) {
                return 0;
            }

            return PermissionManager::userHasPermission($boardId) ? $boardId : 0;
        }

        if (PermissionManager::userHasPermission($boardId)) {
            return $boardId;
        }

        // PublicBoardController exposes board backgrounds, but hides task descriptions and comments.
        if ($attachment->object_type === Constant::BOARD_BACKGROUND_IMAGE
            && !$board->archived_at
            && $board->getMetaByKey('public_access_enabled')) {
            return $boardId;
        }

        return 0;
    }

    private function getBoardId(Attachment $attachment)
    {
        if ($attachment->object_type === Constant::COMMENT_IMAGE) {
            if (empty($attachment->object_id)) {
                $settings = $attachment->settings;
                return absint($settings[Constant::ATTACHMENT_UPLOAD_BOARD_ID] ?? 0);
            }

            $comment = Comment::withoutGlobalScopes()->find($attachment->object_id);
            return $comment ? absint($comment->board_id) : 0;
        }

        if ($attachment->object_type === Constant::TASK_DESCRIPTION) {
            $task = Task::find($attachment->object_id);
            return $task ? absint($task->board_id) : 0;
        }

        if ($attachment->object_type === Constant::BOARD_BACKGROUND_IMAGE) {
            return absint($attachment->object_id);
        }

        return 0;
    }
}
