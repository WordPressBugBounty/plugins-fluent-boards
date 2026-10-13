<?php

namespace FluentBoards\App\Services\Libs;

use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\Services\UploadService;
use Exception;

class FileSystem
{
    protected $subDir = '';

    public function _setSubDir($subDir)
    {
        $this->subDir = $subDir;
        return $this;
    }
    /**
     * Read file content from custom upload dir of this application
     * @return string [path]
     */
    public function _get($file)
    {
        $arr = explode('/', $file);
        $fileName = end($arr);
        
        $filePath = $this->getDir() . DIRECTORY_SEPARATOR . $fileName;
        
        // Use WordPress Filesystem API
        global $wp_filesystem;
        if (!function_exists('WP_Filesystem')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        WP_Filesystem();
        
        return $wp_filesystem->get_contents($filePath);
    }

    /**
     * Get custom upload dir name of this application
     * @return string [directory path]
     */
    public function _getDir()
    {
        $uploadDir = wp_upload_dir();

        $fbsUploadDir = apply_filters('fluent_boards/upload_folder_name', FLUENT_BOARDS_UPLOAD_DIR);

        if ($this->subDir) {
            return $uploadDir['basedir'] . DIRECTORY_SEPARATOR . $fbsUploadDir. DIRECTORY_SEPARATOR. $this->subDir;
        }

        return $uploadDir['basedir'] . DIRECTORY_SEPARATOR . $fbsUploadDir;
    }

    /**
     * Legacy Core/Pro entry point: prepare storage, not a verified security boundary.
     * Rule writes are best effort; $allowProbe is retained for caller compatibility.
     */
    public function _installUploadProtectionRules($allowProbe = true)
    {
        $directory = (new static())->_getDir();
        if (!wp_mkdir_p($directory)) {
            return false;
        }
        $path = $directory . '/.htaccess';
        if (!$this->hasUploadProtectionRules($path)) {
            if (!function_exists('insert_with_markers')) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
            }
            insert_with_markers($path, 'Fluent Boards', $this->uploadProtectionRules());
        }
        return true;
    }

    protected function uploadProtectionRules()
    {
        return [
            '<IfModule mod_authz_core.c>',
            'Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            'Order deny,allow',
            'Deny from all',
            '</IfModule>',
        ];
    }

    protected function hasUploadProtectionRules($path)
    {
        if (!is_file($path) || !is_readable($path)) {
            return false;
        }
        $contents = str_replace("\r\n", "\n", file_get_contents($path));
        if (preg_match_all('/^# BEGIN Fluent Boards\n(.*?)^# END Fluent Boards$/ms', $contents, $blocks) !== 1) {
            return false;
        }
        $rules = array_values(array_filter(array_map('trim', explode("\n", $blocks[1][0])), function ($line) {
            return $line !== '' && strpos($line, '#') !== 0;
        }));
        return $rules === $this->uploadProtectionRules();
    }

    /** Legacy adapter for older Pro callers; protection is no longer probed over HTTP. */
    public function _checkUploadProtection($allowProbe = true)
    {
        return null;
    }

    /**
     * Get absolute path of file using custom upload dir name of this application
     * @return string [file path]
     */
    public function _getAbsolutePathOfFile($file)
    {
        return $this->_getDir() . DIRECTORY_SEPARATOR . $file;
    }

