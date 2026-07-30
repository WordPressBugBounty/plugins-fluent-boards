<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Folder;
use FluentBoards\App\Services\FolderService;
use FluentBoards\Framework\Http\Request\Request;

class FolderController extends Controller
{
    protected $folderService;

    public function __construct(FolderService $folderService)
    {
        parent::__construct();
        $this->folderService = $folderService;
    }

    public function createFolder(Request $request)
    {
        $data = $this->validate($request->all(), [
            'title' => 'required|string|max:50',
        ]);

        try {
            $folder = $this->folderService->create($data);

            return $this->sendSuccess([
                'message' => __('Folder has been created', 'fluent-boards'),
                'folder'  => $folder,
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function getFolders()
    {
        try {
            $folders = $this->folderService->getFolders();

            return $this->sendSuccess([
                'folders' => $folders,
            ]);
        } catch (\Exception $e) {
            return $this->sendError([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getFolderById(Request $request, $folderId)
    {
        $order = $request->getSafe('order', 'sanitize_text_field', 'created_at');
        $orderBy = $request->getSafe('orderBy', 'sanitize_text_field', 'DESC');
        $searchInput = $request->getSafe('searchInput', 'sanitize_text_field');
        $option = $request->getSafe('option', 'sanitize_text_field');

        try {
            $folder = $this->folderService->getFolderById($folderId, [
                'order'       => $order,
                'orderBy'     => $orderBy,
                'searchInput' => $searchInput,
                'option'      => $option
            ]);

            return $this->sendSuccess([
                'folder' => $folder,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function addBoardToFolder(Request $request, $folderId)
    {
        try {
            $boardIds = $request->getSafe('board_ids');
            $this->folderService->addBoardToFolder($folderId, $boardIds);

            return $this->sendSuccess([
                'message' => __('Added to folder successfully!', 'fluent-boards'),
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function removeBoardFromFolder(Request $request, $folderId)
    {
        $boardId = $request->getSafe('board_id', 'absint');
        $folder = Folder::findOrFail(absint($folderId));
        $this->folderService->removeBoardFromFolder($folder->id, $boardId);

        return $this->sendSuccess([
            'message' => __('Removed from folder successfully!', 'fluent-boards'),
        ]);
    }

    public function updateFolder(Request $request, $folderId)
    {
        $title = $request->getSafe('title', 'sanitize_text_field');
        $folder = $this->folderService->updateFolder($folderId, $title);

        return $this->sendSuccess([
            'message' => __('Folder updated successfully', 'fluent-boards'),
            'folder'  => $folder,
        ]);
    }

    public function deleteFolder($folderId)
    {
        $this->folderService->deleteFolder($folderId);

        return $this->sendSuccess([
            'message' => __('Folder deleted successfully.', 'fluent-boards'),
        ]);
    }
}
