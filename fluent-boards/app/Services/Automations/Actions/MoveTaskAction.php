<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\TaskService;

class MoveTaskAction extends BaseAction
{
    protected $actionName = 'move_task';

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Move task / Change stage', 'fluent-boards'),
            'description' => __('Move the task to another stage, on its own board or another one.', 'fluent-boards'),
        ];
    }

    public function getFields()
    {
        return [
            $this->boardField(),
            [
                'key' => 'stage_id',
                'type' => 'select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('Stage', 'fluent-boards'),
            ],
        ];
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $task = $context->getTask();
        $stageId = absint($settings['stage_id'] ?? 0);

        if (!$task || !$stageId) {
            throw new \Exception(esc_html__('Task and stage are required.', 'fluent-boards'));
        }

        $stage = $this->findStage($stageId, $this->targetBoardId($settings, $context));

        if ((int) $task->stage_id === (int) $stage->id) {
            return ['changed' => false];
        }

        $taskService = new TaskService();
        $fromBoardId = (int) $task->board_id;

        // Another board: same clean-up as a manual board move (labels, assignees and share link stay behind).
        if ($fromBoardId !== (int) $stage->board_id) {
            $task = $taskService->changeBoardByTask($task, (int) $stage->board_id);
        }

        $taskService->moveTaskToStage($stage->id, $task);

        return [
            'changed' => true,
            'stage_id' => (int) $stage->id,
            'board_id' => (int) $stage->board_id,
            'from_board_id' => $fromBoardId,
        ];
    }
}
