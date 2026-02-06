<?php

namespace FluentBoards\App\Services;


use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\Framework\Support\Arr;

class UploadService
{

    /**
     * @throws \Exception
     */
    public static function handleFileUpload($file, $boardId, $taskId = null)
    {
        $uploadInfo = FileSystem::setSubDir('board_'.$boardId)->put($file);

        if (!empty($uploadInfo) && is_array($uploadInfo)) {
            return $uploadInfo;
        }

        return new \WP_Error('file_upload_error', __('File upload failed', 'fluent-boards'));
    }

    public function validateFile($file)
    {
        if (!$file) {
            throw new \Exception(esc_html__('File is empty.', 'fluent-boards'));
        }
        if (!$this->isFileTypeSupported($file)) {
            throw new \Exception(esc_html__('File type not supported', 'fluent-boards'));
        }
        if ($file['size_in_bytes'] > $this->getFileUploadLimit()) {
            throw new \Exception(esc_html__('File size is too large', 'fluent-boards'));
        }
    }

    public function getFileUploadLimit() {
        // Logic for calculating file upload limit as in your original code
        return min(
            wp_convert_hr_to_bytes(ini_get('upload_max_filesize')),
            wp_convert_hr_to_bytes(ini_get('post_max_size')),
            wp_max_upload_size()
        );
    }

    public function isFileTypeSupported($file)
    {
        // Define supported file types that are generally allowed by user
        $allowedMimeTypes = get_allowed_mime_types();
        // Check if the file type is supported
        return in_array(strtolower($file['type']), $allowedMimeTypes);
    }

}