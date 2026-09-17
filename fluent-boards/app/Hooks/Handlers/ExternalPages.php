<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\CommentImage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\AttachmentAccessService;
use FluentBoards\App\Services\Libs\FileSystem;

class ExternalPages
{
    public function view_uploaded_comment_image()
    {
        nocache_headers();
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only endpoint; current permissions are checked for every request
        $attachmentHash = isset($_REQUEST['fbs_comment_image']) && is_string($_REQUEST['fbs_comment_image'])
            ? sanitize_text_field(wp_unslash($_REQUEST['fbs_comment_image'])) : '';

        if (empty($attachmentHash)) {
            wp_die(esc_html__('Invalid Attachment Hash', 'fluent-boards'), '', ['response' => 404]);
        }

        $attachment = $this->getUploadedImageByHash($attachmentHash);

        if (!$attachment) {
            wp_die(esc_html__('Invalid Attachment Hash', 'fluent-boards'), '', ['response' => 404]);
        }

        $boardId = (new AttachmentAccessService())->getAccessibleBoardId($attachment);
        if (!$boardId) {
            wp_die(esc_html__('You do not have permission to view this image.', 'fluent-boards'), '', ['response' => 403]);
        }

        // Old URLs may include a board ID, but it can never choose the file directory.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Optional compatibility parameter is checked against the attachment's board
        if (isset($_REQUEST['fbs_bid']) && (!is_scalar($_REQUEST['fbs_bid']) || absint(wp_unslash($_REQUEST['fbs_bid'])) !== $boardId)) {
            wp_die(esc_html__('You do not have permission to view this image.', 'fluent-boards'), '', ['response' => 403]);
        }

        if ('local' !== $attachment->driver) {
            if(!empty($attachment->file_path)){
                $this->redirectToExternalAttachment($attachment->full_url);
            }else{
                wp_die(esc_html__('File could not be found', 'fluent-boards'), '', ['response' => 404]);
            }
            return;
        }

        // Preserve legacy absolute paths inside plugin uploads; bare filenames use the trusted board.
        $filePath = FileSystem::resolveLocalAttachmentPath($attachment->file_path)
            ?: FileSystem::resolveLocalAttachmentPath($attachment->file_path, $boardId);

        if (!$filePath || !is_readable($filePath)) {
            wp_die(esc_html__('File could not be found.', 'fluent-boards'), '', ['response' => 404]);
        }

        $this->serveLocalAttachment($attachment, $filePath);
    }

    public function view_comment_image()
    {
        $this->view_uploaded_comment_image();
    }

    private function getUploadedImageByHash($attachmentHash)
    {
        return CommentImage::where('file_hash', $attachmentHash)->first();
    }

    private function serveLocalAttachment($attachment, $filePath)
    {
        if (ob_get_level()) {
            ob_end_clean();
        }
        header("Content-Type: {$attachment->attachment_type}");
        header("Content-Disposition: inline; filename=\"{$attachment->title}\"");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Serving binary file content directly to browser, WP_Filesystem not suitable for this use case
        readfile($filePath);
        die();
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
            $urlBase = apply_filters('fluent_boards/app_url', admin_url('admin.php?page=fluent-boards#/'));
            $page_url = $urlBase . 'boards/' . $task->board_id . '/tasks/' . $task->id . '-' .substr($task->title, 0, 10);
            wp_redirect($page_url);
            exit;
        }

        die();
    }

    private function isFrontendEnabled()
    {
        $storedSettings = get_option('fluent_boards_modules', []);
        $settings = is_string($storedSettings) ? maybe_unserialize($storedSettings) : $storedSettings;

        if (is_array($settings) && isset($settings['frontend']['enabled'])) {
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
