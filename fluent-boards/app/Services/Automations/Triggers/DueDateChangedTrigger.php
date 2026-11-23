<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class DueDateChangedTrigger extends BaseTrigger
{
    protected $triggerName = 'due_date_changed';

    protected $hookName = 'fluent_boards/task_due_date_changed';

    protected $actionArgNum = 2;

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Due date changed', 'fluent-boards'),
            'description' => __('Runs when a task due date is set or changed.', 'fluent-boards'),
        ];
    }

    public function createContext(array $originalArgs)
    {
        $task = $originalArgs[0] ?? null;
        if (!$task instanceof Task || $task->parent_id) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs, [
            'old_due_at' => $originalArgs[1] ?? null,
            'due_at' => $task->due_at,
        ]);
    }
}
