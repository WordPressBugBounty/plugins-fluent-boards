<?php

namespace FluentBoards\App\Http\Controllers;

use DateTimeImmutable;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\StageService;
use FluentBoards\App\Services\TaskService;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\UploadService;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Support\Arr;
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
        $tasksArray = $this->taskService->getTasksForBoards(['overdue', 'upcoming'], 6, $task_ids);

        return [
            'data' => $tasksArray,
        ];
    }

    public function getTasksByBoard($board_id)
    {
        $board = Board::findOrFail($board_id);
        
        $tasks = Task::with(['assignees', 'labels', 'watchers', 'taskCustomFields'])
            ->where('board_id', $board_id)
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            ->orderBy('due_at', 'ASC')
            ->get();

        foreach ($tasks as $task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->contact = Helper::crm_contact($task->crm_contact_id);
            $task->is_watching = $task->isWatching();
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
            $task->watchers = Helper::sanitizeUserCollections($task->watchers);
            $task->notifications = $this->notificationService->getUnreadNotificationsOfTasks($task);
            if($board->type == 'roadmap') {
                $task->popular = $task->getPopularCount();
            }
        }

        return [
            'tasks' => $tasks,
        ];
    }

    public function create(Request $request, $board_id)
    {
        $taskData = $this->taskSanitizeAndValidate($request->get('task'), [
            'title'          => 'required|string',
            'board_id'       => 'required|numeric',
            'stage_id'       => 'required|numeric',
            'priority'       => 'nullable|string',
            'crm_contact_id' => 'nullable|numeric',
            'is_template'    => 'string',
        ]);

        try {
            if ($taskData['board_id'] != $board_id) {
                throw new \Exception(__('Board id is not valid', 'fluent-boards'));
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
        try {

            $stageService = new StageService();

            $task = Task::findOrFail($task_id);

            if(!$task) {
                throw new \Exception(__('Task not found', 'fluent-boards'));
            }
            if (defined('FLUENT_BOARDS_PRO')) {
                $task->load(['attachments']);
            }

            $task->load(['board', 'stage', 'labels', 'assignees']);

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
        $stage = Stage::findOrFail($request->stage_id);

        return [
            'stage' => $stage,
        ];
    }

    public function getActivities(Request $request, $board_id, $task_id)
    {
        $filter = $request->getSafe('filter');
        $per_page = 15; // Apparently, let's use a fixed number of items per page.

        return [
            'activities' => $this->taskService->getActivities($task_id, $per_page, $filter)
        ];

    }

    public function getArchivedTasks(Request $request, $board_id)
    {
        $tasks = $this->taskService->getArchivedTasks($request->all(), $board_id);

        foreach ($tasks as $task) {
            $task->assignees = Helper::sanitizeUserCollections($task->assignees);
        }

        return [
            'tasks' => $tasks
        ];
    }

    public function updateTaskProperties(Request $request, $board_id, $task_id)
    {
        $col = $request->getSafe('property', 'sanitize_text_field');
        $value = $request->get('value');

        $validatedData = $this->updateTaskPropValidationAndSanitation($col, $value);
        $task = Task::with(['board', 'labels', 'assignees'])->findOrFail($task_id);

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

        // A recent update to a task might impact other tasks on the board.
        $updatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($board_id);

        return [
            'message'      => __('Task has been updated', 'fluent-boards'),
            'task'         => $task,
            'updatedTasks' => $updatedTasks
        ];
    }

    public function updateTaskDates(Request $request, $board_id, $task_id)
    {
        $task = Task::findOrFail($task_id);

        $startAt = $request->getSafe('started_at', 'sanitize_text_field', NULL);
        $dueAt = $request->getSafe('due_at', 'sanitize_text_field', NULL);

        if ($startAt && $dueAt) {
            if (strtotime($startAt) > strtotime($dueAt)) {
                $startAt = gmdate('Y-m-d 00:00:00', strtotime($dueAt));
            }
        }

        $task = $this->taskService->updateTaskProperty('started_at', $startAt, $task);
        $task = $this->taskService->updateTaskProperty('due_at', $dueAt, $task);

        return [
            'task'         => $task,
            'message'      => __('Dates has been updated', 'fluent-boards'),
            'updatedTasks' => $this->taskService->getLastOneMinuteUpdatedTasks($board_id),
        ];
    }

    public function updateTaskCoverPhoto(Request $request, $board_id, $task_id)
    {
        $imagePath = $request->thumbnail;
        $task = $this->taskService->taskCoverPhotoUpdate($task_id, $imagePath);

        return [
            'message' => __('Task cover photo has been updated', 'fluent-boards'),
            'task'    => $task,
        ];

    }

    public function taskStatusUpdate(Request $request, $board_id, $task_id)
    {
        return [
            'message' => __('Task status has been updated', 'fluent-boards'),
            'task'    => $this->taskService->taskStatusUpdate($task_id, $request->integrationType),
        ];
    }

    public function deleteTask($board_id, $task_id)
    {
        $task = Task::findOrFail($task_id);
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

    private function updateTaskPropValidationAndSanitation($col, $value)
    {
        $rules = [
            'title'             => 'required|string',
            'board_id'          => 'required',
            'parent_id'         => 'required',
            'crm_contact_id'    => 'nullable',
            'task_type'         => 'nullable|string',
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
            'is_watching'       => 'nullable|string',
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
    }

    public function getLabelsByTask($task_id)
    {
        $labels = $this->taskService->getLabelsByTask($task_id);

        return $this->sendSuccess([
            'labels' => $labels,
        ], 200);
    }

    public function getStageByTask($task_id)
    {
        $stage = $this->taskService->getStageByTask($task_id);

        return [
            'stage' => $stage,
        ];
    }

    public function assignYourselfInTask($board_id, $task_id)
    {
        $task = $this->taskService->assignYourselfInTask($board_id, $task_id);
        $task->is_watching = $task->isWatching();

        return [
            'task' => $task,
        ];
    }

    public function detachYourselfFromTask($board_id, $task_id)
    {
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
        $task = $this->taskService->moveTaskToNextStage($task_id);

        return [
            'task' => $task
        ];
    }

    /**
     * @throws \Exception
     */
    public function moveTask(Request $request, $board_id, $task_id)
    {
        $task = Task::findOrFail($task_id);
        $oldStageId = $task->stage_id;
        $newStageId = $request->getSafe('newStageId', 'intval');
        $newIndex = $request->getSafe('newIndex', 'intval');
        $newBoardId = $request->getSafe('newBoardId', 'intval');

        if ((!is_numeric($newStageId) || $newStageId == 0)) {
            throw new \Exception(__('Invalid Stage', 'fluent-boards'));
        }
        if ((!is_numeric($newIndex) || $newIndex == 0)) {
            throw new \Exception(__('Invalid Value', 'fluent-boards'));
        }
        if ($newBoardId) {
            if ((!is_numeric($newBoardId) || $newBoardId == 0)) {
                throw new \Exception(__('Invalid Board', 'fluent-boards'));
            }
            $task = $this->taskService->changeBoardByTask($task, $newBoardId);
        }

        $task->stage_id = $newStageId;
        $task = $task->moveToNewPosition($newIndex);

        if ($oldStageId != $newStageId) {

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

        $updatedTasks = $this->taskService->getLastOneMinuteUpdatedTasks($task->board_id, $request->get('last_boards_updated'));

        return [
            'new_position' => $task,
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
        try {
            // Pagination parameters
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);
            $filter = $request->get('filter', 'newest'); // Filter for comments and activities

            $commentsAndActivities = $this->taskService->getCommentsAndActivities($task_id, $perPage, $page, $filter);
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
        return [
            'tasks' => $this->taskService->getAssociatedTasks($associated_id)
        ];
    }

    /**
     * @param Request $request
     * @param $board_id
     * @param $task_id
     * @return \WP_REST_Response
     */
    public function uploadMediaFileFromWpEditor(Request $request, $board_id, $task_id)
    {
        try {


            $file = Arr::get($request->files(), 'file')->toArray();
            (new \FluentBoards\App\Services\UploadService)->validateFile($file);

            $uploadInfo = UploadService::handleFileUpload( $request->files(), $board_id);

            $fileData = $uploadInfo[0];
            $fileUploadedData = $this->taskService->uploadMediaFileFromWpEditor($task_id, $fileData, Constant::TASK_DESCRIPTION);

            return $this->sendSuccess([
                'message' => __('Image has been uploaded', 'fluent-boards-pro'),
                'file' => $fileUploadedData
            ], 200);


        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 400);
        }
    }

}
