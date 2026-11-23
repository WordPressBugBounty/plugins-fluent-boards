<?php

namespace FluentBoards\App\Services\Automations\Triggers;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseTrigger;

class TaskCompletedTrigger extends BaseTrigger
{
    protected $triggerName = 'task_completed';

    protected $hookName = 'fluent_boards/task_closed';

    public function getTrigger()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Task completed', 'fluent-boards'),
            'description' => __('Runs when a task is marked as completed.', 'fluent-boards'),
        ];
    }

    public function createContext(array $originalArgs)
    {
        $task = $originalArgs[0] ?? null;

        // task_closed also fires for subtasks; this trigger is about board tasks.
        if (!$task instanceof Task || $task->parent_id) {
            return null;
        }

        return AutomationContext::fromTask($this->triggerName, $task, $originalArgs);
    }
}
