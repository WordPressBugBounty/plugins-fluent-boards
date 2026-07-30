<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Folder;
use FluentBoards\App\Models\Relation;

class FolderService
{
    public function create(array $data)
    {
        $background = [
            'id'        => null,
            'is_image'  => false,
            'image_url' => null,
            'color'     => !empty($data['color']) ? sanitize_hex_color($data['color']) : null,
        ];

        $folderData = [
            'title'      => sanitize_text_field($data['title'] ?? ''),
            'background' => $background,
        ];

        if (!empty($data['parent_id'])) {
            $folderData['parent_id'] = absint($data['parent_id']);
        }

        return Folder::create($folderData);
    }

    public function getFolders($userId = null)
    {
        if (!$userId) {
            $userId = get_current_user_id();
        }

        $boardIds = array_unique(array_map('intval', PermissionManager::getBoardIdsForUser($userId)));
        $folderIds = [];

        if ($boardIds) {
            $folderIds = Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
                ->whereIn('foreign_id', $boardIds)
                ->pluck('object_id')
                ->unique()
                ->toArray();
        }

        $query = Folder::whereNull('parent_id');
        if ($folderIds) {
            $query->where(function ($folderQuery) use ($folderIds, $userId) {
                $folderQuery->whereIn('id', $folderIds)
                    ->orWhere('created_by', $userId);
            });
        } else {
            $query->where('created_by', $userId);
        }

        return $query->with(['subFolders' => function ($subFoldersQuery) {
                $subFoldersQuery->orderBy('created_at', 'DESC');
            }])
            ->with(['boards' => function ($boardsQuery) use ($boardIds) {
                if ($boardIds) {
                    $boardsQuery->whereIn('fbs_boards.id', $boardIds);
                } else {
                    $boardsQuery->where('fbs_boards.id', 0);
                }

                $boardsQuery->orderBy('created_at', 'DESC');
            }])
            ->orderBy('created_at', 'DESC')
            ->distinct()
            ->get();
    }

    public function getFolderById($folderId, $data = [])
    {
        $folderId = absint($folderId);
        if (!$folderId) {
            return null;
        }

        $allowedOrderColumns = ['created_at', 'title', 'id'];
        $order = sanitize_sql_orderby($data['order'] ?? 'created_at') ?: 'created_at';
        if (!in_array($order, $allowedOrderColumns, true)) {
            $order = 'created_at';
        }
        $orderBy = strtoupper(sanitize_text_field($data['orderBy'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $searchInput = sanitize_text_field($data['searchInput'] ?? '');
        $option = sanitize_text_field($data['option'] ?? '');
        $boardIds = array_map('intval', PermissionManager::getBoardIdsForUser());

        return Folder::where('id', $folderId)
            ->with(['subFolders' => function ($query) {
                $query->orderBy('created_at', 'DESC');
            }])
            ->with(['boards' => function ($query) use ($boardIds, $option, $order, $orderBy, $searchInput) {
                if ($boardIds) {
                    $query->whereIn('fbs_boards.id', $boardIds);
                } else {
                    $query->where('fbs_boards.id', 0);
                }

                if ($option) {
                    if ($option === 'archived') {
                        $query->whereNotNull('archived_at');
                    } else {
                        $query->whereNull('archived_at');
                    }
                }

                if ($searchInput) {
                    global $wpdb;
                    $query->where('title', 'like', '%' . $wpdb->esc_like($searchInput) . '%');
                }

                $query->withCount('completedTasks')->orderBy($order, $orderBy);
            }])
            ->orderBy('title', 'ASC')
            ->first();
    }

    public function getBoardIdsByFolder($folderId)
    {
        $folderId = absint($folderId);
        if (!$folderId) {
            return [];
        }

        return Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('object_id', $folderId)
            ->pluck('foreign_id')
            ->toArray();
    }

    public function addBoardToFolder($folderId, $boardIds)
    {
        $folder = $this->assertCanModifyFolder($folderId);
        $boardIds = $this->normalizeBoardIds($boardIds);

        if (!$boardIds) {
            return;
        }

        $boardIds = Board::whereIn('id', $boardIds)->pluck('id')->toArray();
        if (!$boardIds) {
            return;
        }

        Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->whereIn('foreign_id', $boardIds)
            ->delete();

        $boardPivots = [];
        foreach ($boardIds as $boardId) {
            $boardPivots[$boardId] = ['object_type' => Constant::OBJECT_TYPE_FOLDER_BOARD];
        }

        $folder->boards()->syncWithoutDetaching($boardPivots);
    }

    public function assertCanModifyFolder($folderId)
    {
        $folder = Folder::findOrFail(absint($folderId));
        $userId = get_current_user_id();

        if (!$userId || ((int) $folder->created_by !== (int) $userId && !PermissionManager::isAdmin($userId))) {
            throw new \Exception(__('You do not have permission to modify this folder.', 'fluent-boards'));
        }

        return $folder;
    }

    public function removeBoardFromFolder($folderId, $boardId)
    {
        Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('object_id', absint($folderId))
            ->where('foreign_id', absint($boardId))
            ->delete();
    }

    public function updateFolder($folderId, $title)
    {
        $folder = Folder::findOrFail(absint($folderId));
        $folder->title = sanitize_text_field($title);
        $folder->save();

        return $folder;
    }

    public function deleteFolder($folderId)
    {
        $folderId = absint($folderId);
        $folder = Folder::findOrFail($folderId);

        Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('object_id', $folderId)
            ->delete();

        $folder->delete();
    }

    protected function removeBoardFromPreviousFolder($boardId)
    {
        Relation::where('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD)
            ->where('foreign_id', absint($boardId))
            ->delete();
    }

    protected function normalizeBoardIds($boardIds)
    {
        $boardIds = is_array($boardIds) ? $boardIds : [$boardIds];
        $boardIds = array_map('absint', $boardIds);

        return array_values(array_filter(array_unique($boardIds)));
    }
}
