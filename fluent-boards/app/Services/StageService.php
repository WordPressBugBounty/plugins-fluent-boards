<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\Services\Constant;

class StageService
{
    public function createDefaultStages($board)
    {
        $stages = $this->defaultStages($board);
        foreach ($stages as $stage) {
            Stage::create($stage);
        }
    }

    public function defaultStages($board)
    {
        return $this->defaultStagesForTodos($board->id);
    }

    public function defaultStagesForTodos($boardId)
    {
        return [
            [
                'board_id' => $boardId,
                'title'    => 'Open',
                'position' => 1,
                'slug'     => 'open',
                'settings' => [
                    'default_task_status' => 'open'
                ]
            ],
            [
                'board_id' => $boardId,
                'title'    => 'In Progress',
                'position' => 2,
                'slug'     => 'in-progress',
                'settings' => [
                    'default_task_status' => 'open'
                ]
            ],
            [
                'board_id' => $boardId,
                'title'    => 'Completed',
                'position' => 3,
                'slug'     => 'completed',
                'settings' => [
                    'default_task_status' => 'closed'
                ]
            ]
        ];
    }
    public function updateStageProperty($col, $value, $stageId)
    {
        $stage = Stage::findOrFail($stageId);

        if ('title' == $col) {
            $stage = $this->updateTitle($value, $stage);
        } elseif ('status' == $col) {
            $stage = $this->updateStatus($value, $stage);
        } elseif ('color' == $col) {
            $stage = $this->updateColor($value, $stage);
        } elseif ('bg_color' == $col) {
            $stage = $this->updateBackgroundColor($value, $stage);
        } elseif ('archived_at' == $col) {
            $stage = $this->updateArchivedAt($value, $stage);
        }
        return $stage;
    }

    public function getLastOneMinuteUpdatedStages($boardId)
    {
        $oneMinuteAgoTimestamp = current_time('timestamp') - 60;
        return Stage::where('board_id', $boardId)
                    ->where('updated_at', '>=', date_i18n('Y-m-d H:i:s', $oneMinuteAgoTimestamp))
                    ->get();
    }

    private function updateTitle($value, $stage)
    {
        $stage->title = $value;
        $stage->save();
        return $stage;
    }

    private function updateStatus($value, $stage)
    {
        $oldSettings = $stage->settings;
        $oldSettings['default_task_status'] = $value;
        $stage->settings = $oldSettings;
        $stage->save();
        return $stage;
    }

    private function updateColor($value, $stage)
    {
        $stage->color = $value;
        $stage->save();
        return $stage;
    }

    private function updateBackgroundColor($value, $stage)
    {
        $stage->bg_color = $value;
        $stage->save();
        return $stage;
    }

    private function updateArchivedAt($value, $stage)
    {
        $stage->archived_at = $value;
        $stage->save();
        return $stage;
    }

    public function createStage($stageData, $boardId)
    {
        $stage = new Stage();
        $stage->board_id = $boardId;
        $stage->title = $stageData['title'];
        $stage->settings = [
            'default_task_status' => Arr::get($stageData, 'status') ?? 'open',
            'default_task_assignees' => []
        ];
        $providerPosition = Arr::get($stageData, 'position');
        $lastStagePosition = $this->getLastPositionOfStagesOfBoard($boardId);
        $stage->position = $lastStagePosition ? $lastStagePosition->position + 1 : 1;
        $stage->save();
        if($providerPosition){
            $stage->moveToNewPosition($providerPosition);
        }
        return $stage;
    }

    public function getLastPositionOfStagesOfBoard($boardId)
    {
        // return last position of stages of board
        return Stage::where('board_id', $boardId)
                    ->whereNull('archived_at')
                    ->orderBy('position', 'desc')
                    ->first();
    }

