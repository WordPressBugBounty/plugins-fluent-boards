<?php

namespace FluentBoards\App\Api\Classes;

defined('ABSPATH') || exit;

use Exception;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\TaskService;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Services\AttachmentService;
use FluentBoardsPro\App\Services\SubtaskService;
use FluentCrm\App\Models\Subscriber;

use FluentBoards\App\Services\Constant;



/**
 * Tasks Class - PHP API Wrapper
 *
 * Tasks API Wrapper Class that can be used as <code>FluentBoardsApi('tasks')</code> to get the class instance
 *
 * @package FluentBoards\App\Api\Classes
 * @namespace FluentBoards\App\Api\Classes
 *
 * @version 1.0.0
 */
class Tasks
{
    private $instance = null;

    private $allowedInstanceMethods = [
        'all',
        'get',
        'find',
        'first',
        'paginate'
    ];

    public function __construct(Task $instance)
    {
        $this->instance = $instance;
    }

    /**
     * Get Tasks by board
     *
     * Use:
     * <code>FluentBoardsApi('tasks')->getTasksByBoard();</code>
     *
     * @param string|int $board_id
     * @param array $with
     * @return array|Task Model
     */
    public function getTasksByBoard($board_id, $with = [])
    {
        if (!$board_id) {
            return [];
        }

        //checking if current user has access to board
        if (!PermissionManager::userHasPermission($board_id)) {
            return false;
        }

        $query = Task::query()
            ->where('board_id', $board_id)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->orderBy('due_at', 'ASC');

        if (!empty($with)) {
            $query->with($with);
        }

        $tasks = $query->get();

        $tasks->transform(function ($task) {
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->contact = Helper::crm_contact($task->crm_contact_id);
            $task->is_watching = $task->isWatching();
            return $task;
        });

        return $tasks;
    }


    /**
     * Get Task by id
     *
     * Use:
     * <code>FluentBoardsApi('tasks')->getTask($id);</code>
     *
     * @param int|string $id Task id
     * @return false|Task Model
     */
    public function getTask($id)
    {
        if (!empty($id)) {
            $task = Task::where('id', $id)->first();

            //checking if current user has access to board
            if (!PermissionManager::userHasPermission($task->board_id)) {
                return false;
            }
            return $task;
        }
        return false;
    }


    /**
     * Get Task Created by
     *
     * Use:
     * <code>FluentBoardsApi('tasks')->getTasksCreatedBy($userId);</code>
     *
     * @param int|string $userId Task id
     * @param array $with
     * @return false|Task Model
     */
    public function getTasksCreatedBy($userId, $with = [])
    {
        if (!empty($userId)) {
            $query = Task::where('created_by', $userId);

            if (!empty($with)) {
                $query->with($with);
            }

            $tasks = $query->get();

            $permittedTasks = [];

            foreach ($tasks as $task) {
                //checking if current user has access to board
                if (PermissionManager::userHasPermission($task->board_id)) {
                    $permittedTasks[] = $task;
                }
            }

            return $permittedTasks;
        }
        return false;
    }


