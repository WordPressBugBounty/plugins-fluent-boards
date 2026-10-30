<?php

namespace FluentBoards\App\Services;

use InvalidArgumentException;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\App;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Services\FolderService;

/** Stage native board exports and restore them in bounded task batches. */
class JsonImportService
{
    private $externalImporter;

    /** Accept an optional Pro handler for third-party JSON exports. */
    public function __construct($externalImporter = null)
    {
        $this->externalImporter = $externalImporter;
    }

    /** Reject invalid import input for the controller to report to the client. */
    private function fail($error, $code = 422)
    {
        throw new InvalidArgumentException(is_array($error) ? $error['message'] : $error, $code);
    }

    /** Validate and import an uploaded JSON board export. */
    public function importFile(Request $request)
    {
        $file = Arr::get($request->files(), 'file');
        $file = $file ? $file->toArray() : null;
        $importFrom = $request->getSafe('importFrom');

        if (!$file || empty($file['tmp_name'])) {
            return $this->fail([
                'message' => __('File is empty.', 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        if (!empty($file['error'])) {
            return $this->fail([
                'message' => __('The file could not be uploaded. Please try again.', 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        // Guard against a path being passed off as an upload
        if (!is_uploaded_file($file['tmp_name'])) {
            return $this->fail([
                'message' => __('Invalid file upload.', 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        // Only JSON board exports are accepted here. The file is never written into the
        // uploads directory - it is read from PHP's temp file and discarded with the request.
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'json') {
            return $this->fail([
                'message' => __('Only .json import files are allowed.', 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        $fileSize = (int) filesize($file['tmp_name']);
        $maxFileSize = (int)apply_filters('fluent_boards/import_max_file_size', 64 * 1024 * 1024);
        if ($fileSize <= 0 || $fileSize > $maxFileSize) {
            return $this->fail([
                'message' => __('The import file is empty or exceeds the allowed size.', 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        $jsonString = file_get_contents($file['tmp_name']);
        $importData = json_decode($jsonString, true);
        unset($jsonString);

        if (!is_array($importData)) {
            return $this->fail([
                'message' => __("Invalid JSON.", 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        $board = $importFrom === Constant::FLUENT_BOARDS_IMPORT
            ? $this->importFromFluentBoards($importData)
            : $this->importExternalSource($importFrom, $importData);

        if(!$board){
            return $this->fail([
                'message' => __("Invalid JSON.", 'fluent-boards'),
                'type'    => 'warning',
            ]);
        }

        return [
            'message'    => __('Processed and Imported successfully', 'fluent-boards'),
            'board_id' => $board ? $board->id: null,
            'success' => true,
        ];

    }

    /** Allow the Pro controller to handle third-party JSON exports. */
    protected function importExternalSource($source, $importData)
    {
        return $this->externalImporter ? call_user_func($this->externalImporter, $source, $importData) : null;
    }

    /** Validate the Fluent Boards export marker before importing. */
    private function importFromFluentBoards($importData)
    {
        try {
            if (!is_string($importData['key'] ?? null) || !is_array($importData['board'] ?? null)) {
                return null;
            } else {
                if (!str_contains($importData['key'], Constant::FLUENT_BOARDS_IMPORT)) {
                    return null;
                } else {
                    return FluentBoardsImporter::process($importData);
                }
            }
        } catch (\Exception $e) {
            return $this->fail($e->getMessage(), 400);
        }
    }

    /** Stage a JSON upload chunk outside the public uploads directory. */
    public function uploadJsonChunk(Request $request)
    {
        $chunk = Arr::get($request->files(), 'chunk');

        if (!$chunk) {
            return $this->fail([
                'message' => __('Chunk data is missing.', 'fluent-boards')
            ]);
        }

        $chunk = $chunk->toArray();

        if (!empty($chunk['error']) || empty($chunk['tmp_name']) || !is_uploaded_file($chunk['tmp_name'])) {
            return $this->fail([
                'message' => __('The chunk could not be uploaded. Please try again.', 'fluent-boards')
            ]);
        }

        $chunkIndex = intval($request->getSafe('chunkIndex'));     // 0, 1, 2, ...
        $totalChunks = intval($request->getSafe('totalChunks'));   // e.g. 35
        $uploadId = sanitize_file_name($request->getSafe('uploadId')); // unique per upload session
        $fileName = $this->sanitizeImportFileName($uploadId . '.json');

        if (!$fileName) {
            return $this->fail([
                'message' => __('Invalid upload id.', 'fluent-boards')
            ]);
        }

        if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
            return $this->fail([
                'message' => __('Invalid chunk information.', 'fluent-boards')
            ]);
        }

        $dirPath = $this->getImportDirPath();
        if (!$dirPath) {
            return $this->fail([
                'message' => __('Upload directory information not available.', 'fluent-boards')
            ]);
        }

        // Use a bounded set of stable locks so unrelated uploads need not wait on one global lock.
        $filePath = $dirPath . $fileName;
        $statePath = $filePath . '.state';
        $lock = $this->acquireImportLock($dirPath, $fileName);

        try {
            if ($chunkIndex === 0) {
                if (file_exists($filePath)) {
                    if (!unlink($filePath)) {
                        return $this->fail(__('Could not reset the import upload.', 'fluent-boards'));
                    }
                }
                if (file_exists($statePath) && !unlink($statePath)) {
                    return $this->fail(__('Could not reset the import upload.', 'fluent-boards'));
                }
                $state = ['next' => 0, 'total' => $totalChunks];
            } else {
                $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : null;
            }
            if (!is_array($state) || ($state['next'] ?? null) !== $chunkIndex || ($state['total'] ?? null) !== $totalChunks) {
                return $this->fail(__('Invalid chunk order. Restart the import upload.', 'fluent-boards'));
            }

            $chunkContent = file_get_contents($chunk['tmp_name']);
            clearstatcache(true, $filePath);
            $writtenSoFar = is_file($filePath) ? filesize($filePath) : 0;
            $maxFileSize = (int) apply_filters('fluent_boards/import_max_file_size', 64 * 1024 * 1024);
            if ($chunkContent === false || $writtenSoFar + strlen($chunkContent) > $maxFileSize) {
                return $this->fail(__('The import file is unreadable or exceeds the allowed size.', 'fluent-boards'));
            }
            if (file_put_contents($filePath, $chunkContent, FILE_APPEND) !== strlen($chunkContent)) {
                wp_delete_file($filePath);
                wp_delete_file($statePath);
                return $this->fail(__('Could not write the import file. Restart the upload.', 'fluent-boards'));
            }
            $state['next'] = $chunkIndex + 1;
            $stateData = wp_json_encode($state);
            if (file_put_contents($statePath, $stateData) !== strlen($stateData)) {
                wp_delete_file($filePath);
                wp_delete_file($statePath);
                return $this->fail(__('Could not save the import upload state.', 'fluent-boards'));
            }
        } finally {
            $this->releaseImportLock($lock);
        }

        return [
            'chunkIndex' => $chunkIndex,
            'isComplete' => ($chunkIndex + 1) >= $totalChunks,
            // Only the file name is handed back — never an absolute path. The
            // processing phase resolves it inside the import directory itself.
            'filePath'   => $fileName,
        ];
    }

    /**
     * The one directory chunked imports are allowed to touch.
     */
    private function getImportDirPath()
    {
        $uploadDir = wp_upload_dir();
        $blockedDirs = array_filter([
            realpath(ABSPATH),
            !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : '',
            defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : '',
            !empty($uploadDir['basedir']) ? realpath($uploadDir['basedir']) : '',
        ]);

        $baseDirs = [
            defined('WP_TEMP_DIR') ? WP_TEMP_DIR : '',
            function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '',
            ini_get('upload_tmp_dir'),
            '/tmp',
        ];

        $baseDir = '';
        foreach ($baseDirs as $candidateDir) {
            if (!$candidateDir) {
                continue;
            }

            if (!is_dir($candidateDir) && !wp_mkdir_p($candidateDir)) {
                continue;
            }

            $realCandidateDir = realpath($candidateDir);
            if (!$realCandidateDir || !wp_is_writable($realCandidateDir)) {
                continue;
            }

            foreach ($blockedDirs as $blockedDir) {
                $blockedDir = rtrim($blockedDir, '/\\');

                $isInsideBlockedDir = strpos($realCandidateDir, $blockedDir . DIRECTORY_SEPARATOR) === 0;

                if ($realCandidateDir === $blockedDir || $isInsideBlockedDir) {
                    continue 2;
                }
            }

            $baseDir = $realCandidateDir;
            break;
        }

        if (!$baseDir) {
            return '';
        }

        $dirPath = trailingslashit($baseDir) . 'fluent-boards-imports-' . md5(ABSPATH) . '/';

        if (!is_dir($dirPath) && !wp_mkdir_p($dirPath)) {
            return '';
        }

        // Staged board imports live outside uploads; web-server rules are defense in depth.
        if (!file_exists($dirPath . '.htaccess')) {
            @file_put_contents($dirPath . '.htaccess', "Require all denied\nDeny from all\n");
        }

        if (!file_exists($dirPath . 'index.html')) {
            @file_put_contents($dirPath . 'index.html', '');
        }

        return $dirPath;
    }

    /**
     * Reduce whatever the client sent to a bare, safe *.json file name.
     * Returns an empty string when the value cannot be trusted.
     */
    private function sanitizeImportFileName($value)
    {
        $fileName = sanitize_file_name(basename(str_replace('\\', '/', (string)$value)));

        if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $fileName)) {
            return '';
        }

        if (strpos($fileName, '..') !== false) {
            return '';
        }

        return $fileName;
    }

    /**
     * Resolve a client supplied import file reference to a real path that is
     * guaranteed to live inside the plugin's own import directory.
     */
    private function resolveImportFilePath($value)
    {
        $fileName = $this->sanitizeImportFileName($value);
        $dirPath = $this->getImportDirPath();

        if (!$fileName || !$dirPath) {
            return '';
        }

        $realDir = realpath($dirPath);
        $realFile = realpath($dirPath . $fileName);

        if (!$realDir || !$realFile || !is_file($realFile)) {
            return '';
        }

        $realDir = rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR;

        if (strpos($realFile, $realDir) !== 0) {
            return '';
        }

        return $realFile;
    }

    /** Initialize or resume a batched JSON import. */
    public function processJsonImport(Request $request)
    {
        $page = max(1, intval($request->get('importing_page', 1)));
        $boardId = absint($request->get('board_id', 0));
        $processPerRequest = 200;

        if (ini_get('max_execution_time') < 300) {
            @ini_set('max_execution_time', '300');
        }

        if ($page === 1) {
            return $this->initializeJsonImport($request, $processPerRequest);
        }

        $directory = $this->getImportDirPath();
        if (!$directory) {
            return $this->fail(__('Could not lock the import session.', 'fluent-boards'));
        }
        $lock = $this->acquireImportLock($directory, 'board-' . $boardId);
        try {
            return $this->processJsonImportPage($boardId, $page, $processPerRequest);
        } finally {
            $this->releaseImportLock($lock);
        }
    }

    /** Validate the target folder, create the imported board, and stage task batches. */
    private function initializeJsonImport(Request $request, $processPerRequest)
    {
        $filePath = $this->resolveImportFilePath($request->get('filePath'));
        if (!$filePath) {
            return $this->fail(__('Import file not found.', 'fluent-boards'));
        }
        $lock = $this->acquireImportLock(dirname($filePath) . '/', basename($filePath));
        try {
            $statePath = $filePath . '.state';
            $state = is_file($statePath) ? json_decode(file_get_contents($statePath), true) : null;
            if (!is_array($state) || empty($state['total']) || ($state['next'] ?? null) !== $state['total']) {
                return $this->fail(__('The upload is incomplete. Restart the import upload.', 'fluent-boards'));
            }
            return $this->initializeCompletedJsonImport($request, $processPerRequest);
        } finally {
            if (!is_file($filePath)) {
                wp_delete_file($filePath . '.state');
            }
            $this->releaseImportLock($lock);
        }
    }

    /** Process a complete upload while holding the staging-directory lock. */
    private function initializeCompletedJsonImport(Request $request, $processPerRequest)
    {
        // Never trust a filesystem path from the client: the reference is
        // reduced to a file name and resolved inside the import directory.
        $filePath = $this->resolveImportFilePath($request->get('filePath'));
        $importFrom = $request->getSafe('importFrom');

        if (!$filePath) {
            return $this->fail([
                'message' => __('Import file not found.', 'fluent-boards')
            ]);
        }

        // json_decode needs ~8x the file size in memory.
        $fileSize = filesize($filePath);
        $requiredMemory = max(536870912, $fileSize * 8); // at least 512MB
        $currentMemory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($currentMemory < $requiredMemory) {
            @ini_set('memory_limit', ceil($requiredMemory / 1048576) . 'M');
        }

        // Read and parse the full JSON (only happens once on page 1)
        $jsonString = file_get_contents($filePath);
        $importData = json_decode($jsonString, true);
        unset($jsonString); // Free memory immediately

        if (!is_array($importData)) {
            @unlink($filePath);
            return $this->fail([
                'message' => __('Invalid JSON file. Please check the file and try again.', 'fluent-boards')
            ]);
        }

        if ($importFrom !== Constant::FLUENT_BOARDS_IMPORT) {
            $board = $this->importExternalSource($importFrom, $importData);
            @unlink($filePath);
            if (!$board) {
                return $this->fail(['message' => __('Unsupported or invalid import file.', 'fluent-boards')]);
            }
            return [
                'message' => __('Imported successfully', 'fluent-boards'),
                'board_id' => $board->id,
                'has_more' => false,
            ];
        }

        // FluentBoards JSON: validate
        if (!is_string($importData['key'] ?? null) || !str_contains($importData['key'], Constant::FLUENT_BOARDS_IMPORT) || !is_array($importData['board'] ?? null)) {
            @unlink($filePath);
            return $this->fail([
                'message' => __('Invalid FluentBoards JSON file.', 'fluent-boards')
            ]);
        }

        $folderId = absint($request->get('folder_id', $request->get('folderId', 0)));
        $folderService = new FolderService();
        if ($folderId) {
            // Validate before creating a board so inaccessible folders cannot leave orphaned imports.
            $folderService->assertCanModifyFolder($folderId);
        }

        // Create board structure (stages, labels, members, custom fields) — no tasks yet
        $result = FluentBoardsImporter::initializeBoard($importData);
        $board = $result['board'];
        $boardId = $board->id;
        if ($folderId) {
            $folderService->addBoardToFolder($folderId, [$boardId]);
        }
        $tasks = $importData['board']['tasks'] ?? [];
        $totalTasks = count($tasks);
        unset($importData); // Free the huge array from memory

        $importDir = $this->getImportDirPath() . 'import_' . $boardId . '/';
        if (!wp_mkdir_p($importDir)) {
            $this->abortInitialization($boardId, $importDir, __('Could not create the import batch directory.', 'fluent-boards'));
        }

        $totalPages = max(1, ceil($totalTasks / $processPerRequest));
        for ($i = 0; $i < $totalPages; $i++) {
            $pageTasks = array_slice($tasks, $i * $processPerRequest, $processPerRequest);
            $pageData = wp_json_encode($pageTasks);
            if ($pageData === false || file_put_contents($importDir . 'tasks_page_' . ($i + 1) . '.json', $pageData) !== strlen($pageData)) {
                $this->abortInitialization($boardId, $importDir, __('Could not write the import batch. The uploaded source has been kept.', 'fluent-boards'));
            }
        }
        unset($tasks); // Free memory

        // Process the first batch only after all staged writes have succeeded.
        $pageFile = $importDir . 'tasks_page_1.json';
        if (!is_file($pageFile)) {
            $this->abortInitialization($boardId, $importDir, __('The import batch is missing. The uploaded source has been kept.', 'fluent-boards'));
        }
        if (file_exists($pageFile)) {
            $taskBatch = json_decode(file_get_contents($pageFile), true);
            if (!is_array($taskBatch) || count($taskBatch) !== min($processPerRequest, $totalTasks)) {
                $this->abortInitialization($boardId, $importDir, __('The import batch could not be read. The uploaded source has been kept.', 'fluent-boards'));
            }
            // Publish continuation state only after page one is complete, so another
            // request cannot process page one while initialization still holds its upload lock.
            $committed = $this->commitImportBatch(function () use ($board, $result, $taskBatch, $totalPages, $boardId, $totalTasks, $importDir) {
                if ($taskBatch) {
                    FluentBoardsImporter::processTaskBatch(
                        $board, $result['mappers'], $result['siteUrl'], $taskBatch
                    );
                }
                if ($totalPages <= 1) {
                    return true;
                }
                return (bool) Meta::create([
                    'object_id' => $boardId,
                    'object_type' => Constant::JSON_IMPORT,
                    'key' => 'json_import_' . $boardId,
                    'value' => [
                        'mappers' => $result['mappers'],
                        'siteUrl' => $result['siteUrl'],
                        'totalTasks' => $totalTasks,
                        'totalPages' => $totalPages,
                        'last_completed_page' => 1,
                        'importDir' => $importDir,
                    ],
                ])->id;
            });
            if (!$committed) {
                $this->abortInitialization($boardId, $importDir, __('Could not save the import progress. The uploaded source has been kept.', 'fluent-boards'));
            }
            @unlink($pageFile); // Delete after recording progress
        }

        @unlink($filePath);

        $completed = min($processPerRequest, $totalTasks);
        $hasMore = $completed < $totalTasks;

        if (!$hasMore) {
            FluentBoardsImporter::finalizeImport($boardId);
            $this->cleanupImportSession($boardId, $importDir);
        }

        return [
            'total'      => $totalTasks,
            'completed'  => $completed,
            'total_page' => $totalPages,
            'has_more'   => $hasMore,
            'last_page'  => 1,
            'board_id'   => $boardId,
            'message'    => $hasMore
                ? __('Processing...', 'fluent-boards')
                : __('Board imported successfully', 'fluent-boards'),
        ];
    }

    /** Import the next staged task batch for the requested board. */
    private function processJsonImportPage($boardId, $page, $processPerRequest)
    {
        if (!$boardId) {
            return $this->fail([
                'message' => __('Board ID is required.', 'fluent-boards')
            ]);
        }

        $board = Board::findOrFail($boardId);
        $meta = Meta::where('object_id', $boardId)
            ->where('object_type', Constant::JSON_IMPORT)
            ->where('key', 'json_import_' . $boardId)
            ->first();

        if (!$meta) {
            return $this->fail([
                'message' => __('Import session not found. Please restart the import.', 'fluent-boards')
            ]);
        }

        $session = $meta->value;
        $importDir = $session['importDir'];
        $totalTasks = $session['totalTasks'];
        $totalPages = $session['totalPages'];

        // A missing file is not proof that its tasks were imported.
        if (!array_key_exists('last_completed_page', $session)) {
            return $this->fail(__('This import session must be restarted to verify its progress.', 'fluent-boards'));
        }
        $page = (int) $session['last_completed_page'] + 1;
        if ($page <= $totalPages && !is_file($importDir . 'tasks_page_' . $page . '.json')) {
            return $this->fail(__('The import batch is missing. Restart the import.', 'fluent-boards'));
        }

        // Process the page if it exists
        if ($page <= $totalPages) {
            $pageFile = $importDir . 'tasks_page_' . $page . '.json';
            $taskBatch = json_decode(file_get_contents($pageFile), true);
            $expectedTasks = min($processPerRequest, $totalTasks - ($page - 1) * $processPerRequest);
            if (!is_array($taskBatch) || count($taskBatch) !== $expectedTasks) {
                return $this->fail(__('The import batch could not be read. No progress was recorded.', 'fluent-boards'));
            }
            $session['last_completed_page'] = $page;
            $committed = $this->commitImportBatch(function () use ($board, $session, $taskBatch, $meta) {
                if ($taskBatch) {
                    FluentBoardsImporter::processTaskBatch(
                        $board, $session['mappers'], $session['siteUrl'], $taskBatch
                    );
                }
                $meta->value = $session;
                return (bool) $meta->save();
            });
            if (!$committed) {
                return $this->fail(__('Could not save the import progress. No tasks from this batch were kept; retry to continue.', 'fluent-boards'));
            }
            @unlink($pageFile);
        }

        $completed = min($page * $processPerRequest, $totalTasks);
        $hasMore = $page < $totalPages;

        if (!$hasMore) {
            FluentBoardsImporter::finalizeImport($boardId);
            $this->cleanupImportSession($boardId, $importDir);
        }

        return [
            'total'      => $totalTasks,
            'completed'  => $completed,
            'total_page' => $totalPages,
            'has_more'   => $hasMore,
            'last_page'  => $page,
            'board_id'   => $boardId,
            'message'    => $hasMore
                ? __('Processing...', 'fluent-boards')
                : __('Board imported successfully', 'fluent-boards'),
        ];
    }

    /**
     * Clean up after import is complete: delete the Meta session and any leftover page files.
     */
    /**
     * Take a non-blocking import lock. A held lock means another request is already
     * working on this import, so answer busy instead of tying up a PHP worker.
     */
    private function acquireImportLock($directory, $name)
    {
        $lock = fopen($directory . '.upload-' . substr(hash('sha256', $name), 0, 2) . '.lock', 'c');
        if (!$lock) {
            $this->fail(__('Could not lock the import.', 'fluent-boards'));
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->fail(__('Another import request is in progress. Please try again in a moment.', 'fluent-boards'), 409);
        }
        return $lock;
    }

    private function releaseImportLock($lock)
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Create a batch's tasks and record its progress atomically, so a failed
     * checkpoint rolls the tasks back and a retry cannot duplicate them.
     */
    private function commitImportBatch(callable $work)
    {
        $db = App::getInstance('db');
        $db->beginTransaction();
        try {
            if (!$work()) {
                $db->rollBack();
                return false;
            }
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Roll back a board whose staging failed, so a retry creates exactly one board. */
    private function abortInitialization($boardId, $importDir, $message)
    {
        $this->cleanupImportSession($boardId, $importDir);
        (new BoardService())->deleteBoard($boardId);
        $this->fail($message);
    }

    private function cleanupImportSession($boardId, $importDir)
    {
        Meta::where('object_id', $boardId)
            ->where('object_type', Constant::JSON_IMPORT)
            ->where('key', 'json_import_' . $boardId)
            ->delete();

        $files = glob($importDir . 'tasks_page_*.json');
        if ($files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($importDir);
    }
}
