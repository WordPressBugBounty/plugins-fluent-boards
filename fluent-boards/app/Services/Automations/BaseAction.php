<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Services\Constant;

abstract class BaseAction
{
    protected $actionName = '';

    public function getName()
    {
        return sanitize_key($this->actionName);
    }

    public function getSettingsDefaults()
    {
        return [];
    }

    public function getFields()
    {
        return [];
    }

    abstract public function getBlock();

    abstract public function handle(AutomationContext $context, array $settings);

    /**
     * Board picker for actions that put a task somewhere. Empty means the triggering task's board.
     * Fields that list board items name it in `board_field` so the editor loads them for that board.
     */
    protected function boardField()
    {
        return [
            'key' => 'board_id',
            'type' => 'select',
            'option_key' => 'boards',
            'label' => __('Board', 'fluent-boards'),
            'placeholder' => __('Same board as the task', 'fluent-boards'),
        ];
    }

    /** The action's board setting, or the triggering task's board when it is empty. */
    protected function targetBoardId(array $settings, AutomationContext $context)
    {
        return absint($settings['board_id'] ?? 0) ?: $context->getBoardId();
    }

    /** A live stage on a live board, or an exception naming what is wrong. */
    protected function findStage($stageId, $boardId)
    {
        $board = $boardId ? Board::whereNull('archived_at')->find($boardId) : null;
        if (!$board) {
            throw new \Exception(esc_html__('The selected board was not found or is archived.', 'fluent-boards'));
        }

        $stage = Stage::where('id', absint($stageId))
            ->where('board_id', $board->id)
            ->whereNull('archived_at')
            ->first();

        if (!$stage) {
            throw new \Exception(esc_html__('The selected stage is not on the selected board.', 'fluent-boards'));
        }

        return $stage;
    }

    /** "Keep ..." switches for actions that copy a task (template, duplicate). */
    protected function keepFields()
    {
        $labels = [
            'keep_assignees' => __('Keep assignees', 'fluent-boards'),
            'keep_labels' => __('Keep labels', 'fluent-boards'),
            'keep_subtasks' => __('Keep subtasks', 'fluent-boards'),
            'keep_attachments' => __('Keep attachments', 'fluent-boards'),
            'keep_comments' => __('Keep comments', 'fluent-boards'),
        ];

        $fields = [];
        foreach ($labels as $key => $label) {
            $fields[] = ['key' => $key, 'type' => 'switch', 'label' => $label];
        }

        return $fields;
    }

    /** cloneTask() options from the "Keep ..." switches. */
    protected function keepOptions(array $settings)
    {
        return [
            'assignee' => $this->isYes($settings['keep_assignees'] ?? 'yes'),
            'label' => $this->isYes($settings['keep_labels'] ?? 'yes'),
            'subtask' => $this->isYes($settings['keep_subtasks'] ?? 'no'),
            'attachment' => $this->isYes($settings['keep_attachments'] ?? 'no'),
            'comment' => $this->isYes($settings['keep_comments'] ?? 'no'),
        ];
    }

    /** A copy is a plain task made by the automation: not a template, not pinned. */
    protected function markAutomationCopy(Task $clonedTask, AutomationContext $context)
    {
        TaskMeta::where('task_id', $clonedTask->id)
            ->whereIn('key', [Constant::IS_TASK_TEMPLATE, Constant::IS_TASK_PINNED])
            ->delete();

        Task::where('id', $clonedTask->id)->update([
            'created_by' => $context->get('actor_id') ?: get_current_user_id(),
            'source' => 'automation',
            'updated_at' => current_time('mysql'),
        ]);
    }

    protected function isYes($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(sanitize_key((string) $value), ['yes', 'true', '1', 'on'], true);
    }
}
