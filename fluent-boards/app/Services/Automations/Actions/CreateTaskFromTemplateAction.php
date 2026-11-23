<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\TaskService;

class CreateTaskFromTemplateAction extends BaseAction
{
    protected $actionName = 'create_task_from_template';

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Create task from template', 'fluent-boards'),
            'description' => __('Create a new task by cloning a template task.', 'fluent-boards'),
        ];
    }

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

    public function getFields()
    {
        return array_merge([
            $this->boardField(),
            [
                'key' => 'template_task_id',
                'type' => 'select',
                'option_key' => 'task_templates',
                'board_field' => 'board_id',
                'label' => __('Template task', 'fluent-boards'),
            ],
            [
                'key' => 'stage_id',
                'type' => 'select',
                'option_key' => 'stages',
                'board_field' => 'board_id',
                'label' => __('Target stage', 'fluent-boards'),
            ],
            [
                'key' => 'title',
                'label' => __('Task title', 'fluent-boards'),
                'placeholder' => __('Leave blank to use the template title', 'fluent-boards'),
                'smart_codes' => true,
            ],
        ], $this->keepFields());
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $templateTaskId = absint($settings['template_task_id'] ?? 0);
        // No stage picked on the task's own board: use the task's stage, as before boards were pickable.
        $stageId = absint($settings['stage_id'] ?? 0)
            ?: (empty($settings['board_id']) ? absint($context->get('stage_id')) : 0);

        if (!$templateTaskId || !$stageId) {
            throw new \Exception(esc_html__('Template task and target stage are required.', 'fluent-boards'));
        }

        // Template and stage come from the action's board; "same board" means the triggering task's.
        $stage = $this->findStage($stageId, $this->targetBoardId($settings, $context));

        $templateTask = Task::where('id', $templateTaskId)
            ->where('board_id', $stage->board_id)
            ->first();
        if (!$templateTask || !$this->isTemplateTask($templateTask)) {
            throw new \Exception(esc_html__('Template task not found on the selected board.', 'fluent-boards'));
        }

        $title = sanitize_text_field($context->replaceSmartCodes($settings['title'] ?? ''));

        $clonedTask = (new TaskService())->cloneTask($templateTask->id, array_merge(
            $this->keepOptions($settings),
            ['title' => $title ?: $templateTask->title, 'stage_id' => (int) $stage->id]
        ), $templateTask->board_id);

        $this->markAutomationCopy($clonedTask, $context);

        do_action('fluent_boards/task_created_from_template', $clonedTask, $templateTask, $context);

        return [
            'task_id' => (int) $clonedTask->id,
            'template_task_id' => (int) $templateTask->id,
            'stage_id' => (int) $clonedTask->stage_id,
        ];
    }

    protected function isTemplateTask(Task $task)
    {
        return TaskMeta::where('task_id', $task->id)
            ->where('key', Constant::IS_TASK_TEMPLATE)
            ->where('value', 'yes')
            ->exists();
    }
}
