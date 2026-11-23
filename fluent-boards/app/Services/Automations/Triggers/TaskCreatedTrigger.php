<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class TaskCreatedTrigger extends BaseTrigger
{
    protected $triggerName = 'task_created';

    protected $hookName = 'fluent_boards/task_created';

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Task created', 'fluent-boards'),
            'description' => __('Runs when a new task is created on a board.', 'fluent-boards'),
        ];
    }

    public function getSettingsFields()
    {
        return array_merge(parent::getSettingsFields(), [
            [
                'key' => 'stage_ids',
                'type' => 'multi-select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('In stages', 'fluent-boards'),
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

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs);
    }

    public function matches(AutomationContext $context, array $settings)
    {
        if (!parent::matches($context, $settings)) {
            return false;
        }

        return $this->matchesStage($context->get('stage_id'), $settings['stage_ids'] ?? [], (int) $context->get('board_id'));
    }
}
