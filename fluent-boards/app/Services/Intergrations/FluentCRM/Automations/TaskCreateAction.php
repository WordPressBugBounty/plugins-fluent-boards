<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM\Automations;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\TaskService;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\Framework\Support\Arr;
use FluentBoards\App\Services\Helper;

class TaskCreateAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'add_task_to_board';
        $this->priority = 20;
        parent::__construct();
    }

    public function getBlock()
    {
        return [
            'category' => __('FluentBoards', 'fluent-boards'),
            'title'       => __('Create Task', 'fluent-boards'),
            'description' => __('Create Task to the selected Board', 'fluent-boards'),
            'icon' => 'fc-icon-apply_list',
            'settings'    => [
                'stage' => [],
                'title' => 'Task from automation of {{contact.email}}'
            ]
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Create Task', 'fluent-boards'),
            'sub_title' => __('Select which Board & Stage where task will be created', 'fluent-boards'),
            'fields'    => [
                'stage' => [
                    'type'        => 'grouped-select',
                    'is_multiple' => false,
                    'label'       => __('Select Board & It\'s stage', 'fluent-boards'),
                    'placeholder' => __('Select Board & It\'s stage', 'fluent-boards'),
                    'options'     => Helper::getStagesByBoardGroup()
                ],
                'title' => [
                    'type'        => 'input-text-popper',
                    'field_type'  => 'text',
                    'label'       => __('Task Title', 'fluent-boards'),
                    'placeholder' => __('Task Title', 'fluent-boards')
                ],

                'due_day' => [
                    'label'         => __('Due Date', 'fluent-boards'),
                    'type'          => 'input-number',
                    'inline_help'   => __('Set to your_input days after task creation, values less than zero will set due date to null', 'fluent-boards')
                ],

                'description' => [
                    'type'          => 'html_editor',
                    'smart_codes'   => 'yes',
                    'context_codes' => 'yes',
                    'label'         => __('Description', 'fluent-boards')
                ],

                'priority' => [
                    'type'        => 'select',
                    'label'       => __('Select Priority', 'fluent-boards'),
                    'options'     => Helper::getPriorityOptions(),
                    'inline_help' =>  __('Keeping it blank will select priority to low', 'fluent-boards')
                ]
            ]
        ];
    }

    public function handle( $subscriber, $sequence, $funnelSubscriberId, $funnelMetric )
    {
        $data = $sequence->settings;

        $stage = Arr::get($data, 'stage'); // this is a string of the pipeline stage id
        $title = Arr::get( $data, 'title');
        $priority = Arr::get( $data, 'priority');
        $due_day = Arr::get( $data, 'due_day');

        if(isset($due_day)) {
            $due_date = Helper::dueDateConversion($due_day, 'day');
        }

        $description = Arr::get( $data, 'description');

        if ( empty($stage) ) {
            FunnelHelper::changeFunnelSubSequenceStatus( $funnelSubscriberId, $sequence->id, 'skipped' );
            return;
        }
        $stageId = intval( $stage );
        $board   = Helper::getBoardByStageId( $stageId );

        if ( !$stage ) {
            return;
        }

        $description = apply_filters('fluent_crm/parse_campaign_email_text', $description, $subscriber);
        $title       = apply_filters('fluent_crm/parse_campaign_email_text', $title, $subscriber);

        (new Task())->createTask([
            'title'          => $title,
            'board_id'       => $board->id,
            'crm_contact_id' => $subscriber->id,
            'type'           => 'task',
            'status'         => 'open',
            'stage_id'       => $stageId,
            'source'         => 'funnel',
            'description'    => $description,
            'priority'       => $priority,
            'due_at'         => $due_date ?? null,
            'position'       => (new TaskService())->getLastPositionOfTasks($stageId),
        ]);

        FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'completed');

    }

}