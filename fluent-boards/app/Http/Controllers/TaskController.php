<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\CommentService;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\TaskService;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\UploadService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentCrm\App\Models\Subscriber;

class TaskController extends Controller
{
    private TaskService $taskService;

    private NotificationService $notificationService;

    public function __construct(TaskService $taskService, NotificationService $notificationService)
    {

        parent::__construct();
        $this->taskService = $taskService;
        $this->notificationService = $notificationService;
    }

    public function getTopTasksForBoards()
    {
        $userId = get_current_user_id();
        $task_ids = PermissionManager::getTaskIdsWatchByUser($userId);
        $tasksArray = $this->taskService->getTasksForBoards(['assigned', 'overdue', 'upcoming', 'completed', 'others'], 6, $task_ids);
        $taskCounts = $this->taskService->getTaskCountsForBoards(['assigned', 'overdue', 'upcoming', 'completed', 'others'], $task_ids);

        return [
            'data' => $tasksArray,
            'counts' => $taskCounts,
        ];
    }

    public function getTasksByBoard(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);
        $includeArchived = $request->getSafe('include_archived', 'boolval', false);

        // Get stage IDs
        $stageIds = $this->getStageIdsByBoard($board_id, $includeArchived);

        // Fetch tasks for the board
        $tasksQuery = Task::with(['assignees', 'labels', 'watchers', 'taskCustomFields'])
            ->where('board_id', $board_id)
            ->whereNull('parent_id')
            ->whereIn('stage_id', $stageIds)
            ->orderBy('due_at', 'ASC');

        if (!$includeArchived) {
            $tasksQuery->whereNull('archived_at');
        }

        $tasks = $tasksQuery->get();

        // Process each task
        $this->processTasks($tasks, $board);

        if ($board->type === 'roadmap') {
            foreach ($tasks as $task) {
                $task->vote_statistics = $this->taskService->getIdeaVoteStatistics($task->id);
            }
        }

