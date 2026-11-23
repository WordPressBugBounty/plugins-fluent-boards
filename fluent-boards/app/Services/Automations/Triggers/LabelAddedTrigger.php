<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\BoardTerm;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class LabelAddedTrigger extends BaseTrigger
{
    protected $triggerName = 'label_added';

    protected $hookName = 'fluent_boards/task_label';

    protected $actionArgNum = 3;

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Label added', 'fluent-boards'),
            'description' => __('Runs when a label is applied to a task.', 'fluent-boards'),
        ];
    }

    public function getSettingsFields()
    {
        return array_merge(parent::getSettingsFields(), [
            [
                'key' => 'label_ids',
                'type' => 'multi-select',
                'option_key' => 'labels',
                'label' => __('Labels', 'fluent-boards'),
                'placeholder' => __('Select at least one label', 'fluent-boards'),
            ],
        ]);
    }

    public function createContext(array $originalArgs)
    {
        $task = $originalArgs[0] ?? null;
        $label = $originalArgs[1] ?? null;
        $operation = sanitize_key($originalArgs[2] ?? '');

        if ($operation !== 'added' || !$task instanceof Task || !$label instanceof BoardTerm) {
            return null;
        }

        if ($label->type !== 'label' || (int) $label->board_id !== (int) $task->board_id) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs, [
            'label_id' => (int) $label->id,
        ]);
    }

    public function matches(AutomationContext $context, array $settings)
    {
        if (!parent::matches($context, $settings)) {
            return false;
        }

        $labelIds = array_filter(array_map('absint', (array) ($settings['label_ids'] ?? [])));

        // No labels picked is an unfinished trigger, not "any label".
        return $labelIds && in_array((int) $context->get('label_id'), $labelIds, true);
    }
}