    protected function moveOtherStages($stage)
    {

        $stages = Stage::where('board_id', $stage->board_id)
                       ->where('position', '>=', $stage->position)
                       ->whereNotIn('id', [$stage->id])
                       ->whereNull('archived_at')->get();

        foreach ($stages as $stage) {
            $stage->position = $stage->position + 1;
            $stage->save();
        }
    }

    public function copyStagesOfBoard($board, $fromBoardId, $isWithTemplates='no')
    {
        $stages = Stage::where('board_id', $fromBoardId)->where('type', 'stage')->whereNull('archived_at')->orderBy('position', 'asc')->get();
        $stageMapForCopyingTask = array();
        foreach($stages as $key => $stage)
        {
            $stageToSave = array();
            $stageToSave['title'] = $stage['title'];
            $stageToSave['board_id'] = $board->id;
            $stageToSave['slug'] = str_replace(' ', '-', strtolower($stage['title']));
            $stageToSave['type'] = 'stage';
            $stageToSave['position'] = $key + 1;
            $stageToSave['bg_color'] = $stage['bg_color'];
            $stageToSave['settings'] = [
                'default_task_status' => $stage->settings['default_task_status']
            ];
            if (!empty($stage->settings['is_template']) && $isWithTemplates == 'yes') {
                $stageToSave['settings']['is_template'] = $stage->settings['is_template'];
            }
            $newStage = Stage::create($stageToSave);
            $stageMapForCopyingTask[$stage['id']] = $newStage->id;
        }
        return $stageMapForCopyingTask;
    }

    public function importStagesFromBoard($board_id, $selectedStages, $position = null)
    {
        $targetStages = Stage::whereIn('id', $selectedStages)->get();
        $stageMapForCopyingTask = array();
        $stageIdsToCopy = array();

        $numberOfStages = Stage::where('board_id', $board_id)->whereNull('archived_at')->count();

        foreach($targetStages as $key => $stage)
        {
            $stageToSave = array();
            $stageToSave['title'] = $stage['title'] . ' - imported';
            $stageToSave['board_id'] = $board_id;
            $stageToSave['slug'] = str_replace(' ', '-', strtolower($stage['title']));
            $stageToSave['type'] = 'stage';
            $stageToSave['position'] = $numberOfStages + $key + 1;
            $stageToSave['settings'] = [
                'default_task_status' => $stage->settings['default_task_status']
            ];
            $newStage = Stage::create($stageToSave);
            if($position) {
                $newStage->moveToNewPosition( (int) $position + $key );
            }
            $stageMapForCopyingTask[$stage['id']] = $newStage->id;
            $stageIdsToCopy[] = $stage['id'];
        }

        $this->importTasks($board_id, $stageMapForCopyingTask, $stageIdsToCopy);
    }

    public function importTasks($boardId, $stageMapper, $stageIds)
    {
        $tasksToImport = $this->getAllParentAndSubTasksOfStages($stageIds);
        $taskMap = [];
        $subtaskGroupMap = [];
        foreach($tasksToImport as $task)
        {
            $newTask = array();
            $newTask['title'] = $task->title;
            $newTask['parent_id'] = $task->parent_id ? $taskMap[$task->parent_id] : null;
            $newTask['description'] = $task->description;
            $newTask['board_id'] = $boardId;
            $newTask['stage_id'] = $stageMapper[$task->stage_id];
            $newTask['status'] = $task->status;
            $newTask['priority'] = $task->priority;
            $newTask['position'] = $task->position;
            $newTask['due_at'] = $task->due_at;
            $backgroundColor = $task->settings['cover']['backgroundColor'];
            $newTask['settings'] = [
                'cover' => [
                    'backgroundColor' => $backgroundColor,
                ]
            ];
            $newTask = Task::create($newTask);

            if (!$task->parent_id) {
                $taskMap[$task->id] = $newTask->id;
                //group mapping
                $subtaskGroupMap = (new TaskService())->copySubtaskGroup($task, $newTask, $subtaskGroupMap);
            } else {
                $groupRelationOfTask = TaskMeta::where('key', Constant::SUBTASK_GROUP_CHILD)
                    ->where('task_id', $task->id)
                    ->first();

                if ($groupRelationOfTask && $subtaskGroupMap[$groupRelationOfTask->value]) {
                    TaskMeta::create([
                        'task_id' => $newTask->id,
                        'key' => Constant::SUBTASK_GROUP_CHILD,
                        'value' => $subtaskGroupMap[$groupRelationOfTask->value]
                    ]);
                }
            }
        }

        //update task count of board
        $totalTasks = sizeof($tasksToImport);
        $board = Board::findOrFail($boardId);
        $settings = $board->settings ?? [];

        if (isset($settings['tasks_count'])) {
            $settings['tasks_count'] += $totalTasks;
        } else {
            $settings['tasks_count'] = $totalTasks;
        }
        $board->settings = $settings;
        $board->save();
    }