        return [
            'tasks' => $tasks,
        ];
    }

    public function getTasksByBoardStage(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);
        $includeArchived = $request->getSafe('include_archived', 'boolval', false);

        // Get stage IDs
        $stageIds = $this->getStageIdsByBoard($board_id, $includeArchived);
        $stageTaskCounts = $this->getStageTaskCounts($board_id, $stageIds, $includeArchived);

        // Initialize tasks array
        $tasks = [];
        $paginationByStage = [];

        // Fetch and process tasks for each stage
        foreach ($stageIds as $stageId) {
            $stageTasks = $this->makeStageTasksQuery($board_id, $stageId, $includeArchived)
                ->orderBy('position', 'ASC')
                ->limit(20)
                ->get();

            // Process each stage's tasks
            $this->processTasks($stageTasks, $board, [
                'includeContact' => false,
                'includeObserverState' => false,
                'includeRoadmapPopularity' => false,
            ]);
            $tasks = array_merge($tasks, $stageTasks->toArray()); // Merge with the main task list

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
            'pagination_by_stage' => $paginationByStage,
        ];
    }

    public function getTableTasks(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);
        $args = [
            'page' => $request->getSafe('page', 'intval', 1),
            'per_page' => $request->getSafe('per_page', 'intval', 20),
            'sort_by' => $request->getSafe('sort_by', 'sanitize_text_field', 'position'),
            'sort_direction' => $request->getSafe('sort_direction', 'sanitize_text_field', 'asc'),
            'search' => $request->getSafe('search', 'sanitize_text_field', ''),
            'include_archived' => $request->getSafe('include_archived', 'boolval', false),
            'stage' => $request->get('stage', []),
            'task_status' => $request->get('task_status', []),
            'priority' => $request->get('priority', []),
            'assignee' => $request->get('assignee', []),
            'labels' => $request->get('labels', []),
            'watchers' => $request->get('watchers', []),
            'contact' => $request->get('contact', []),
            'custom_fields' => $request->get('custom_fields', []),
            'due_date' => $request->get('due_date', []),
        ];

        $tasks = $this->taskService->getTableTasks($board_id, $args);
        $taskItems = $tasks->items();
        $this->processTasks($taskItems, $board, [
            'includeContact' => false,
            'includeObserverState' => false,
            'includeRoadmapPopularity' => false,
        ]);

        return $this->sendSuccess([
            'items' => $taskItems,
            'pagination' => [
                'total' => (int) $tasks->total(),
                'current_page' => (int) $tasks->currentPage(),
                'per_page' => (int) $tasks->perPage(),
                'last_page' => (int) $tasks->lastPage(),
            ],
        ], 200);
    }

    public function getFilteredBoardTasks(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);
        $args = [
            'search' => $request->getSafe('search', 'sanitize_text_field', ''),
            'include_archived' => $request->getSafe('include_archived', 'boolval', false),
            'stage' => $request->get('stage', []),
            'task_status' => $request->get('task_status', []),
            'priority' => $request->get('priority', []),
            'assignee' => $request->get('assignee', []),
            'labels' => $request->get('labels', []),
            'watchers' => $request->get('watchers', []),
            'contact' => $request->get('contact', []),
            'custom_fields' => $request->get('custom_fields', []),
            'due_date' => $request->get('due_date', []),
        ];

        $tasks = $this->taskService->getBoardViewTasks($board_id, $args);
        $this->processTasks($tasks, $board, [
            'includeContact' => false,
            'includeObserverState' => false,
            'includeRoadmapPopularity' => false,
        ]);

        return [
            'tasks' => $tasks,
        ];
    }

    public function getStageTasksPage(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $board = Board::findOrFail($board_id);
        $includeArchived = $request->getSafe('include_archived', 'boolval', false);
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
            ->first();

        if (!$stage) {
            return $this->sendError(esc_html__('Stage not found', 'fluent-boards'), 404);
        }

        $stageTasksQuery = $this->makeStageTasksQuery($board_id, $stageId, $includeArchived);

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

        $this->processTasks($stageTasks, $board, [
            'includeContact' => false,
            'includeObserverState' => false,
            'includeRoadmapPopularity' => false,
        ]);

        $startCursor = $stageTasks->count() ? (float) $stageTasks->first()->position : null;
        $endCursor = $stageTasks->count() ? (float) $stageTasks->last()->position : null;

        $hasMoreBefore = false;
        $hasMoreAfter = false;

        if ($startCursor !== null) {
            $hasMoreBefore = $this->makeStageTasksQuery($board_id, $stageId, $includeArchived)
                ->where('position', '<', $startCursor)
                ->exists();
        }

        if ($endCursor !== null) {
            $hasMoreAfter = $this->makeStageTasksQuery($board_id, $stageId, $includeArchived)
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

    /**
     * Get Stage IDs by Board ID.
     *
     * @param int $board_id
     * @return array
     */
    private function getStageIdsByBoard($board_id, $includeArchived = false)
    {
        $stageQuery = Stage::where('board_id', $board_id);
        if (!$includeArchived) {
            $stageQuery->whereNull('archived_at');
        }

        return $stageQuery->pluck('id')->toArray();
    }

    private function makeStageTasksQuery($board_id, $stageId, $includeArchived = false)
    {
        $stageTasksQuery = Task::query()
            // Kanban/List only need card-level task data here; full task detail is
            // fetched separately when the modal opens.
            ->select($this->getStageTaskCardColumns())
            ->with(['assignees', 'labels', 'watchers'])
            ->where('board_id', $board_id)
            ->where('stage_id', $stageId)
            ->whereNull('parent_id');

        if (!$includeArchived) {
            $stageTasksQuery->whereNull('archived_at');
        }

        return $stageTasksQuery;
    }

    private function getStageTaskCounts($board_id, array $stageIds, $includeArchived = false)
    {
        if (!$stageIds) {
            return [];
        }

        $query = Task::query()
            ->selectRaw('stage_id, COUNT(*) as total_count')
            ->where('board_id', $board_id)
            ->whereNull('parent_id')
            ->whereIn('stage_id', $stageIds);

        if (!$includeArchived) {
            $query->whereNull('archived_at');
        }

        return $query
            ->groupBy('stage_id')
            ->pluck('total_count', 'stage_id')
            ->map(function ($count) {
                return (int) $count;
            })
            ->toArray();
    }

    private function getStageTaskCardColumns()
    {
        return [
            'id',
            'title',
            'slug',
            'board_id',
            'parent_id',
            'crm_contact_id',
            'type',
            'stage_id',
            'status',
            'reminder_type',
            'priority',
            'archived_at',
            'remind_at',
            'started_at',
            'due_at',
            'last_completed_at',
            'position',
            'comments_count',
            'created_by',
            'settings',
            'source',
            'source_id',
        ];
    }

    /**
     * Process and append extra information for each task.
     *
     * @param \Illuminate\Database\Eloquent\Collection $tasks
     * @param \App\Models\Board $board
     * @param array $options
     */
    private function processTasks($tasks, $board, $options = [])
    {
        $includeContact = Arr::get($options, 'includeContact', true);
        $includeObserverState = Arr::get($options, 'includeObserverState', true);
        $includeRoadmapPopularity = Arr::get($options, 'includeRoadmapPopularity', true);
        $taskIds = [];

        foreach ($tasks as $task) {
            $taskIds[] = (int) $task->id;
        }

        $unreadNotificationCounts = $this->notificationService->getUnreadNotificationCountsByTaskIds($taskIds);

        foreach ($tasks as $task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            if ($includeContact) {
                $task->contact = Helper::crm_contact($task->crm_contact_id); // Handle possible null contact
            }
            if ($includeObserverState) {
                $task->is_watching = $task->isWatching();
            }
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
            $task->watchers = Helper::sanitizeUserCollections($task->watchers);
            $task->notifications = $unreadNotificationCounts[(int) $task->id] ?? 0;

            // If the board type is 'roadmap', calculate popularity
            if ($includeRoadmapPopularity && $board->type === 'roadmap') {
                $task->popular = $task->getPopularCount();
            }
        }
    }


    public function create(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $taskData = $this->taskSanitizeAndValidate($request->getSafe('task'), [
            'title'          => 'required|string',
            'board_id'       => 'required|numeric',
            'stage_id'       => 'required|numeric',
            'priority'       => 'nullable|string',
            'crm_contact_id' => 'nullable|numeric',
            'is_template'    => 'string',
        ]);

        try {
            if ($taskData['board_id'] != $board_id) {
                throw new \Exception(esc_html__('Board id is not valid', 'fluent-boards'));
            }

            $task = $this->taskService->createTask($taskData, $board_id);

            return $this->sendSuccess([
                'task'         => $task,
                'message'      => __('Task has been successfully created', 'fluent-boards'),
                'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($task->board_id)
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function find($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        try {

            $stageService = new StageService();

            $task = $this->taskService->findTaskOnBoard($task_id, $board_id);

            if (isset($task->parent_id)) {
                $task = $this->taskService->findTaskOnBoard($task->parent_id, $board_id, false);
            }

            if(!$task) {
                throw new \Exception(esc_html__('Task not found', 'fluent-boards'));
            }

            if (defined('FLUENT_BOARDS_PRO')) {
                $task->load(['attachments']);
            }

            $task->load(['board', 'stage', 'labels', 'assignees','watchers']);

            $task->assignees = Helper::sanitizeUserCollections($task->assignees);

            $task->isOverdue = $task->isOverdue();
            $task->contact = Task::lead_contact($task->crm_contact_id);
            $task->board->stages = $stageService->stagesByBoardId($board_id);
            $task->is_watching = $this->notificationService->isCurrentUserObservingTask($task);

            $task = $this->taskService->loadNextStage($task);

            if ($task->type == 'roadmap') {
                $task->vote_statistics = $this->taskService->getIdeaVoteStatistics($task_id);
            }

            return [
                'task' => $task
            ];

        } catch (\Exception $e ) {
            return $this->sendError($e->getMessage(), 400);
        }


    }

    public function getStageType(Request $request)
    {
        $stage_id = $request->getSafe('stage_id', 'intval');
        $stage = Stage::findOrFail($stage_id);

        return [
            'stage' => $stage,
        ];
    }

    public function getActivities(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $filter = $request->getSafe('filter', 'sanitize_text_field');
        $per_page = 15; // Apparently, let's use a fixed number of items per page.
        $this->taskService->findTaskOnBoard($task_id, $board_id);

        return [
            'activities' => $this->taskService->getActivities($task_id, $per_page, $filter)
        ];

    }

    public function getArchivedTasks(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        // Sanitize request parameters before passing to service
        $sanitizedParams = [
            'per_page' => $request->getSafe('per_page', 'intval', 20),
            'page' => $request->getSafe('page', 'intval', 1),
            'query' => $request->getSafe('searchInput', 'sanitize_text_field', '') 
        ];


        $tasks = $this->taskService->getArchivedTasks($sanitizedParams, $board_id);

        foreach ($tasks as $task) {
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
        }


        return [
            'tasks' => $tasks
        ];
    }

    public function bulkRestoreTasks(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        try {
            $rawTaskIds = $request->getSafe('task_ids');
            // Sanitize task_ids array to integers
            $task_ids = [];
            if (is_array($rawTaskIds)) {
                $task_ids = array_filter(array_map('intval', $rawTaskIds));
            }
            
            if (empty($task_ids)) {
                return $this->response->sendError('No task IDs provided', 400);
            }

            $tasks = Task::where('board_id', $board_id)
                ->whereIn('id', $task_ids)
                ->whereNotNull('archived_at')
                ->get();

            if ($tasks->isEmpty()) {
                return $this->response->sendError('No archived tasks found with provided IDs', 404);
            }

            $restored_count = 0;
            $failed_count = 0;
            $failed_tasks = [];
            
            foreach ($tasks as $task) {
                try {
                    // Use TaskService to properly restore the task (same as single task restoration)
                    $this->taskService->updateTaskProperty('archived_at', null, $task);
                    
                    // Prepare task for response (same as single task update)
                    $task->isOverdue = $task->isOverdue();
                    $task->isUpcoming = $task->upcoming();
                    $task->contact = Helper::crm_contact($task->crm_contact_id);
                    $task->is_watching = $task->isWatching();
                    $task->assignees = Helper::sanitizeUserCollections($task->assignees);
                    
                    $restored_count++;
                } catch (\Exception $e) {
                    // Track failed tasks but continue processing others
                    $failed_count++;
                    $failed_tasks[] = [
                        'id' => $task->id,
                        'title' => $task->title,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Get recently updated tasks (same as single task operations)
            $recentlyUpdatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($board_id);

            // Build response with detailed results
            $response = [
                'restored_count' => $restored_count,
                'failed_count' => $failed_count,
                'updatedTasks' => $recentlyUpdatedTasks
            ];

            if ($failed_count > 0) {
                $response['failed_tasks'] = $failed_tasks;
                if ($restored_count > 0) {
                    $response['message'] = $restored_count . ' ' . ($restored_count === 1 ? 'task' : 'tasks') . ' restored successfully, ' . $failed_count . ' ' . ($failed_count === 1 ? 'task' : 'tasks') . ' failed';
                } else {
                    $response['message'] = 'Failed to restore ' . $failed_count . ' ' . ($failed_count === 1 ? 'task' : 'tasks');
                }
            } else {
                $response['message'] = $restored_count . ' ' . ($restored_count === 1 ? 'task' : 'tasks') . ' restored successfully';
            }

            return $this->response->sendSuccess($response, 200);

        } catch (\Exception $e) {
            return $this->response->sendError($e->getMessage(), 500);
        }
    }

    public function bulkDeleteTasks(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        try {
            $rawTaskIds = $request->getSafe('task_ids');
            // Sanitize task_ids array to integers
            $task_ids = [];
            if (is_array($rawTaskIds)) {
                $task_ids = array_filter(array_map('intval', $rawTaskIds));
            }
            
            if (empty($task_ids)) {
                return $this->response->sendError('No task IDs provided', 400);
            }

            $tasks = Task::where('board_id', $board_id)
                ->whereIn('id', $task_ids)
                ->get();

            if ($tasks->isEmpty()) {
                return $this->response->sendError('No tasks found with provided IDs', 404);
            }

            $deleted_count = 0;
            $failed_count = 0;
            $failed_tasks = [];
            $options = null;
            
            foreach ($tasks as $task) {
                try {
                    // This handles all cleanup: subtasks, watchers, assignees, labels, notifications, attachments, etc.
                    $this->taskService->deleteTaskForBulk($task);
                    $deleted_count++;
                } catch (\Exception $e) {
                    // Track failed tasks but continue processing others
                    $failed_count++;
                    $failed_tasks[] = [
                        'id' => $task->id,
                        'title' => $task->title,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Get recently updated tasks (same as single task operations)
            $recentlyUpdatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($board_id);

            // Build response with detailed results
            $response = [
                'deleted_count' => $deleted_count,
                'failed_count' => $failed_count,
                'updatedTasks' => $recentlyUpdatedTasks
            ];

            if ($failed_count > 0) {
                $response['failed_tasks'] = $failed_tasks;
                if ($deleted_count > 0) {
                    $response['message'] = $deleted_count . ' ' . ($deleted_count === 1 ? 'task' : 'tasks') . ' deleted successfully, ' . $failed_count . ' ' . ($failed_count === 1 ? 'task' : 'tasks') . ' failed';
                } else {
                    $response['message'] = 'Failed to delete ' . $failed_count . ' ' . ($failed_count === 1 ? 'task' : 'tasks');
                }
            } else {
                $response['message'] = $deleted_count . ' ' . ($deleted_count === 1 ? 'task' : 'tasks') . ' deleted successfully';
            }

            return $this->response->sendSuccess($response, 200);

        } catch (\Exception $e) {
            return $this->response->sendError($e->getMessage(), 500);
        }
    }

    public function updateTaskProperties(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        //Properties in col: settings, assignees,crm_contact_id, archived_at(AUTO_SET_TIMESTAMP) , status, title, description, priority, is_watching, is_template
        $col = $request->getSafe('property', 'sanitize_text_field');
        if ($col === 'description') {
            $value = $request->getSafe('value', 'wp_kses_post');
        } elseif ($col === 'settings' || $col === 'assignees') {
            $value = $request->get('value');
            if (is_array($value) && isset($value['cover']) && is_array($value['cover'])) {
                if (isset($value['cover']['backgroundColor'])) {
                    $value['cover']['backgroundColor'] = sanitize_text_field($value['cover']['backgroundColor']);
                }
            }
        } else {
            $value = $request->getSafe('value', 'sanitize_text_field');
        }

        $validatedData = $this->updateTaskPropValidationAndSanitation($col, $value);
        $task = $this->taskService->findTaskOnBoard($task_id, $board_id);
        $task->load(['board', 'labels', 'assignees']);

        if ($col === 'board_id' && (int) $validatedData[$col] !== $board_id) {
            throw new \Exception(esc_html__('Task not found', 'fluent-boards'));
        }

        if ($col === 'stage_id' && !Stage::where('id', (int) $validatedData[$col])->where('board_id', $board_id)->exists()) {
            throw new \Exception(esc_html__('Stage not found', 'fluent-boards'));
        }

        if ($col === 'parent_id' && $validatedData[$col]) {
            $this->taskService->findTaskOnBoard($validatedData[$col], $board_id, false);
        }

        $oldDateValue = null;
        if (in_array($col, ['due_at', 'started_at'])) {
            $oldDateValue = $task->{$col};
        }

        if ($task->parent_id && !$task->board_id) {
            $task->board_id = $board_id;
            $task->save();
        }
        
        $task = $this->taskService->updateTaskProperty($col, $validatedData[$col], $task);
        $task->isOverdue = $task->isOverdue();
        $task->isUpcoming = $task->upcoming();
        $task->contact = Helper::crm_contact($task->crm_contact_id);
        $task->is_watching = $task->isWatching();
        $task->assignees = Helper::sanitizeUserCollections($task->assignees);

        if ($task->parent_id) {
           $task->subtask_group_id  = TaskMeta::where('task_id', $task->id)->where('key', Constant::SUBTASK_GROUP_CHILD)->value('value');
        }

        // A recent update to a task might impact other tasks on the board.
        $updatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($board_id);
        $taskExists = false;
        foreach ($updatedTasks as $index => $updatedTask) {
            if ($updatedTask->id === $task->id) {
                $updatedTasks[$index] = $task; // Replace the existing task
                $taskExists = true;
                break;
            }
        }

        if (!$taskExists) {
            $updatedTasks[] = $task;
        }

        return [
            'message'      => __('Task has been updated', 'fluent-boards'),
            'task'         => $task,
            'updatedTasks' => $updatedTasks
        ];
    }

    public function updateTaskDates(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = Task::where('id', $task_id)->where('board_id', $board_id)->firstOrFail();
        $payload = $request->all();

        // Capture old dates before updating
        $oldDates = [
            'due_at' => $task->due_at,
            'started_at' => $task->started_at,
        ];

    

        $hasStartAt = array_key_exists('started_at', $payload);
        $hasDueAt = array_key_exists('due_at', $payload);
        $hasReminderType = array_key_exists('reminder_type', $payload);
        $hasRemindAt = array_key_exists('remind_at', $payload);

        $startAt = $hasStartAt ? $request->getSafe('started_at', 'sanitize_text_field', NULL) : $task->started_at;
        $dueAt = $hasDueAt ? $request->getSafe('due_at', 'sanitize_text_field', NULL) : $task->due_at;

        if ($hasStartAt && $hasDueAt && $startAt && $dueAt) {
            if (strtotime($startAt) > strtotime($dueAt)) {
                $startAt = substr($dueAt, 0, 10) . ' 00:00:00';
            }
        }

        if ($hasStartAt) {
            $task = $this->taskService->updateTaskProperty('started_at', $startAt, $task);
        }

        if ($hasDueAt) {
            $task = $this->taskService->updateTaskProperty('due_at', $dueAt, $task);
        }

        // Only mutate reminder fields when the caller explicitly sends them.
        if ($hasReminderType) {
            $reminderType = $request->getSafe('reminder_type', 'sanitize_text_field', NULL);
            $task = $this->taskService->updateTaskProperty('reminder_type', $reminderType, $task);
        }

        if ($hasRemindAt) {
            $remindAt = $request->getSafe('remind_at', 'sanitize_text_field', NULL);
            $task = $this->taskService->updateTaskProperty('remind_at', $remindAt, $task);
        }

        $datesChanged = false;
        $changedDates = [];
        
        if ($oldDates['due_at'] !== $task->due_at) {
            $datesChanged = true;
            $changedDates['due_at'] = $oldDates['due_at'];
        }
        
        if ($oldDates['started_at'] !== $task->started_at) {
            $datesChanged = true;
            $changedDates['started_at'] = $oldDates['started_at'];
        }
        
        if ($datesChanged) {
            do_action('fluent_boards/task_date_changed', $task, $changedDates);
        }

        return [
            'task'         => $task,
            'message'      => __('Dates have been updated', 'fluent-boards'),
            'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($board_id),
        ];
    }

    /**
     * Toggle task pinned state (meta only). Only top-level tasks can be pinned.
     *
     * @param Request $request Expects body: pinned (bool or "true"/"1" for pin, false/"false"/"0" for unpin)
     * @param int     $board_id
     * @param int     $task_id
     * @return array{task: \FluentBoards\App\Models\Task, message: string, updatedTasks: array}
     */
    public function toggleTaskPinned(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);

        $task = Task::where('board_id', $board_id)->findOrFail($task_id);

        if ($task->parent_id) {
            return $this->sendError(__('Subtasks cannot be pinned', 'fluent-boards'), 400);
        }

        $pinned = filter_var($request->getSafe('pinned', 'sanitize_text_field', false), FILTER_VALIDATE_BOOLEAN);

        if ((int) $task->is_pinned !== ($pinned ? 1 : 0)) {
            if ($pinned) {
                $task = $this->taskService->pinTask($task);
                $message = __('Task has been pinned', 'fluent-boards');
            } else {
                $task = $this->taskService->unpinTask($task);
                $message = __('Task has been unpinned', 'fluent-boards');
            }
        } else {
            $message = $pinned ? __('Task is already pinned', 'fluent-boards') : __('Task is already unpinned', 'fluent-boards');
        }

        // Pin state is stored in task meta, so task.updated_at may not change.
        // Ensure the toggled task is always present in the incremental payload.
        $updatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($board_id);
        $taskExists = false;
        foreach ($updatedTasks as $index => $updatedTask) {
            if ($updatedTask->id === $task->id) {
                $updatedTasks[$index] = $task;
                $taskExists = true;
                break;
            }
        }
        if (!$taskExists) {
            $updatedTasks[] = $task;
        }

        return [
            'task'         => $task,
            'message'      => $message,
            'updatedTasks' => $updatedTasks,
        ];
    }

    public function updateTaskCoverPhoto(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $imagePath = $request->getSafe('thumbnail', 'sanitize_text_field');
        $task = $this->taskService->taskCoverPhotoUpdate($task_id, $imagePath, $board_id);

        return [
            'message' => __('Task cover photo has been updated', 'fluent-boards'),
            'task'    => $task,
        ];

    }

    public function taskStatusUpdate(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $integrationType = $request->getSafe('integrationType', 'sanitize_text_field');
        return [
            'message' => __('Task status has been updated', 'fluent-boards'),
            'task'    => $this->taskService->taskStatusUpdate($task_id, $integrationType, $board_id),
        ];
    }

    public function deleteTask($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = $this->taskService->findTaskOnBoard($task_id, $board_id);
        $options = null;
        //if we need to do something before a task is deleted
        do_action('fluent_boards/before_task_deleted', $task, $options);

        $this->taskService->deleteTask($task);

        return [
            'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($board_id),
            'message'      => __('Task has been deleted', 'fluent-boards'),
        ];
    }

    private function taskSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeTask($data);

        return $this->validate($data, $rules);
    }

    /**
     * Ensure write routes cannot pair an accessible route board with a task from another board.
     *
     * @param \FluentBoards\App\Models\Task $task
     * @param int $boardId
     * @return void
     * @throws \Exception
     */
    private function assertTaskBelongsToBoard($task, $boardId)
    {
        $boardId = absint($boardId);

        if (!$task || !$boardId) {
            throw new \Exception(esc_html__('Task not found', 'fluent-boards'));
        }

        if ((int) $task->board_id === $boardId) {
            return;
        }

        if ($task->parent_id) {
            $parentBoardId = Task::where('id', $task->parent_id)->value('board_id');

            if ((int) $parentBoardId === $boardId) {
                return;
            }
        }

        throw new \Exception(esc_html__('Task not found', 'fluent-boards'));
    }

    private function updateTaskPropValidationAndSanitation($col, $value)
    {
        $rules = [
            'title'             => 'required|string',
            'board_id'          => 'required',
            'parent_id'         => 'required',
            'crm_contact_id'    => 'nullable',
            'type'         => 'nullable|string',
            'status'            => 'nullable|string',
            'stage_id'          => 'required',
            'reminder_type'     => 'nullable|string',
            'priority'          => 'nullable|string',
            'lead_value'        => 'nullable|numeric|between:0,9999999.99',
            'remind_at'         => 'nullable|string',
            'scope'             => 'nullable|string',
            'source'            => 'nullable|string',
            'description'       => 'nullable|string',
            'due_at'            => 'nullable|string',
            'started_at'        => 'nullable|string',
            'start_at'          => 'nullable|string',
            'log_minutes'       => 'nullable|integer|unsigned',
            'last_completed'    => 'nullable|date',
            'assignees'         => 'nullable|integer',
            'archived_at'       => 'nullable|string',
            'is_watching'       => 'nullable',
            'is_template'       => 'string',
            'last_completed_at' => 'nullable',
            'settings'          => 'nullable|array',
        ];
        if (array_key_exists($col, $rules)) {
            $rule = $rules[$col];
            if ('assignees' == $col && is_array($value)) {
                $sanitizedAndValidatedValue = [];
                foreach ($value as $val) {
                    $sanitizeData = Helper::sanitizeTask([$col => $val]);
                    $validatedData = $this->validate($sanitizeData, [
                        $col => $rule,
                    ]);
                    array_push($sanitizedAndValidatedValue, $validatedData[$col]);
                }

                return [$col => $sanitizedAndValidatedValue];
            }
            $data = Helper::sanitizeTask([$col => $value]);

            return $this->validate($data, [
                $col => $rule,
            ]);
        }

        // If the column is not found in the rules array, throw an exception
        // translators: %s is the property name
        throw new \Exception(sprintf(esc_html__('Invalid property: %s', 'fluent-boards'), esc_html($col)));
    }

    public function getStageByTask($task_id)
    {
        $task_id = absint($task_id);
        try {
            $stage = $this->taskService->getStageByTask($task_id);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }

        return [
            'stage' => $stage,
        ];
    }

    public function assignYourselfInTask($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = $this->taskService->assignYourselfInTask($board_id, $task_id);
        $task->is_watching = $task->isWatching();

        return [
            'task' => $task,
        ];
    }

    public function detachYourselfFromTask($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = $this->taskService->detachYourselfFromTask($board_id, $task_id);
        $task->assignees = Helper::sanitizeUserCollections($task->assignees);
        $task->is_watching = $task->isWatching();

        return [
            'task' => $task,
        ];
    }

    private function taskMetaSanitizeAndValidate($data, array $rules = [])
    {
        $data = Helper::sanitizeTaskMeta($data);

        return $this->validate($data, $rules);
    }

    public function moveTaskToNextStage($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = $this->taskService->moveTaskToNextStage($task_id, $board_id);

        return [
            'task' => $task
        ];
    }

    /**
     * @throws \Exception
     */
    public function moveTask(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $task = $this->taskService->findTaskOnBoard($task_id, $board_id);
        $oldStageId = $task->stage_id;
        $newStageId = $request->getSafe('newStageId', 'intval');
        $newIndex = $request->getSafe('newIndex', 'intval');
        $newBoardId = $request->getSafe('newBoardId', 'intval');
        $prevTaskId = $request->getSafe('prevTaskId', 'intval');
        $nextTaskId = $request->getSafe('nextTaskId', 'intval');

        if ((!is_numeric($newStageId) || $newStageId == 0)) {
            throw new \Exception(esc_html__('Invalid Stage', 'fluent-boards'));
        }

        if (!$prevTaskId && !$nextTaskId && (!is_numeric($newIndex) || $newIndex == 0)) {
            throw new \Exception(esc_html__('Invalid Value', 'fluent-boards'));
        }

        if ($newBoardId) {
            if ((!is_numeric($newBoardId) || $newBoardId == 0)) {
                throw new \Exception(esc_html__('Invalid Board', 'fluent-boards'));
            }

            if (!PermissionManager::userHasBoardPermission($newBoardId, 'PUT')) {
                throw new \Exception(esc_html__('Task not found', 'fluent-boards'));
            }
        }

        $effectiveBoardId = $newBoardId ?: $task->board_id;
        $targetStage = Stage::where('id', $newStageId)
            ->where('board_id', $effectiveBoardId)
            ->first();

        if (!$targetStage) {
            throw new \Exception(esc_html__('Invalid Stage', 'fluent-boards'));
        }

        foreach (array_filter([$prevTaskId, $nextTaskId]) as $neighborTaskId) {
            $this->taskService->findTaskOnBoard($neighborTaskId, $effectiveBoardId);
        }

        if ($newBoardId) {
            $task = $this->taskService->changeBoardByTask($task, $newBoardId);
            // Load relationships to ensure frontend gets updated data after board move
            $task->load(['assignees', 'labels', 'watchers', 'attachments']);
        }

        // Clean up archived_by_stage meta when task is moved to different stage
        if ($oldStageId != $newStageId) {
            TaskMeta::where('task_id', $task->id)
                ->where('key', Constant::META_KEY_ARCHIVED_BY_STAGE)
                ->delete();
        }

        $task->stage_id = $newStageId;
        // New drag flows send neighbour ids so ordering stays correct even when
        // the client only has a paged slice of the stage. Older move flows still
        // rely on the legacy 1-based newIndex fallback.
        if ($prevTaskId || $nextTaskId) {
            $task = $task->moveBetweenTasks($prevTaskId, $nextTaskId);
        } else {
            $task = $task->moveToNewPosition($newIndex);
        }

        if ($oldStageId != $newStageId) {

            $this->taskService->manageDefaultAssignees($task, $newStageId);

            $defaultPosition = $task->stage->defaultTaskStatus();

            if ($defaultPosition == 'closed' && $task->status != 'closed') {
                $task = $task->close();
            }

//            do_action('fluent_boards/task_moved_to_new_stage', $task, $oldStageId);

            do_action('fluent_boards/task_stage_updated', $task, $oldStageId);

            $usersToSendEmail = $this->notificationService->filterAssigneeToSendEmail($task->id, Constant::BOARD_EMAIL_STAGE_CHANGE);
            $this->taskService->sendMailAfterTaskModify('stage_change', $usersToSendEmail, $task->id);
        }

        do_action('fluent_boards/task_updated', $task, 'position');

        $lastBoardsUpdated = $request->getSafe('last_boards_updated', 'sanitize_text_field');
        $updatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($task->board_id, $lastBoardsUpdated);

        return [
            'message'      => __('Task has been updated', 'fluent-boards'),
            'task'         => $task,
            'updatedTasks' => $updatedTasks,
            'last_updated' => current_time('mysql')
        ];
    }

    /**
     * Get comments and activities for a task, merged into a single array, sorted by creation date, and paginated.
     *
     * @param Request $request The HTTP request instance.
     * @param int $board_id The ID of the board.
     * @param int $task_id The ID of the task.
     * @return \WP_REST_Response The response containing paginated comments and activities, total count, current page, and items per page.
     */
    public function getCommentsAndActivities( Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        try {
            // Pagination parameters
            $page = $request->getSafe('page', 'intval', 1);
            $perPage = $request->getSafe('per_page', 'intval', 10);
            $filter = $request->getSafe('filter', 'sanitize_text_field', 'newest'); // Filter for comments and activities
            $commentsAndActivities = $this->taskService->getCommentsAndActivities($task_id, $perPage, $page, $filter, $board_id);
            // Return the response with the task, paginated comments and activities, total count, current page, and items per page
            return $this->sendSuccess([
                'comments_and_activities' => $commentsAndActivities,
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 500);
        }
    }

    public function sendMailAfterStageChange($usersToSendEmail, $taskId)
    {
        $current_user_id = get_current_user_id();

        /* this will run in background as soon as possible */
        /* sending Model or Model Instance won't work here */
        as_enqueue_async_action('fluent_boards/one_time_schedule_send_email_for_stage_change', [$taskId, $usersToSendEmail, $current_user_id], 'fluent-boards');
    }
    public function getAssociatedTasks($associated_id)
    {
        if (!$this->currentUserCanReadCrmContacts()) {
            return $this->sendError(esc_html__('You do not have permission to view CRM contact tasks', 'fluent-boards'), 403);
        }

        $associated_id = absint($associated_id);
        return [
            'tasks' => $this->taskService->getAssociatedTasks($associated_id, get_current_user_id())
        ];
    }

    /**
     * Check FluentCRM contact read permission before exposing CRM-associated task data.
     *
     * @return bool
     */
    private function currentUserCanReadCrmContacts()
    {
        $permissionManager = 'FluentCrm\\App\\Services\\PermissionManager';

        if (!class_exists($permissionManager)) {
            return false;
        }

        return (bool) $permissionManager::currentUserCan('fcrm_read_contacts');
    }

    /**
     * @param Request $request
     * @param $board_id
     * @param $task_id
     * @return \WP_REST_Response
     */
    public function uploadMediaFileFromWpEditor(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        try {
            $this->taskService->findTaskOnBoard($task_id, $board_id);


            $file = Arr::get($request->files(), 'file')->toArray();
            (new \FluentBoards\App\Services\UploadService)->validateFile($file);

            $uploadInfo = UploadService::handleFileUpload( $request->files(), $board_id);

            $fileData = $uploadInfo[0];
            $fileUploadedData = $this->taskService->uploadMediaFileFromWpEditor($task_id, $fileData, Constant::TASK_DESCRIPTION);
            if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                $mediaData = (new AttachmentService())->processMediaData($fileData, $file);
                $fileUploadedData['driver'] = $mediaData['driver'];
                $fileUploadedData['file_path'] = $mediaData['file_path'];
                $fileUploadedData['full_url'] = $mediaData['full_url'];
                $fileUploadedData->save();
            }
            $fileUploadedData['public_url'] = (new CommentService())->createPublicUrl($fileUploadedData, $board_id);

            return $this->sendSuccess([
                'message' => __('Image has been uploaded', 'fluent-boards'),
                'file' => $fileUploadedData
            ], 200);


        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function createTaskFromImage(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        $stageId = $request->getSafe('stage_id', 'intval');
        if (!Stage::where('id', $stageId)->where('board_id', $board_id)->exists()) {
            return $this->sendError(esc_html__('Stage not found', 'fluent-boards'), 400);
        }

        $file = Arr::get($request->files(), 'file')->toArray();
        (new \FluentBoards\App\Services\UploadService)->validateFile($file);

        $uploadInfo = UploadService::handleFileUpload( $request->files(), $board_id);
        $task = $this->taskService->createTaskFromImage($board_id, $stageId, $uploadInfo, $file);
        return $this->sendSuccess([
            'task' => $task,
            'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($board_id),
            'message' => __('Task has been created', 'fluent-boards'),
        ], 200);

    }

    public function handleTaskCoverImageUpload(Request $request, $board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        try {
            $task = $this->taskService->findTaskOnBoard($task_id, $board_id);

            $file = Arr::get($request->files(), 'file')->toArray();
            (new \FluentBoards\App\Services\UploadService)->validateFile($file);

            $uploadInfo = UploadService::handleFileUpload( $request->files(), $board_id);

            $fileData = $uploadInfo[0];
            $fileUploadedData = $this->taskService->uploadMediaFileFromWpEditor($task_id, $fileData, Constant::TASK_DESCRIPTION);
            if(!!defined('FLUENT_BOARDS_PRO_VERSION')) {
                $mediaData = (new AttachmentService())->processMediaData($fileData, $file);
                $fileUploadedData['driver'] = $mediaData['driver'];
                $fileUploadedData['file_path'] = $mediaData['file_path'];
                $fileUploadedData['full_url'] = $mediaData['full_url'];
                $fileUploadedData->save();
            }

            $settings = $task->settings;
            $this->taskService->deleteTaskCoverImage($settings);
            $publicUrl = (new CommentService())->createPublicUrl($fileUploadedData, $board_id);

            $settings['cover'] = [
                'imageId' => $fileUploadedData['id'],
                'backgroundImage' => $publicUrl,
            ];
            $task->settings = $settings;
            $task->save();

            return $this->sendSuccess([
                'message' => __('Image has been uploaded', 'fluent-boards'),
                'public_url' => $publicUrl
            ], 200);


        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }
    public function removeTaskCover($board_id, $task_id)
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        try {
            $task = $this->taskService->findTaskOnBoard($task_id, $board_id);
            $settings = $task->settings;
            $this->taskService->deleteTaskCoverImage($settings);
            unset($settings['cover']);
            $task->settings = $settings;
            $task->save();
            return $this->sendSuccess([
                'task' => $task,
                'message' => __('Task Cover removed successfully', 'fluent-boards'),
            ]);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    /**
     * Get task tabs configuration
     */
    public function getTaskTabsConfig()
    {
        $default_config = [
            [
                'name'    => 'assigned',
                'label'   => __('Assigned', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 1
            ],
            [
                'name'    => 'upcoming',
                'label'   => __('Upcoming', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 2
            ],
            [
                'name'    => 'overdue',
                'label'   => __('Overdue', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 3
            ],
            [
                'name'    => 'mentioned',
                'label'   => __('Mentioned', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 4
            ],
            [
                'name'    => 'completed',
                'label'   => __('Completed', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 5
            ],
            [
                'name'    => 'others',
                'label'   => __('Others', 'fluent-boards'),
                'visible' => 'true',
                'order'   => 6
            ]
        ];
        $availableTabNames = array_column($default_config, 'name');

        $existConfig = Meta::where('object_id', get_current_user_id())->where('key', Constant::FBS_TASK_TABS_CONFIG)->first();
        $config = $default_config;

        if ($existConfig && !empty($existConfig->value)) {
            $storedConfig = $existConfig->value;
            $configChanged = false;
            $config = $storedConfig;
            $config = array_values(array_filter($config, fn($tab) => in_array($tab['name'] ?? '', $availableTabNames, true)));
            $configChanged = count($config) !== count($storedConfig);

            if (empty($config)) {
                $config = $default_config;
                $configChanged = true;
            }

            $existingNames = array_column($config, 'name');
            $missingTabs = [];
            foreach ($default_config as $defaultTab) {
                if (!in_array($defaultTab['name'], $existingNames)) {
                    $missingTabs[] = $defaultTab;
                }
            }
            
            if (!empty($missingTabs)) {
                $newConfig = [];
                $order = 1;
                $addedAssigned = false;
                foreach ($config as $tab) {
                    if ($tab['name'] === 'upcoming' && !$addedAssigned) {
                        $assignedTab = array_filter($missingTabs, fn($t) => $t['name'] === 'assigned');
                        if (!empty($assignedTab)) {
                            $assignedTab = reset($assignedTab);
                            $assignedTab['order'] = $order++;
                            $newConfig[] = $assignedTab;
                            $addedAssigned = true;
                        }
                    }
                    $tab['order'] = $order++;
                    $newConfig[] = $tab;
                }
                foreach ($missingTabs as $missingTab) {
                    if ($missingTab['name'] !== 'assigned') {
                        $missingTab['order'] = $order++;
                        $newConfig[] = $missingTab;
                    }
                }
                $config = $newConfig;
                $configChanged = true;
            }

            if ($configChanged) {
                $existConfig->value = $config;
                $existConfig->save();
            }
        }

        // Always apply fresh translations based on tab name
        $labelMap = [
            'assigned'  => __('Assigned', 'fluent-boards'),
            'upcoming'  => __('Upcoming', 'fluent-boards'),
            'overdue'   => __('Overdue', 'fluent-boards'),
            'mentioned' => __('Mentioned', 'fluent-boards'),
            'completed' => __('Completed', 'fluent-boards'),
            'others'    => __('Others', 'fluent-boards'),
        ];

        foreach ($config as &$tab) {
            if (isset($labelMap[$tab['name']])) {
                $tab['label'] = $labelMap[$tab['name']];
            }
        }

        return $this->sendSuccess([
            'data' => $config
        ]);
    }

    /**
     * Save task tabs configuration
     */
    public function saveTaskTabsConfig(Request $request)
    {
        $rawConfig = $request->getSafe('tabs');
        
        if (empty($rawConfig) || !is_array($rawConfig)) {
            return $this->sendError([
                'message' => __('Invalid data format', 'fluent-boards')
            ], 400);
        }
        
        // Sanitize config array
        $config = [];
        foreach ($rawConfig as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $sanitizedTab = [
                'name' => isset($tab['name']) ? sanitize_text_field($tab['name']) : '',
                'label' => isset($tab['label']) ? sanitize_text_field($tab['label']) : '',
                'visible' => isset($tab['visible']) ? sanitize_text_field($tab['visible']) : 'false',
                'order' => isset($tab['order']) ? absint($tab['order']) : 0,
            ];
            $config[] = $sanitizedTab;
        }

        if (count(array_filter($config, fn($tab) => $tab['visible'] == 'true')) == 0) {
            return $this->sendError([
                'message' => __('At least one tab must be visible', 'fluent-boards')
            ], 400);
        }
        
        $userId = get_current_user_id();

        $exit = Meta::where('object_id', $userId)->where('key', 'fbs_task_tabs_config')->first();

        if ($exit) {
            $exit->value = $config;
            $exit->save();
        } else {
            $exit = Meta::create([
                'object_id'   => $userId,
                'object_type' => 'option',
                'key'         => Constant::FBS_TASK_TABS_CONFIG,
                'value'       => $config
            ]);
        }
        $config = $exit->value;

        return $this->sendSuccess([
            'message' => __('Configuration saved successfully', 'fluent-boards'),
            'config' => $config
        ]);
    }
    public function getAssociatedCrmContacts($board_id)
    {
        $board_id = absint($board_id);
        $contactsInTasks = Task::where('board_id', $board_id)
                                ->whereNotNull('crm_contact_id')
                                ->get();
        
        if ($contactsInTasks->isEmpty()) {
            return $this->sendSuccess([]);
        }
                        
        $contactIds = $contactsInTasks->pluck('crm_contact_id')
                                    ->unique()
                                    ->toArray();
                        
        $allContacts = Subscriber::whereIn('id', $contactIds)->get();
                        
         if ($allContacts->isEmpty()) {
            return $this->sendSuccess([]);
        }
                        
        $formattedContacts = [];
        foreach ($allContacts as $contact) {
            $name = trim($contact->first_name . ' ' . $contact->last_name);
                        
            $formattedContacts[] = [
                'id' => $contact->id,
                'display_name' => $name,
                'email' => $contact->email,
                'photo' => fluent_boards_user_avatar($contact->user_email, $name),
                ];
            }
            if (!empty($formattedContacts)) {
                usort($formattedContacts, function ($a, $b) {
                return strcmp($a['display_name'], $b['display_name']);
            });
        }
                        
    return $this->sendSuccess($formattedContacts);
    }
    
    public function cloneTask(Request $request, $board_id, $task_id) 
    {
        $board_id = absint($board_id);
        $task_id = absint($task_id);
        $taskData = $this->taskSanitizeAndValidate($request->only(['title', 'stage_id', 'assignee', 'subtask', 'label', 'attachment', 'comment']), [
            'title'          => 'required|string',
            'stage_id'       => 'required|numeric',
            'assignee'       => 'required',
            'subtask'        => 'required',
            'label'          => 'required',
            'attachment'     => 'required',
            'comment'        => 'required',
        ]);
        try {
            $taskData = fluent_boards_string_to_bool($taskData);
            $clonedTask = $this->taskService->cloneTask($task_id, $taskData, $board_id);

            return $this->sendSuccess([
                'message' => __('Task has been cloned successfully', 'fluent-boards'),
                'task' => $clonedTask,
                'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($clonedTask->board_id)
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

    public function bulkActions(Request $request, $board_id)
    {
        $board_id = absint($board_id);
        try {
            $rawTaskIds = $request->getSafe('task_ids');
            // Sanitize task_ids array to integers
            $taskIds = [];
            if (is_array($rawTaskIds)) {
                $taskIds = array_filter(array_map('intval', $rawTaskIds));
            }
            $action = $request->getSafe('action', 'sanitize_text_field');
            // Sanitize params array
            $rawParams = $request->except(['task_ids', 'action']);
            // Ensure rawParams is sanitized
            if (!is_array($rawParams)) {
                $rawParams = [];
            }
            $params = [];
            foreach ($rawParams as $key => $value) {
                $sanitizedKey = sanitize_text_field($key);
                if (is_array($value)) {
                    $params[$sanitizedKey] = array_map('sanitize_text_field', $value);
                } else {
                    $params[$sanitizedKey] = sanitize_text_field($value);
                }
            }

            $result = $this->taskService->bulkActions($taskIds, $action, $params, $board_id);

            // Process successful tasks the same way as getTasksByBoard
            if (!empty($result['successful_tasks'])) {
                $board = Board::findOrFail($board_id);
                $this->processTasks($result['successful_tasks'], $board);
            }

            return $this->sendSuccess($result);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 500);
        }
    }
}