    public function create($data)
    {
        if (empty($data)) {
            return false;
        }

        if (empty($data['title']) || empty($data['board_id']) || empty($data['stage_id'])) {
            return false;
        }

        $taskData = Helper::sanitizeTaskForWebHook($data);
        if (is_string($data['assignees'])) {
            $data['assignees'] = json_decode($data['assignees'], true);
            if (!is_array($data['assignees'])) {
                $data['assignees'] = [$data['assignees']]; // Wrap single value in an array
            }
        } elseif (is_int($data['assignees'])) {
            $data['assignees'] = [$data['assignees']]; // Wrap integer in an array
        }

        if (is_string($data['labels'])) {
            $data['labels'] = json_decode($data['labels'], true);
            if (!is_array($data['labels'])) {
                $data['labels'] = [$data['labels']]; // Wrap single value in an array
            }
        } elseif (is_int($data['labels'])) {
            $data['labels'] = [$data['labels']]; // Wrap integer in an array
        }




        if(!empty($taskData['priority']) && !in_array($taskData['priority'], ['low', 'medium', 'high'])) {
            $taskData['priority'] = 'low';
        }
        if(!empty($taskData['status']) && !in_array($taskData['status'], ['open', 'closed'])) {
            $taskData['status'] = 'open';
        }
        if(!empty($data['crm_contact_id'])) {
            $taskData['crm_contact_id'] = $data['crm_contact_id'];
        }

        if(!empty($data['contact_email']) && empty($data['crm_contact_id'])) {
            // Find first if the contact exists
            $contact = FluentCrmApi('contacts')->createOrUpdate([
                'email' => $data['contact_email'],
                'first_name' => $data['contact_first_name'],
                'last_name' => $data['contact_last_name'],
                'status' => 'subscribed'
            ]);
            $taskData['crm_contact_id'] = $contact->id;
        }

        if(!empty($data['assignees']) && is_array($data['assignees'])) {
            // check if the assignees are valid, they have to be wp user ids
            $users = get_users(['include' => $data['assignees']]);
            $taskData['assignees'] = array_map(function($user) {
                return $user->ID;
            }, $users);
            // after successful task creation we have to add them as board members
        }

        if(!empty($data['labels']) && is_array($data['labels'])) {
            $labelIds = [];
            // check if the labels are valid, they have to be label ids
            foreach ($data['labels'] as $label) {
                if(is_numeric($label)) {
                    $labelModel = Label::where('id', $label)->where('board_id', $data['board_id'])->first();
                    if($labelModel) {
                        $labelId = $labelModel->id;
                        $labelIds[] = $labelId;
                    }
                    continue;
                }
                if(is_string($label)) {
                    $labelModel = Label::where('board_id', $data['board_id'])
                                ->where(function($query) use ($label) {
                                    $query->where('title', $label)
                                        ->orWhere('slug', $label);
                                })->first();
                    if($labelModel) {
                        $labelId = $labelModel->id;
                        $labelIds[] = $labelId;
                    }
                }
            }
            $taskData['labels'] =  $labelIds;
            // we have to map the labels after task creation
        }

        $task =  $this->instance->createTask($taskData);
        // push assignees to board Members
        $boardService = new BoardService();
        if(!empty($taskData['assignees'])) {
            foreach ($taskData['assignees'] as $assigneeId) {
                $boardService->addMembersInBoard($data['board_id'], $assigneeId);
            }
        }

        return $task;
    }


    public function createTask($data = [])
    {
        $defaultData = [
            'title',
            'board_id',
            'stage_id',
            'parent_id',
            'status', // open | closed
            'priority', // low | medium | high
            'source',
            'source_id',
            'description',
            'due_at',
            'crm_contact_id',
            // <or>
            'contact_email',
            'contact_first_name', // this is the full name
            'contact_last_name', // this is the full name
            'contact_status', // default subscribed
            // </or>
            'assignees', // WP User IDs / WP User Emails as an array
            'labels', // array of label ids or titles [1, 'New']
        ];

    }

    public function addAssignees($task_id, $assignees = [])
    {
        if(empty($task_id) || empty($assignees)) {
            return false;
        }

        $task = Task::where('id', $task_id)->first();
        $board = Board::findOrFail($task->board_id);

        if(!PermissionManager::isBoardManager($task->board_id))
        {
            if(!PermissionManager::isAdmin())
            {
                return false;
            }
        }

        if(!$task) {
            return false;
        }

        $boardUsersIds    = $board->users->pluck('ID')->toArray();

        foreach($assignees as $assignee)
        {
            if(in_array($assignee, $boardUsersIds)) { //check if user is a board member
                $task->assignees()
                    ->syncWithoutDetaching([
                        $assignee => [
                            'object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE
                        ]
                    ]);
                $task->watchers()
                    ->syncWithoutDetaching([
                        $assignee => [
                            'object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH
                        ]
                    ]);
            }
        }

        return true;
    }

