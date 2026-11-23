<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class SubtaskAddedTrigger extends BaseTrigger
{
    protected $triggerName = 'subtask_added';

    protected $hookName = 'fluent_boards/subtask_added';

    protected $actionArgNum = 2;

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Subtask added', 'fluent-boards'),
            'description' => __('Runs when a subtask is added to a task.', 'fluent-boards'),
        ];
    }

    public function createContext(array $originalArgs)
    {
        $parentTask = $originalArgs[0] ?? null;
        $subtask = $originalArgs[1] ?? null;

        if (!$parentTask instanceof Task || !$subtask instanceof Task) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $parentTask, $originalArgs, [
            'subtask_id' => (int) $subtask->id,
            'subtask_title' => $subtask->title,
        ]);
    }
}
