<?php

namespace FluentBoards\App\Services;

use InvalidArgumentException;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
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

        $fileSize = (int)$file['size'];
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

        if ($chunkIndex < 0 || $totalChunks < 1) {
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

        // All chunks for this upload go into the same file
        $filePath = $dirPath . $fileName;

        // If this is the first chunk, remove any leftover file from a previous attempt
        if ($chunkIndex === 0 && file_exists($filePath)) {
            @unlink($filePath);
        }

        $maxFileSize = (int)apply_filters('fluent_boards/import_max_file_size', 64 * 1024 * 1024);
        $writtenSoFar = file_exists($filePath) ? (int)filesize($filePath) : 0;
        if (($writtenSoFar + (int)$chunk['size']) > $maxFileSize) {
            @unlink($filePath);
            return $this->fail([
                'message' => __('The import file exceeds the allowed size.', 'fluent-boards')
            ]);
        }

        // Read the chunk bytes and APPEND to the file
        $chunkContent = file_get_contents($chunk['tmp_name']);
        file_put_contents($filePath, $chunkContent, FILE_APPEND | LOCK_EX);

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

        return $this->processJsonImportPage($boardId, $page, $processPerRequest);
    }

    /** Validate the target folder, create the imported board, and stage task batches. */
    private function initializeJsonImport(Request $request, $processPerRequest)
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
        wp_mkdir_p($importDir);

        $totalPages = max(1, ceil($totalTasks / $processPerRequest));
        for ($i = 0; $i < $totalPages; $i++) {
            $pageTasks = array_slice($tasks, $i * $processPerRequest, $processPerRequest);
            file_put_contents(
                $importDir . 'tasks_page_' . ($i + 1) . '.json',
                wp_json_encode($pageTasks)
            );
        }
        unset($tasks); // Free memory

        // Save import session to database so page 2+ can access the mappers
        Meta::create([
            'object_id'   => $boardId,
            'object_type' => Constant::JSON_IMPORT,
            'key'         => 'json_import_' . $boardId,
            'value'       => [
                'mappers'    => $result['mappers'],
                'siteUrl'    => $result['siteUrl'],
                'totalTasks' => $totalTasks,
                'totalPages' => $totalPages,
                'importDir'  => $importDir,
            ],
        ]);

        // Delete the original large file — we have page files now
        @unlink($filePath);

        // Process the first batch
        $pageFile = $importDir . 'tasks_page_1.json';
        if (file_exists($pageFile)) {
            $taskBatch = json_decode(file_get_contents($pageFile), true);
            if ($taskBatch) {
                FluentBoardsImporter::processTaskBatch(
                    $board, $result['mappers'], $result['siteUrl'], $taskBatch
                );
            }
            @unlink($pageFile); // Delete after processing
        }

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

        // Skip pages whose file is already gone (processed during a timed-out request)
        while ($page <= $totalPages) {
            $pageFile = $importDir . 'tasks_page_' . $page . '.json';
            if (file_exists($pageFile)) {
                break; // Found an unprocessed page
            }
            $page++; // This page was already done, skip it
        }

        // Process the page if it exists
        if ($page <= $totalPages) {
            $pageFile = $importDir . 'tasks_page_' . $page . '.json';
            $taskBatch = json_decode(file_get_contents($pageFile), true);
            if ($taskBatch) {
                FluentBoardsImporter::processTaskBatch(
                    $board, $session['mappers'], $session['siteUrl'], $taskBatch
                );
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