    public function removeAssignees($task_id, $assignees = [])
    {
        if(empty($task_id) || empty($assignees)) {
            return false;
        }

        $task = Task::where('id', $task_id)->first();
        if(!$task) {
            return false;
        }

        if(!PermissionManager::isBoardManager($task->board_id))
        {
            if(!PermissionManager::isAdmin())
            {
                return false;
            }
        }

        $task->assignees()->detach($assignees);

        $task->watchers()->detach($assignees);

        return true;
    }



    public function attachLabels($taskId, $labelIds = [])
    {
        // do code here
        if(!$taskId)
        {
            return false;
        }

        $task = Task::findOrFail($taskId);
        $board = Board::findOrFail($task->board_id);

        if(!$task)
        {
            return false;
        }

        //checking if current user has access to board
        if (!PermissionManager::userHasPermission($task->board_id)) {
            return false;
        }

        $boardlabelIds = $board->labels->pluck('id')->toArray();

        foreach ($labelIds as $labelId)
        {
            if(in_array($labelId, $boardlabelIds)) { //check if label is a board label
                $task->labels()
                    ->syncWithoutDetaching([
                        $labelId => [
                            'object_type' => Constant::OBJECT_TYPE_TASK_LABEL
                        ]
                    ]);
            }
        }

        return true;

    }

    public function removeLabels($taskId, $labelIds = [])
    {
        // do code here
        if(!$taskId)
        {
            return false;
        }

        $task = Task::findOrFail($taskId);

        if(!$task)
        {
            return false;
        }

        //checking if current user has access to board
        if (!PermissionManager::userHasPermission($task->board_id)) {
            return false;
        }

        $task->labels()->detach($labelIds);

        return true;
    }

    /*
     * Change the stage of a task
     * @param int $taskId
     * @param int $stageId - the new stage id
     * @return Task
     */
    public function changeStage($task, $stageId)
    {
        // param can be either task object or task Id
        if(is_numeric($task)) {
            $task = Task::where('id', $task)->first();
        }

        if(!$task) {
            return false;
        }

        //checking if current user has access to board
        if (!PermissionManager::userHasPermission($task->board_id)) {
            return false;
        }

        $stage = Stage::findOrFail($stageId);

        if($stage->board_id != $task->board_id)
        {
            return false;
        }

        $taskService = new TaskService();
        $position = $taskService->getLastPositionOfTasks($stageId);

        $task->stage_id = $stageId;
        $task->position = $position;
        $task->save();

        return $task;
    }

    public function updateProperty($taskId, $property, $value)
    {
        $taskService = new TaskService();
        $task = Task::where('id', $taskId)->first();

        if(!$task) {
            return false;
        }

        $allowedColumns = ['title', 'description', 'due_at', 'priority', 'status', 'source', 'source_id', 'crm_contact_id'];

        if(!in_array($property, $allowedColumns)) {
            return false;
        }

        $taskService->updateTaskProperty($task, $property, $value);

        return $task;

    }

    /**
     * Create a task attachment
     * @param int $boardId
     * @param int $taskId
     * @param array $data - ['title', 'url'] // url is required
     * @throws Exception
     */
    public function createTaskAttachment(int $boardId, int $taskId, array $data = [])
    {
        // check if the user has permission to add attachment
        if (!$this->hasProAndBoardAccess($boardId) || empty($data) || empty($data['url'])) {
            return false;
        }

        $task = Task::where('id', $taskId)->where('board_id', $boardId)->first();
        if (!$task) {
            return false;
        }

        $attachmentData = Helper::sanitizeTaskAttachment($data);
        return (new AttachmentService())->addTaskAttachment($task->id, $attachmentData);
    }


    /**
     * delete a task attachment
     * @param $boardId
     * @param $taskId
     * @param $attachmentId
     * @return bool
     */
    public function deleteTaskAttachment( int $boardId, int $taskId, int $attachmentId)
    {
        // check if the user has permission to delete attachment
        if (!$this->hasProAndBoardAccess($boardId)) {
            return false;
        }

        $task = Task::where('id', $taskId)->where('board_id', $boardId)->first();
        if (!$task) {
            return false;
        }

        (new AttachmentService())->deleteTaskAttachment($task->id, $attachmentId);

        return true;
    }


