<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\TaskService;

class AddAssigneeAction extends BaseAction
{
    protected $actionName = 'add_assignee';

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Add assignee', 'fluent-boards'),
            'description' => __('Assign one or more users to the task.', 'fluent-boards'),
        ];
    }

    public function getFields()
    {
        return [
            [
                'key' => 'assignee_ids',
                'type' => 'multi-select',
                'option_key' => 'users',
                'label' => __('Assignees', 'fluent-boards'),
            ],
        ];
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $task = $context->getTask();
        $assigneeIds = array_filter(array_map('absint', (array) ($settings['assignee_ids'] ?? [])));

        if (!$task || !$assigneeIds) {
            throw new \Exception(esc_html__('Task and assignees are required.', 'fluent-boards'));
        }

        // Only board members may be assigned; the setting can outlive a membership.
        $memberIds = PermissionManager::getUserIdsWithBoardAccess($task->board_id, $assigneeIds);
        $currentIds = $task->assignees->pluck('ID')->toArray();
        $taskService = new TaskService();
        $added = [];

        foreach (array_diff($memberIds, array_map('absint', $currentIds)) as $assigneeId) {
            // updateAssignee toggles, so it is only called for users not yet assigned.
            $taskService->updateAssignee($assigneeId, $task);
            $added[] = (int) $assigneeId;
        }

        return [
            'added'   => $added,
            'skipped' => array_values(array_diff($assigneeIds, $memberIds)),
        ];
    }
}