    public function updateStageTemplate($stage_id)
    {
        $stage = Stage::findOrFail($stage_id);
        $stageSettings = $stage->settings;
        if($stageSettings && array_key_exists('is_template', $stageSettings))
        {
            $currentlyIsTemplate = $stageSettings['is_template'];
            if($currentlyIsTemplate){
                $stageSettings['is_template'] = false;
                $stage->settings = $stageSettings;
            }else{
                $stageSettings['is_template'] = true;
                $stage->settings = $stageSettings;
            }
        }else {
            if(!$stageSettings) {
                $stage->settings = [
                    'is_template' => true
                ];
            } else {
                $stage->settings = array_merge($stage->settings, [
                    'is_template' => true
                ]);
            }

        }
        $stage->save();

        return $stage;
    }

    public function moveAllTasks($oldStageId, $newStageId)
    {
        $tasks = Task::where('stage_id', $oldStageId)->whereNull('parent_id')->whereNull('archived_at')->get();

        // get the last position available of that stage
        $position = (new TaskService())->getLastPositionOfTasks($newStageId);

        // update tasks stage and position
        foreach ($tasks as $key => $task) {
            $task->stage_id = $newStageId;
            $task->position = $position + $key;
            $task->save();
        }
        return $tasks;
    }
    public function archiveAllTasksInStage($stage_id)
    {
        $tasks = Task::where('stage_id', $stage_id)->whereNull('parent_id')->whereNull('archived_at')->get();
        foreach ($tasks as $task) {
            $task->position = 0;
            $task->archived_at = current_time('mysql');
            $task->save();
            do_action('fluent_boards/task_archived', $task);
        }
        return $tasks;
    }

    public function createRoadmapStages($board, $stagesData)
    {
        foreach ($stagesData as $index => $formStageData) {
            $stage = new Stage();
            $stage->board_id = $board->id;
            $stage->title = $formStageData['title'];
            $stage->slug = $formStageData['slug'];
            $stage->position = $formStageData['position'] ? $formStageData['position'] : 1;
            $stage->settings = $this->roadmapStageSetting($index);
            $stage->save();
        }
        return $stagesData;
    }

    /*
     * I have no idea what this function does, but I am doing it
     * to make the code work
     */
    public function roadmapStageSetting($index)
    {
        return [
            'is_public' => $index > 0 ? true : false,
            'default_task_status' => 'open',
            'is_template' => false,
        ];
    }


    public function createStages($board, $stageData)
    {
        $firstStage = null;
        foreach ($stageData as $index => $stage) {
            $stageToPush = array();
            $stageToPush['title'] = $stage['title'];
            $stageToPush['board_id'] = $board->id;
            $stageToPush['position'] = $index + 1;
            $stageToPush['slug'] = $this->createSlug($stage['title']);

            if (Arr::get($stage, 'title') == 'Completed') {
                $stageToPush['settings'] = [
                    'default_task_status' => 'closed',
                    'is_template' => false
                ];
            }

            $stage = Stage::create($stageToPush);
            if($index == 0){
                $firstStage = $stage;
            }
        }
        return $firstStage;
    }

