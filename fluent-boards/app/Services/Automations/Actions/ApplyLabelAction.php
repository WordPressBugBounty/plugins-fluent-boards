<?php

namespace FluentBoards\App\Services\Automations\Actions;

use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Services\Automations\AutomationContext;
use FluentBoards\App\Services\Automations\BaseAction;
use FluentBoards\App\Services\Constant;

class ApplyLabelAction extends BaseAction
{
    protected $actionName = 'apply_label';

    public function getBlock()
    {
        return [
            'category' => __('Task', 'fluent-boards'),
            'title' => __('Apply label', 'fluent-boards'),
            'description' => __('Apply one or more labels to the task.', 'fluent-boards'),
        ];
    }

    public function getFields()
    {
        return [
            [
                'key' => 'label_ids',
                'type' => 'multi-select',
                'option_key' => 'labels',
                'label' => __('Labels', 'fluent-boards'),
            ],
        ];
    }

    public function handle(AutomationContext $context, array $settings)
    {
        $task = $context->getTask();
        $labelIds = array_filter(array_map('absint', (array) ($settings['label_ids'] ?? [])));
        $added = [];
        $skipped = [];

        if (!$task || !$labelIds) {
            throw new \Exception(esc_html__('Task and labels are required.', 'fluent-boards'));
        }

        foreach ($labelIds as $labelId) {
            $label = Label::where('id', $labelId)
                ->where('board_id', $task->board_id)
                ->first();

            // On an any-board automation, labels from other boards cannot go on this task; say so in the run log.
            if (!$label) {
                $skipped[] = $labelId;
                continue;
            }

            $exists = Relation::where('object_id', $task->id)
                ->where('foreign_id', $labelId)
                ->where('object_type', Constant::OBJECT_TYPE_TASK_LABEL)
                ->exists();

            if ($exists) {
                continue;
            }

            $task->labels()->syncWithoutDetaching([$labelId => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
            $added[] = $labelId;

            do_action('fluent_boards/task_label', $task, $label, 'added');
        }

        return ['added' => $added, 'skipped' => $skipped];
    }
}
