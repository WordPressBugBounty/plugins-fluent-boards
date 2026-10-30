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

        // Logged-out access through a public board or a task share link.
        if ($this->canViewPublicly($attachment)) {
            return $boardId;
        }

        return 0;
    }

    /**
     * Whether a visitor without board access may load the attachment through a public board
     * or a task share link. Each attachment kind follows the setting that exposes its content.
     */
    public function canViewPublicly(Attachment $attachment)
    {
        if (empty($attachment->object_id)) {
            return false;
        }

        // The public board view is a Pro feature; Pro answers these two filters.
        if ($attachment->object_type === Constant::BOARD_BACKGROUND_IMAGE) {
            $board = Board::find($attachment->object_id);
            return $board && (bool)apply_filters('fluent_boards/public_board_background_visible', false, $board);
        }

        list($task, $feature) = $this->getAttachmentTaskFeature($attachment);
        if (!$task) {
            return false;
        }

        if (apply_filters('fluent_boards/public_board_task_feature_visible', false, $task, $feature)) {
            return true;
        }

        return (new TaskShareService())->isTaskFeatureVisible($task, $feature);
    }

    /**
     * The task an attachment belongs to and the public setting that exposes it, or [null, ''].
     */
    private function getAttachmentTaskFeature(Attachment $attachment)
    {
        if ($attachment->object_type === Constant::COMMENT_IMAGE) {
            $comment = Comment::withoutGlobalScopes()->find($attachment->object_id);
            if (!$comment || !in_array($comment->type, PublicTaskSerializer::PUBLIC_COMMENT_TYPES, true)) {
                return [null, ''];
            }
            return [Task::find($comment->task_id), 'task_comments'];
        }

        if ($attachment->object_type === Constant::TASK_DESCRIPTION) {
            return [Task::find($attachment->object_id), 'task_description'];
        }

        if ($attachment->object_type === Constant::TASK_ATTACHMENT) {
            return [Task::find($attachment->object_id), 'task_attachments'];
        }

        return [null, ''];
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
