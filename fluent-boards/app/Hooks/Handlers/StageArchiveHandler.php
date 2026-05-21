<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Services\Constant;

class StageArchiveHandler
{
    public function onStageArchived($boardId, $stage)
    {
        try {
            $tasks = Task::where('stage_id', $stage->id)
                ->whereNull('parent_id')
                ->whereNull('archived_at')
                ->get();

            foreach ($tasks as $task) {
                $task->position = 0;
                $task->archived_at = current_time('mysql');
                $task->save();

                TaskMeta::updateOrCreate(
                    ['task_id' => $task->id, 'key' => Constant::META_KEY_ARCHIVED_BY_STAGE],
                    ['value' => $stage->id]
                );
            }
            (new BoardHandler())->updateBoardTaskCount($boardId);
        
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }
    }

    public function onStageRestored($boardId, $stage)
    {
        try {
            if (!$stage || !isset($stage->id)) {
                return;
            }

            // Prevent task restoration if the stage is still archived
            if ($stage->archived_at !== null) {
                return;
            }

            $restorableTaskIds = TaskMeta::where('key', Constant::META_KEY_ARCHIVED_BY_STAGE)
                ->where('value', $stage->id)
                ->pluck('task_id')
                ->toArray();

            if (empty($restorableTaskIds)) {
                return;
            }

            $tasks = Task::whereIn('id', $restorableTaskIds)
                ->where('stage_id', $stage->id)
                ->whereNotNull('archived_at')
                ->get();

            foreach ($tasks as $task) {
                $task->archived_at = null;
                $task->save();

                TaskMeta::where('task_id', $task->id)
                    ->where('key', Constant::META_KEY_ARCHIVED_BY_STAGE)
                    ->delete();
            }
            (new BoardHandler())->updateBoardTaskCount($boardId);
        } catch (\Exception $e) {
            error_log( $e->getMessage());
        }
    }

}