    /**
     * Resolve a stored attachment path only when it belongs to Fluent Boards uploads.
     *
     * @param string $storedPath
     * @param int|null $boardId
     * @return string|null
     */
    public function _resolveLocalAttachmentPath($storedPath, $boardId = null)
    {
        if (!$storedPath) {
            return null;
        }

        $storedFilename = rawurldecode((string) $storedPath);
        $isBareFilename = $storedFilename !== ''
            && strpos($storedFilename, '/') === false
            && strpos($storedFilename, '\\') === false;

        if ($isBareFilename && $boardId === null) {
            return null;
        }

        $filePath = $isBareFilename ? null : realpath($storedPath);
        $allowedDirectory = $this->_getDir();
        $pluginRoot = realpath($allowedDirectory);

        if (!$pluginRoot) {
            return null;
        }

        $pluginRoot = rtrim($pluginRoot, DIRECTORY_SEPARATOR);
        $allowedRoot = $pluginRoot;

        if ($boardId !== null) {
            if (!is_int($boardId) && !is_string($boardId)) {
                return null;
            }

            $boardId = filter_var($boardId, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($boardId === false) {
                return null;
            }

            $allowedDirectory .= DIRECTORY_SEPARATOR . 'board_' . $boardId;
            if (is_link($allowedDirectory)) {
                return null;
            }

            $allowedRoot = realpath($allowedDirectory);
            $expectedBoardRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'board_' . $boardId;
            if (!$allowedRoot || $allowedRoot !== $expectedBoardRoot) {
                return null;
            }
        }

        if ($isBareFilename) {
            $filePath = realpath($allowedDirectory . DIRECTORY_SEPARATOR . $storedFilename);
        }

        if (!$filePath || !$allowedRoot || !is_file($filePath)) {
            return null;
        }

        $allowedRoot = rtrim($allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strpos($filePath, $allowedRoot) === 0 ? $filePath : null;
    }

    /**
     * Upload request files into the application's custom upload directory.
     * @return array
     */
    public function _uploadFromRequest()
    {
        return $this->_put(FluentBoards('request')->files());
    }

    /**
     * Prepare upload-directory protection before accepting local files.
     * @param array $files
     * @return array
     */
    public function _put($files)
    {
        if (!$this->_installUploadProtectionRules()) {
            throw new Exception(__('Could not create the upload directory. Please check directory permissions.', 'fluent-boards'));
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $uploadOverrides = [
            'test_form' => false,
            'mimes' => UploadService::getAllowedMimeMap(),
        ];
        $uploadedFiles = [];
        $files = is_object($files) ? [$files] : (array) $files;

        $this->_overrideUploadDir();
        try {
            foreach ($files as $file) {
                $filesArray = $file->toArray();
                $filesArray['tmp_name'] = $file->getRealPath();
                $extraData = Arr::only($filesArray, ['name', 'size']);
                if (empty($filesArray['error']) && is_file($filesArray['tmp_name'])) {
                    $filesArray['size'] = filesize($filesArray['tmp_name']);
                    $filesArray['size_in_bytes'] = $filesArray['size'];
                    (new UploadService())->validateFile($filesArray);
                }
                $uploadsData = wp_handle_upload($filesArray, $uploadOverrides);
                if (!empty($uploadsData['error']) || empty($uploadsData['file'])) {
                    throw new Exception($uploadsData['error'] ?? __('File upload failed.', 'fluent-boards'));
                }

                // Keep WordPress's actual path; callers also expect a legacy bare filename.
                $uploadsData['full_path'] = $uploadsData['file'];
                $uploadsData['file'] = basename($uploadsData['file']);
                $uploadedFiles[] = array_merge($extraData, $uploadsData);
            }
        } catch (Exception $exception) {
            // The batch fails as a whole, so remove files this call already stored.
            foreach ($uploadedFiles as $uploadedFile) {
                wp_delete_file($uploadedFile['full_path']);
            }
            throw $exception;
        } finally {
            remove_filter('wp_handle_upload_prefilter', [$this, '_renameFileName']);
            remove_filter('upload_dir', [$this, '_setCustomUploadDir']);
        }

        return $uploadedFiles;
    }

    /**
     * Delete a file from custom upload directory of this application
     * @param array $files
     * @return void
     */
    public function _delete($files)
    {
        $files = (array)$files;

        foreach ($files as $file) {
            $arr = explode('/', $file);
            $fileName = end($arr);
            $filePath = $this->getDir() . '/' . $fileName;
            if (file_exists($filePath)) {
                wp_delete_file($filePath);
            }
        }
    }

    /**
     * Register filters for custom upload dir
     */
    public function _overrideUploadDir()
    {
        add_filter('wp_handle_upload_prefilter', [$this, '_renameFileName']);
        add_filter('upload_dir', [$this, '_setCustomUploadDir']);
    }

    /**
     * Set plugin's custom upload dir
     * @param array $param
     * @return array $param
     */
    public function _setCustomUploadDir($param)
    {
        $fbsUploadDir = apply_filters('fluent_boards/upload_folder_name', FLUENT_BOARDS_UPLOAD_DIR);

        if ($this->subDir) {
            $fbsUploadDir .= '/' . $this->subDir;
        }

        $param['url'] = trailingslashit($param['baseurl']) . trim($fbsUploadDir, '/');
        $param['path'] = trailingslashit($param['basedir']) . trim($fbsUploadDir, '/');
        if (!wp_mkdir_p($param['path'])) {
            $param['error'] = __('Could not create the upload directory.', 'fluent-boards');
        }

        return $param;
    }

    /**
     * Rename the uploaded file name before saving
     * @param array $file
     * @return array $file
     */
    public function _renameFileName($file)
    {
        $prefix = wp_generate_uuid4() . '-';
        $prefix = apply_filters('fluent_boards/uploaded_file_name_prefix', $prefix);
        $file['name'] = $prefix . $file['name'];

        return $file;
    }

    public function _deleteDir($dir)
    {
        $root = realpath($this->_getDir());
        $path = $root ? $root . DIRECTORY_SEPARATOR . $dir : '';
        $resolved = $path ? realpath($path) : false;
        // Never remove the storage root, escape it, or follow a directory symlink.
        if (!$root || !$resolved || is_link($path)
            || strpos($resolved, $root . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        return is_dir($resolved) ? $this->deleteContents($resolved) : false;
    }

    /**
     * Delete a directory and its content
     * Lead Note: this method should be called via scheduler or queue
     * @param string $dir
     * @return bool
     */
    private function deleteContents($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            if (!is_link("$dir/$file") && is_dir("$dir/$file")) {
                // Recursively delete subdirectory
                $this->deleteContents("$dir/$file");
            } else {
                // Delete file
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Recursive directory cleanup, direct file operations required
                unlink("$dir/$file");
            }
        }
        // Delete the directory itself. If the directory is not empty, it will not be deleted.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Recursive directory removal, WP_Filesystem not suitable for this operation
        return rmdir($dir);
    }

    public static function __callStatic($method, $params)
    {
        $instance = new static;

        return call_user_func_array([$instance, $method], $params);
    }

    public function __call($method, $params)
    {
        $hiddenMethod = "_" . $method;

        $method = method_exists($this, $hiddenMethod) ? $hiddenMethod : $method;

        return call_user_func_array([$this, $method], $params);
    }
}
