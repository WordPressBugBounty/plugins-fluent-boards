<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class TaskMovedTrigger extends BaseTrigger
{
    protected $triggerName = 'task_moved';

    protected $hookName = 'fluent_boards/task_stage_updated';

    protected $actionArgNum = 2;

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Task moved', 'fluent-boards'),
            'description' => __('Runs when a task moves from one stage to another.', 'fluent-boards'),
        ];
    }

    public function getSettingsFields()
    {
        return array_merge(parent::getSettingsFields(), [
            [
                'key' => 'from_stage_ids',
                'type' => 'multi-select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('From stages', 'fluent-boards'),
                'placeholder' => __('Any stage', 'fluent-boards'),
            ],
            [
                'key' => 'to_stage_ids',
                'type' => 'multi-select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('To stages', 'fluent-boards'),
                'placeholder' => __('Any stage', 'fluent-boards'),
            ],
        ]);
    }

    public function createContext(array $originalArgs)
    {
        $task = $originalArgs[0] ?? null;
        if (!$task instanceof Task) {
            return null;
        }

        $oldStageId = absint($originalArgs[1] ?? 0);

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs, [
            'old_stage_id' => $oldStageId,
            'new_stage_id' => (int) $task->stage_id,
        ]);
    }

    public function matches(AutomationContext $context, array $settings)
    {
        if (!parent::matches($context, $settings)) {
            return false;
        }

        // A cross-board move reports the source stage alongside the task on its destination board.
        $fromStageIds = array_filter(array_map('absint', (array) ($settings['from_stage_ids'] ?? [])));
        if ($fromStageIds && !in_array((int) $context->get('old_stage_id'), $fromStageIds, true)) {
            return false;
        }

        return $this->matchesStage($context->get('new_stage_id'), $settings['to_stage_ids'] ?? [], (int) $context->get('board_id'));
    }
}