    /**
     * Create a subtask
     * @param int $boardId
     * @param int $taskId
     * @param $data
     * @return bool|Task
     */
    public function createSubtask(int $boardId, int $taskId, $data)
    {
        if (!$this->hasProAndBoardAccess($boardId)) {
            return false;
        }

        $task = Task::where('id', $taskId)->where('board_id', $boardId)->first();
        if (!$task) {
            return false;
        }

        $data['board_id'] = $boardId;
        $data['parent_id'] = $task->id;

        // Ensure group_id is provided and valid
        if (!empty($data['group_id'])) {
            // Validate that the group belongs to the parent task
            $group = TaskMeta::where('id', $data['group_id'])
                ->where('task_id', $task->id)
                ->where('key', Constant::SUBTASK_GROUP_NAME)
                ->first();

            if (!$group) {
                // Invalid group_id provided, create a default group instead
                $group = TaskMeta::where('task_id', $task->id)
                    ->where('key', Constant::SUBTASK_GROUP_NAME)
                    ->first();

                if (!$group) {
                    $group = TaskMeta::create([
                        'task_id' => $task->id,
                        'key' => Constant::SUBTASK_GROUP_NAME,
                        'value' => __('Subtask Group 1', 'fluent-boards')
                    ]);
                }

                $data['group_id'] = $group->id;
            }
        } else {
            // No group_id provided, find or create a default group
            $group = TaskMeta::where('task_id', $task->id)
                ->where('key', Constant::SUBTASK_GROUP_NAME)
                ->first();

            if (!$group) {
                $group = TaskMeta::create([
                    'task_id' => $task->id,
                    'key' => Constant::SUBTASK_GROUP_NAME,
                    'value' => __('Subtask Group 1', 'fluent-boards')
                ]);
            }

            $data['group_id'] = $group->id;
        }

        $subtask = $this->create($data);

        if ($subtask) {
            // Link subtask to group
            TaskMeta::create([
                'task_id' => $subtask->id,
                'key' => Constant::SUBTASK_GROUP_CHILD,
                'value' => $data['group_id']
            ]);
        }

        return $subtask;
    }


    /**
     * Update a subtask
     * @param int $boardId
     * @param int $taskId
     * @param int $subtaskId
     * @param string $property
     * @param mixed $value
     *
    */
    public function updateSubtask($boardId, $taskId, $subtaskId, $property, $value)
    {
        if (!$this->hasProAndBoardAccess($boardId)) {
            return false;
        }
        return $this->updateProperty($subtaskId, $property, $value);
    }


    /**
     * Delete a subtask
     * @param int $boardId
     * @param int $taskId
     * @param int $subtaskId
    */
    public function deleteSubtask($boardId, $taskId, $subtaskId)
    {
        if (!$this->hasProAndBoardAccess($boardId)) {
            return false;
        }

        $subtask = Task::where('id', $subtaskId)->where('parent_id', $taskId)->where('board_id', $boardId)->first();
        $deletedTask = clone $subtask;

        $options = null;
        //if we need to do something before a task is deleted
        do_action('fluent_boards/before_task_deleted', $subtask, $options);

        ( new SubtaskService() )->deleteSubtask($subtask);

        do_action('fluent_boards/subtask_deleted_activity', $deletedTask->parent_id, $deletedTask->title);
    }

    /**
     * Check if the user has pro version and has access to the board
     * @param $boardId
     * @return bool
     */
    private function hasProAndBoardAccess($boardId)
    {
        return defined('FLUENT_BOARDS_PRO_VERSION') && PermissionManager::userHasPermission($boardId);
    }


    public function getInstance()
    {
        return $this->instance;
    }

    public function __call($method, $params)
    {
        if (in_array($method, $this->allowedInstanceMethods)) {
            return call_user_func_array([$this->instance, $method], $params);
        }

        throw new \Exception(esc_html(sprintf('Method %s does not exist.', $method)));
    }
}
