<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\TaskService;

class DuplicateTaskAction extends BaseAction
{
    protected $actionName = 'duplicate_task';

    public function getSettingsDefaults()
    {
        return [
            'keep_assignees' => 'yes',
            'keep_labels' => 'yes',
            'keep_subtasks' => 'no',
            'keep_attachments' => 'no',
            'keep_comments' => 'no',
        ];
    }

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Duplicate task', 'fluent-boards'),
            'description' => __('Make a copy of the task, on its own board or another one.', 'fluent-boards'),
        ];
    }

    public function getFields()
    {
        return array_merge([
            $this->boardField(),
            [
                'key' => 'stage_id',
                'type' => 'select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('Stage', 'fluent-boards'),
                'placeholder' => __('Same stage as the task', 'fluent-boards'),
            ],
            [
                'key' => 'title',
                'label' => __('Task title', 'fluent-boards'),
                'placeholder' => __('Leave blank to use the task title', 'fluent-boards'),
                'smart_codes' => true,
            ],
        ], $this->keepFields());
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $task = $context->getTask();
        if (!$task) {
            throw new \Exception(esc_html__('Task is required.', 'fluent-boards'));
        }

        $boardId = $this->targetBoardId($settings, $context);
        $stageId = absint($settings['stage_id'] ?? 0);
        $sameBoard = $boardId === (int) $task->board_id;

        if (!$stageId && !$sameBoard) {
            throw new \Exception(esc_html__('Select a stage on the selected board.', 'fluent-boards'));
        }

        $stage = $this->findStage($stageId ?: $task->stage_id, $boardId);
        $title = sanitize_text_field($context->replaceSmartCodes($settings['title'] ?? ''));
        $taskService = new TaskService();

        // cloneTask() copies within the task's board; a copy for another board then moves there
        // and takes the target stage's defaults instead of the source stage's.
        $copy = $taskService->cloneTask($task->id, array_merge($this->keepOptions($settings), [
            'title' => $title ?: $task->title,
            'stage_id' => $sameBoard ? (int) $stage->id : (int) $task->stage_id,
            'skip_stage_defaults' => !$sameBoard,
        ]), $task->board_id);

        if (!$sameBoard) {
            $copy = $this->moveCopyToStage(Task::findOrFail($copy->id), $stage, $taskService);
        }

        $this->markAutomationCopy($copy, $context);

        return [
            'task_id' => (int) $copy->id,
            'source_task_id' => (int) $task->id,
            'board_id' => (int) $stage->board_id,
            'stage_id' => (int) $stage->id,
        ];
    }

    /**
     * Put a fresh copy on another board's stage, the way a bulk move does: last in the stage,
     * the stage's default assignees, closed when the stage closes tasks. If the move fails the
     * copy is deleted, so a failed run never leaves a stray copy on the source board.
     */
    protected function moveCopyToStage(Task $copy, $stage, TaskService $taskService)
    {
        try {
            $copy = $taskService->changeBoardByTask($copy, (int) $stage->board_id);
            $copy->stage_id = (int) $stage->id;
            $copy = $copy->moveToNewPosition(null);
            $taskService->manageDefaultAssignees($copy, $stage->id, $stage);

            if ($stage->defaultTaskStatus() === 'closed') {
                $copy->close();
            }
        } catch (\Exception $e) {
            $taskService->deleteTask(Task::find($copy->id) ?: $copy);
            throw $e;
        }

        return $copy;
    }
}
