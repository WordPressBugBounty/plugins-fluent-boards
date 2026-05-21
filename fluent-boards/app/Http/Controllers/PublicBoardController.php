<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\PublicAccessService;
use FluentBoards\Framework\Http\Request\Request;

class PublicBoardController extends Controller
{
    public function find($board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);

        $board->background = maybe_unserialize($board->background);
        $board->description = wp_kses_post($board->description ?? '');
        $board->createdOn = $board->created_at ? $board->created_at->format('Y-m-d') : null;
        $board->load(['stages', 'labels', 'users']);

        $board->users = PublicAccessService::sanitizeUsers($board->users);
        $board->isUserOnlyViewer = true;
        $board->is_pinned = false;

        $board->makeHidden([
            'settings', 'currency', 'crm_contact_id',
            'updated_at', 'created_by',
        ]);

        return [
            'board' => $board
        ];
    }

    public function getTasksByBoard($board_id)
    {
        $board_id = absint($board_id);
        Board::findOrFail($board_id);

        $stageIds = $this->getStageIdsByBoard($board_id);

        $tasks = Task::with(['assignees', 'labels'])
            ->where('board_id', $board_id)
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            ->whereIn('stage_id', $stageIds)
            ->orderBy('due_at', 'ASC')
            ->get();

        $this->sanitizeTasks($tasks);

        return [
            'tasks' => $tasks,
        ];
    }

    public function getTasksByBoardStage($board_id)
    {
        $board_id = absint($board_id);
        Board::findOrFail($board_id);

        $tasks = [];
        $stageIds = $this->getStageIdsByBoard($board_id);
        $stageTaskCounts = $this->getStageTaskCounts($board_id, $stageIds);
        $paginationByStage = [];

        foreach ($stageIds as $stageId) {
            $stageTasks = Task::with(['assignees', 'labels'])
                ->where('board_id', $board_id)
                ->where('stage_id', $stageId)
                ->whereNull('archived_at')
                ->whereNull('parent_id')
                ->orderBy('position', 'ASC')
                ->limit(20)
                ->get();

            $this->sanitizeTasks($stageTasks);
            $tasks = array_merge($tasks, $stageTasks->toArray());

            $startCursor = $stageTasks->count() ? (float) $stageTasks->first()->position : null;
            $endCursor = $stageTasks->count() ? (float) $stageTasks->last()->position : null;
            $loadedCount = $stageTasks->count();
            $hasMoreAfter = (int) ($stageTaskCounts[$stageId] ?? 0) > $loadedCount;

            $paginationByStage[$stageId] = [
                'stage_id' => (int) $stageId,
                'total_count' => (int) ($stageTaskCounts[$stageId] ?? 0),
                'limit' => 20,
                'direction' => 'next',
                'cursor' => null,
                'has_more' => $hasMoreAfter,
                'has_more_before' => false,
                'has_more_after' => $hasMoreAfter,
                'start_cursor' => $startCursor,
                'end_cursor' => $endCursor,
            ];
        }

        return [
            'tasks' => $tasks,
            'pagination_by_stage' => $paginationByStage
        ];
    }

    public function getStageTasksPage(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        Board::findOrFail($board_id);

        $stageId = $request->getSafe('stage_id', 'intval');
        $limit = $request->getSafe('limit', 'intval', 20);
        $direction = $request->getSafe('direction', 'sanitize_text_field', 'next');
        $cursor = $request->getSafe('cursor', 'floatval');

        if (!$stageId) {
            return $this->sendError(esc_html__('Invalid Stage', 'fluent-boards'), 400);
        }

        if (!in_array($direction, ['next', 'prev'], true)) {
            return $this->sendError(esc_html__('Invalid direction', 'fluent-boards'), 400);
        }

        $limit = max(1, min(100, $limit));

        $stage = Stage::where('board_id', $board_id)
            ->where('id', $stageId)
            ->whereNull('archived_at')
            ->first();

        if (!$stage) {
            return $this->sendError(esc_html__('Stage not found', 'fluent-boards'), 404);
        }

        $stageTasksQuery = $this->makeStageTasksQuery($board_id, $stageId);

        if ($cursor !== null) {
            if ($direction === 'prev') {
                $stageTasksQuery->where('position', '<', $cursor);
            } else {
                $stageTasksQuery->where('position', '>', $cursor);
            }
        }

        $stageTasks = $stageTasksQuery
            ->orderBy('position', $direction === 'prev' ? 'DESC' : 'ASC')
            ->limit($limit + 1)
            ->get();

        $hasMoreInDirection = $stageTasks->count() > $limit;
        if ($hasMoreInDirection) {
            $stageTasks = $stageTasks->slice(0, $limit)->values();
        }

        if ($direction === 'prev') {
            $stageTasks = $stageTasks->sortBy('position')->values();
        }

        $this->sanitizeTasks($stageTasks);

        $startCursor = $stageTasks->count() ? (float) $stageTasks->first()->position : null;
        $endCursor = $stageTasks->count() ? (float) $stageTasks->last()->position : null;

        $hasMoreBefore = false;
        $hasMoreAfter = false;

        if ($startCursor !== null) {
            $hasMoreBefore = $this->makeStageTasksQuery($board_id, $stageId)
                ->where('position', '<', $startCursor)
                ->exists();
        }

        if ($endCursor !== null) {
            $hasMoreAfter = $this->makeStageTasksQuery($board_id, $stageId)
                ->where('position', '>', $endCursor)
                ->exists();
        }

        return [
            'tasks' => $stageTasks,
            'pagination' => [
                'stage_id' => (int) $stageId,
                'limit' => (int) $limit,
                'direction' => $direction,
                'cursor' => $cursor !== null ? (float) $cursor : null,
                'has_more' => $hasMoreInDirection,
                'has_more_before' => $hasMoreBefore,
                'has_more_after' => $hasMoreAfter,
                'start_cursor' => $startCursor,
                'end_cursor' => $endCursor,
            ],
        ];
    }

    private function getStageIdsByBoard($board_id)
    {
        return Stage::where('board_id', $board_id)
            ->whereNull('archived_at')
            ->pluck('id')
            ->toArray();
    }

    private function getStageTaskCounts($board_id, array $stageIds)
    {
        if (!$stageIds) {
            return [];
        }

        return Task::query()
            ->selectRaw('stage_id, COUNT(*) as total_count')
            ->where('board_id', $board_id)
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            ->whereIn('stage_id', $stageIds)
            ->groupBy('stage_id')
            ->pluck('total_count', 'stage_id')
            ->map(function ($count) {
                return (int) $count;
            })
            ->toArray();
    }

    private function makeStageTasksQuery($board_id, $stageId)
    {
        return Task::query()
            ->with(['assignees', 'labels'])
            ->where('board_id', $board_id)
            ->where('stage_id', $stageId)
            ->whereNull('archived_at')
            ->whereNull('parent_id');
    }

    private function sanitizeTasks($tasks)
    {
        $sensitiveFields = [
            'description',
            'crm_contact_id',
            'lead_value',
            'created_by',
            'source',
            'source_id',
            'settings',
            'reminder_type',
            'remind_at',
            'slug',
            'events',
            'started_at',
            'last_completed_at',
            'comments_count',
            'archived_at',
            'parent_id',
            'type',
        ];

        foreach ($tasks as $task) {
            $task->setAppends([]);
            $task->makeHidden($sensitiveFields);
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->is_watching = false;
            $task->contact = null;
            $task->notifications = [];
            $task->watchers = [];
            $task->assignees = PublicAccessService::sanitizeUsers($task->assignees);
        }
    }
}
