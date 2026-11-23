<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class AssigneeAddedTrigger extends BaseTrigger
{
    protected $triggerName = 'assignee_added';

    protected $hookName = 'fluent_boards/task_assignee_added';

    protected $actionArgNum = 2;

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Assignee added', 'fluent-boards'),
            'description' => __('Runs when someone is assigned to a task.', 'fluent-boards'),
        ];
    }

    public function getSettingsFields()
    {
        return array_merge(parent::getSettingsFields(), [
            [
                'key' => 'assignee_ids',
                'type' => 'multi-select',
                'option_key' => 'users',
                'label' => __('Assignees', 'fluent-boards'),
                'placeholder' => __('Any assignee', 'fluent-boards'),
            ],
        ]);
    }

    public function createContext(array $originalArgs)
    {
        $task = $originalArgs[0] ?? null;
        if (!$task instanceof Task) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs, [
            'assignee_id' => absint($originalArgs[1] ?? 0),
        ]);
    }

    public function matches(AutomationContext $context, array $settings)
    {
        if (!parent::matches($context, $settings)) {
            return false;
        }

        $assigneeIds = array_filter(array_map('absint', (array) ($settings['assignee_ids'] ?? [])));

        return !$assigneeIds || in_array((int) $context->get('assignee_id'), $assigneeIds, true);
    }
}
