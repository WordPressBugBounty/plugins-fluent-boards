<?php

namespace FluentBoards\App\Services;


use FluentBoards\App\Services\Libs\FileSystem;
use FluentBoards\Framework\Support\Arr;

class UploadService
{
    const MAX_FILE_UPLOAD_BYTES = 104857600;

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
        return (int) apply_filters('fluent_boards/upload_file_size_limit', self::MAX_FILE_UPLOAD_BYTES);
    }

    public function isFileTypeSupported($file)
    {
        // Validate by extension against our allow-list. This is more reliable than the
        // browser-provided mime, which is empty/inconsistent for types like .json and .md.
        $extension = strtolower(pathinfo(Arr::get($file, 'name', ''), PATHINFO_EXTENSION));
        if (!$extension) {
            return false;
        }

        foreach (array_keys(self::getAllowedMimeMap()) as $extensionPattern) {
            if (in_array($extension, explode('|', $extensionPattern), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allow-list of upload types as an extension => mime map (WordPress defaults plus
     * common developer/document formats). Shared by validateFile() and wp_handle_upload()
     * so both agree. Executable/script types are intentionally excluded.
     *
     * @return array
     */
    public static function getAllowedMimeMap()
    {
        $extraMimes = [
            'json'      => 'application/json',
            'md'        => 'text/markdown',
            'markdown'  => 'text/markdown',
            'csv'       => 'text/csv',
            'txt'       => 'text/plain',
            'log'       => 'text/plain',
            'xml'       => 'text/xml',
            'yaml|yml'  => 'text/yaml',
            'webp'      => 'image/webp',
            'avif'      => 'image/avif',
            'heic'      => 'image/heic',
            'zip'       => 'application/zip',
            'rar'       => 'application/vnd.rar',
            '7z'        => 'application/x-7z-compressed',
            'tar'       => 'application/x-tar',
            'gz|gzip'   => 'application/gzip',
        ];

        $map = array_merge(get_allowed_mime_types(), $extraMimes);
        $map = apply_filters('fluent_boards/upload_allowed_mimes', $map);

        // Never allow executable or browser-active formats through plugin filters.
        $blockedExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
            'html', 'htm', 'shtml', 'xhtml', 'xht',
            'js', 'mjs', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'swf', 'htaccess',
        ];
        $safeMap = [];

        foreach ($map as $extensionPattern => $mimeType) {
            $extensions = array_diff(explode('|', strtolower($extensionPattern)), $blockedExtensions);
            if ($extensions) {
                $safeMap[implode('|', $extensions)] = $mimeType;
            }
        }

        return $safeMap;
    }

}
