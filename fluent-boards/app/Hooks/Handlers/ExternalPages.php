<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Attachment;
use FluentBoards\App\App;
use FluentBoards\App\Models\CommentImage;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Libs\FileSystem;

class ExternalPages
{
    public function view_uploaded_comment_image()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public file serving endpoint, security validated via hash
        $attachmentHash = isset($_REQUEST['fbs_comment_image']) ? sanitize_text_field(wp_unslash($_REQUEST['fbs_comment_image'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public file serving endpoint, security validated via hash
        $boardId = isset($_REQUEST['fbs_bid']) ? sanitize_text_field(wp_unslash($_REQUEST['fbs_bid'])) : '';

        if (empty($attachmentHash)) {
            die(esc_html__('Invalid Attachment Hash', 'fluent-boards'));
        }

        $attachment = $this->getUploadedImageByHash($attachmentHash);

        if (!$attachment) {
            die(esc_html__('Invalid Attachment Hash', 'fluent-boards'));
        }

        if ('local' !== $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->redirectToExternalAttachment($attachment->full_url);
            }else{
                die(esc_html__('File could not be found', 'fluent-boards'));
            }
            return;
        }
        $fileName = $attachment->file_path;
        $boardId = $boardId;
        $filePath = $fileName;
        if(!file_exists($fileName)){
            $filePath = FileSystem::setSubDir('board_' . $boardId)->getDir() . DIRECTORY_SEPARATOR . $fileName;
        }

        if (!file_exists($filePath)) {
            die(esc_html__('File could not be found.', 'fluent-boards'));
        }

        $this->serveLocalAttachment($attachment, $filePath);
    }

    public function view_comment_image()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public file serving endpoint, security validated via hash and signature
        $attachmentHash = isset($_REQUEST['fbs_comment_image']) ? sanitize_text_field(wp_unslash($_REQUEST['fbs_comment_image'])) : '';

        if (empty($attachmentHash)) {
            die(esc_html__('Invalid Attachment Hash', 'fluent-boards'));
        }

        $attachment = $this->getUploadedImageByHash($attachmentHash);

        if (in_array($attachment->object_type, [Constant::COMMENT_IMAGE])) {
            $attachment->load('comment');
        } elseif (in_array($attachment->object_type, [Constant::TASK_DESCRIPTION])) {
            $attachment['task'] = Task::find($attachment->object_id);
        }

        if (!$attachment) {
            die(esc_html__('Invalid Attachment Hash', 'fluent-boards'));
        }

        // check signature hash
        if (!$this->validateAttachmentSignature($attachment)) {
            die(esc_html__('Sorry, Your secure sign is invalid, Please reload the previous page and get new signed url', 'fluent-boards'));
        }

        //If external file
        if ('local' !== $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->redirectToExternalAttachment($attachment->full_url);
            }else{
                die(esc_html__('File could not be found', 'fluent-boards'));
            }
        }

        //Handle Local file
        if (in_array($attachment->object_type, [Constant::COMMENT_IMAGE])) {
            $fileName = $attachment->file_path;
            $boardId = $attachment->comment->board_id;
        } elseif (in_array($attachment->object_type, [Constant::TASK_DESCRIPTION])) {
            $fileName = $attachment->file_path;
            $boardId = $attachment->task->board_id;
        }

        $filePath = $fileName;
        if(!file_exists($fileName)){
            $filePath = FileSystem::setSubDir('board_' . $boardId)->getDir() . DIRECTORY_SEPARATOR . $fileName;
        }

        if (!file_exists($filePath)) {
            die(esc_html__('File could not be found.', 'fluent-boards'));
        }

        $this->serveLocalAttachment($attachment, $filePath);
    }

    private function getUploadedImageByHash($attachmentHash)
    {
        return CommentImage::where('file_hash', $attachmentHash)->first();
    }

    private function serveLocalAttachment($attachment, $filePath)
    {
        ob_get_clean();
        header("Content-Type: {$attachment->attachment_type}");
        header("Content-Disposition: inline; filename=\"{$attachment->title}\"");;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Serving binary file content directly to browser, WP_Filesystem not suitable for this use case
        readfile($filePath);
        die();
    }

    private function validateAttachmentSignature($attachment)
    {
        $sign = md5($attachment->id . gmdate('YmdH'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Signature validation serves as security mechanism
        $requestSign = isset($_REQUEST['secure_sign']) ? sanitize_text_field(wp_unslash($_REQUEST['secure_sign'])) : '';
        return $sign === $requestSign;
    }

    public function redirectToPage()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public redirect endpoint, no sensitive operations
        $taskId = isset($_GET['taskId']) ? absint(wp_unslash($_GET['taskId'])) : 0;
        
        if (!$taskId) {
            wp_die(esc_html__('Invalid task ID', 'fluent-boards'));
        }
        
        $task = Task::findOrFail($taskId);
        if ($this->isFrontendEnabled() == 'no') {
            $urlBase = apply_filters('fluent_boards/app_url', admin_url('admin.php?page=fluent-boards#/'));
            $page_url = $urlBase . 'boards/' . $task->board_id . '/tasks/' . $task->id . '-' .substr($task->title, 0, 10);
            wp_redirect($page_url);
            exit;
        } else {
            $urlBase = apply_filters('fluent_boards/app_url');
            $page_url = $urlBase . 'boards/' . $task->board_id . '/tasks/' . $task->id . '-' .substr($task->title, 0, 10);
            wp_redirect($page_url);
            exit;
        }

        die();
    }

    private function isFrontendEnabled()
    {
        $storedSettings = get_option('fluent_boards_modules', []);
        if ($storedSettings && is_array($storedSettings)) {
            $settings = maybe_serialize($storedSettings);
        }

        if (isset($settings['frontend']['enabled'])) {
            return $settings['frontend']['enabled'];
        }

        return 'no';
    }
    private function redirectToExternalAttachment($redirectUrl)
    {
        wp_redirect($redirectUrl, 307);
        exit();
    }
}