    private function createSlug($title)
    {
        return str_replace(' ', '-', strtolower($title));
    }

    public function stagesByBoardId($boardId)
    {
        return Stage::where('board_id', $boardId)->whereNull('archived_at')->orderBy('position', 'asc')->get();
    }



    public function sortStageTasks($order, $orderBy, $stage_id)
    {
        $sortOptions = ['priority', 'due_at', 'position', 'created_at', 'title'];
        $orderOptions = ['ASC', 'DESC'];

        // Validate order and orderBy parameters
        if (!in_array($order, $sortOptions) || !in_array($orderBy, $orderOptions)) {
            throw new \Exception(esc_html__('Invalid sort or orderBy parameter', 'fluent-boards'));
        }

        $tasksQuery = Task::where('stage_id', $stage_id)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->with(['assignees', 'labels', 'watchers']);

        // Apply ordering based on the specified order and orderBy
        switch ($order) {
            case 'priority':
                $tasksQuery->orderByRaw("FIELD(priority, 'High', 'Medium', 'Low') {$orderBy}");
                break;

            case 'due_at':
                if ($orderBy === 'ASC') {
                    // Separate ordering for tasks with and without due dates
                    $tasksWithDueDate    = (clone $tasksQuery)->whereNotNull('due_at')->orderBy('due_at')->get();
                    $tasksWithoutDueDate = (clone $tasksQuery)->whereNull('due_at')->get();
                    $tasks = $tasksWithDueDate->merge($tasksWithoutDueDate);
                } else {
                    $tasksQuery->orderBy('due_at', $orderBy);
                }
                break;

            default:
                $tasksQuery->orderBy($order, $orderBy);
                break;
        }

        // Fetch tasks if not already fetched
        if (!isset($tasks)) {
            $tasks = $tasksQuery->get();
        }

        // Update tasks with additional attributes
        $tasks->each(function ($task, $key) {
            $task->position = $key + 1;
            $task->save();
            $task->isOverdue = $task->isOverdue();
            $task->isUpcoming = $task->upcoming();
            $task->is_watching = $task->isWatching();
            $task->contact = Task::lead_contact($task->crm_contact_id);
        });
        return $tasks;
    }


    public function updateStage($updatedStage, $board_id, $oldStage)
    {
        $stageBeforeUpdate = clone $oldStage;
        $oldStage->title = sanitize_text_field(Arr::get($updatedStage, 'title'));
        $oldStage->bg_color = sanitize_text_field(Arr::get($updatedStage, 'cover_bg'));
        $oldStage->save();
        do_action('fluent_boards/stage_updated', $board_id, $updatedStage, $stageBeforeUpdate);
        return $oldStage;
    }

    public function setDefaultAssignees($stage_id, $assignees)
    {
        $stage = Stage::findOrFail($stage_id);
        if ($stage) {
            $oldSettings = $stage->settings;
            $oldSettings['default_task_assignees'] = $assignees;
            $stage->settings = $oldSettings;
            $stage->save();

            do_action('fluent_boards/default_assignees_updated', $stage, $assignees);

            return $stage;
        }
    }

    public function getAllParentAndSubTasksOfStages($stageIds)
    {
        // Fetch parent task IDs for the given stage_ids
        $parentTaskIds = Task::whereIn('stage_id', $stageIds)
            ->whereNull('archived_at')
            ->whereNull('parent_id')
            ->pluck('id');

        // Fetch parent tasks and subtasks in a single query
        $tasks = Task::whereIn('stage_id', $stageIds)
            ->whereNull('parent_id')
            ->whereNull('archived_at')
            ->get();

        $subtasks = Task::whereIn('parent_id', $parentTaskIds)
            ->get();

        // Combine parent tasks and subtasks into a single collection
        return $tasks->merge($subtasks);
    }
}
