<?php

namespace FluentBoards\App\Services\Libs;

use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\Services\Constant;
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
        if ($this->subDir) {
            $fileName = $this->subDir . DIRECTORY_SEPARATOR . $fileName;
        }
        
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
     * Prepare local storage with Apache rules or verified host-managed protection.
     * Installing rules alone does not prove the HTTP server enforces them.
     * Pass false to defer any required HTTP verification to cron.
     */
    public function _installUploadProtectionRules($allowProbe = true)
    {
        // Protect the root even when called on a board-specific filesystem instance.
        $directory = (new static())->_getDir();
        if (!wp_mkdir_p($directory)) {
            return false;
        }

        $path = $directory . '/.htaccess';
        $rules = [
            '<IfModule mod_authz_core.c>',
            'Require all denied',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            'Order deny,allow',
            'Deny from all',
            '</IfModule>',
        ];

        // Hosts may lock security configuration while leaving upload storage writable.
        if (is_file($path) && is_readable($path)) {
            $contents = str_replace("\r\n", "\n", file_get_contents($path));
            if (preg_match_all('/^# BEGIN Fluent Boards\n(.*?)^# END Fluent Boards$/ms', $contents, $blocks) === 1) {
                $existingRules = array_values(array_filter(array_map('trim', explode("\n", $blocks[1][0])), function ($line) {
                    return $line !== '' && strpos($line, '#') !== 0;
                }));
                if ($existingRules === $rules) {
                    return true;
                }
            }
        }

        if (!function_exists('insert_with_markers')) {
            require_once ABSPATH . 'wp-admin/includes/misc.php';
        }

        if (insert_with_markers($path, 'Fluent Boards', $rules)) {
            return true;
        }

        // Unmarked, locked host rules and server-level restrictions need no rewrite.
        return $this->_checkUploadProtection($allowProbe) === true;
    }

    /**
     * Probe direct access without login cookies using a harmless temporary file.
     * Return true for denied access, false for exposure, or null when unverified.
     * Pass false to read only the cache and schedule a missing check in cron.
     */
    public function _checkUploadProtection($allowProbe = true)
    {
        $directory = (new static())->_getDir();
        $uploads = wp_upload_dir();
        $folder = apply_filters('fluent_boards/upload_folder_name', FLUENT_BOARDS_UPLOAD_DIR);
        $url = trailingslashit($uploads['baseurl']) . trim($folder, '/');
        $rulesPath = $directory . '/.htaccess';
        $rulesHash = is_file($rulesPath) && is_readable($rulesPath) ? hash_file('sha256', $rulesPath) : '';
        $cacheKey = Constant::UPLOAD_PROTECTION_CHECK_PREFIX . md5($directory . '|' . $url);
        $cached = get_transient($cacheKey);
        if (is_array($cached) && isset($cached['rules_hash']) && $cached['rules_hash'] === $rulesHash && array_key_exists('blocked', $cached)) {
            return $cached['blocked'];
        }

        if (!$allowProbe) {
            $hook = 'fluent_boards/check_upload_protection';
            if (!wp_next_scheduled($hook)) {
                wp_schedule_single_event(time(), $hook);
            }
            return null;
        }

        $blocked = null;
        $filename = 'fbs-access-check-' . wp_generate_uuid4() . '.txt';
        $path = trailingslashit($directory) . $filename;
        $contents = wp_generate_uuid4();
        try {
            if (is_dir($directory) && is_writable($directory)
                && file_put_contents($path, $contents, LOCK_EX) === strlen($contents)) {
                // The upload URL comes from trusted site configuration, never request input.
                $response = wp_remote_get($url . '/' . $filename, [
                    'timeout' => 5,
                    'redirection' => 0,
                    'limit_response_size' => 1024,
                    'headers' => ['Cache-Control' => 'no-cache'],
                ]);
                if (!is_wp_error($response)) {
                    $status = wp_remote_retrieve_response_code($response);
                    if (in_array($status, [403, 404], true)) {
                        $blocked = true;
                    } elseif ($status === 200 && wp_remote_retrieve_body($response) === $contents) {
                        $blocked = false;
                    }
                }
            }
        } finally {
            if (is_file($path)) {
                wp_delete_file($path);
            }
        }

        set_transient($cacheKey, ['rules_hash' => $rulesHash, 'blocked' => $blocked], 5 * MINUTE_IN_SECONDS);
        return $blocked;
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
            throw new Exception(__('Could not prepare upload-directory protection. Please ask your hosting provider to block direct file access and check directory permissions.', 'fluent-boards'));
        }

        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        $this->overrideUploadDir();

        $uploadOverrides = [
            'test_form' => false,
            // Accept the same allow-list FluentBoards validates against, so wp_handle_upload
            // doesn't reject broader types (e.g. .json, .md) by extension.
            'mimes'     => \FluentBoards\App\Services\UploadService::getAllowedMimeMap(),
        ];
        $uploadedFiles = []; // Initialize the array

        if(is_object($files)) {
            $files = [$files];
        }

        foreach ((array)$files as $file) {

            $filesArray = $file->toArray();

            // tmp_name is the path to the uploaded file which is required for wp_handle_upload, new framework update
            // changed the way to get the tmp_name Reference to line # 448 in File.php
            $filesArray['tmp_name'] = $file->getRealPath();

            $extraData = Arr::only($filesArray, ['name', 'size']);
            $uploadsData = \wp_handle_upload($filesArray, $uploadOverrides);
            // Add the full path to the file
            $uploadsData['full_path'] = $this->_getAbsolutePathOfFile($uploadsData['file']);
            $uploadedFiles[] = array_merge($extraData, $uploadsData);
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
        add_filter('wp_handle_upload_prefilter', function ($file) {
            add_filter('upload_dir', [$this, '_setCustomUploadDir']);

            add_filter('wp_handle_upload', function ($fileinfo) {
                remove_filter('upload_dir', [$this, '_setCustomUploadDir']);
                $fileinfo['file'] = basename($fileinfo['file']);
                return $fileinfo;
            });

            return $this->_renameFileName($file);
        });
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
            $fbsUploadDir .= DIRECTORY_SEPARATOR . $this->subDir;
            if (!is_dir($param['basedir'] . $fbsUploadDir)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating custom upload subdirectory during upload process, WP_Filesystem not initialized
                @mkdir($param['basedir'] . $fbsUploadDir, 0755);
            }
        }

        $param['url'] = $param['baseurl'] . DIRECTORY_SEPARATOR . $fbsUploadDir;

        $param['path'] = $param['basedir'] . DIRECTORY_SEPARATOR . $fbsUploadDir;

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
        $dir = $this->getDir() . DIRECTORY_SEPARATOR . $dir;
        if (is_dir($dir)) {
            $this->deleteContents($dir);
        }
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
            if(is_dir("$dir/$file")) {
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
