<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM\Automations;

use FluentCrm\App\Models\Subscriber;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;
use FluentBoards\App\Services\Helper;

class StageChangedTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_boards/task_stage_updated';
        $this->actionArgNum = 3;
        $this->priority = 20;

        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FluentBoards', 'fluent-boards'),
            'label'       => __('Stage Changed', 'fluent-boards'),
            'description' => __('This funnel will run when stage of a task(associated with crm contact) is changed', 'fluent-boards'),
            'icon'        => 'fc-icon-list_applied_2'
        ];
    }
    public function getFunnelSettingsDefaults()
    {
        return [
            'board_id'          => null,
            'board_stages_from'    => [],
            'board_stages_to'    => []
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'       => __('Stage Changed', 'fluent-boards'),
            'sub_title'   => __('This funnel will run when stage of a task(associated with crm contact) is changed', 'fluent-boards'),
            'fields'      => [
                'board_id'       => [
                    'type'        => 'reload_rest_selector',
                    'option_key'  => 'boards',
                    'is_multiple' => false,
                    'label'       => __('Select Board', 'fluent-boards'),
                    'placeholder' => __('Select Board', 'fluent-boards'),
                ],
                'board_stages_from'       => [
                    'type'        => 'multi-select',
                    'multiple'    => true,
                    'label'       => __('From stage', 'fluent-boards'),
                    'options'     => Helper::getFormattedStagesByBoardId($funnel->settings['board_id']),
                    'inline_help' => __('Leave empty to target any stage of this board', 'fluent-boards'),
                    'dependency' => [
                        'depends_on' => 'board_id',
                        'operator'   => '!=',
                        'value'      => null
                    ]
                ],
                'board_stages_to'       => [
                    'type'        => 'multi-select',
                    'multiple'    => true,
                    'label'       => __('Target stage', 'fluent-boards'),
                    'options'     => Helper::getFormattedStagesByBoardId($funnel->settings['board_id']),
                    'inline_help' => __('Leave empty to target any stage of this board', 'fluent-boards'),
                    'dependency' => [
                        'depends_on' => 'board_id',
                        'operator'   => '!=',
                        'value'      => null
                    ]
                ]
            ]
        ];
    }

    public function getFunnelConditionDefaults($funnel)
    {
        return [
            'run_multiple' => 'no'
        ];
    }

    public function getConditionFields($funnel)
    {
        return [
            'run_multiple' => [
                'type'        => 'yes_no_check',
                'label'       => '',
                'check_label' => __('Restart the Automation Multiple times for a contact for this event. (Only enable if you want to restart automation for the same contact)', 'fluent-boards'),
                'inline_help' => __('If you enable, then it will restart the automation for a contact if the contact already in the automation. Otherwise, It will just skip if already exist', 'fluent-boards')
            ]
        ];
    }

    public function handle($funnel, $originalArgs)
    {
        $task = $originalArgs[0];

        //prepare subscriber data
        $subscriber = Subscriber::find($task->crm_contact_id);
        if (!$subscriber) {
            return;
        }

        $willProcess = $this->isProcessable($funnel, $originalArgs,  $subscriber);

        if (!$willProcess) {
            return;
        }

        (new FunnelProcessor())->startFunnelSequence($funnel, [], [
            'source_trigger_name' => $this->triggerName
        ], $subscriber);
    }

    private function isProcessable($funnel, $originalArgs, $subscriber)
    {
        $boardId = $funnel->settings['board_id'];
        $task = $originalArgs[0];
        $oldStageId = $originalArgs[1];
        $fromStages = $funnel->settings['board_stages_from'];
        $toStages = $funnel->settings['board_stages_to'];

        if($boardId){
            if($task->board_id != $boardId) {
                return false;
            }
        }

        //if empty then true for any stage
        if(!empty($fromStages))
        {
            //checking if task is coming from desired stage
            $isFromStageMatch = in_array($oldStageId, $fromStages);
            if(!$isFromStageMatch){
                return false;
            }
        }

        //if empty then true for any stage
        if(!empty($toStages))
        {
            //checking if task is moved to desired stage
            $isToStageMatch = in_array($task->stage_id, $toStages);
            if(!$isToStageMatch){
                return false;
            }
        }

        //check run_only_one
        if ($subscriber && FunnelHelper::ifAlreadyInFunnel($funnel->id, $subscriber->id)) {
            $multipleRun = Arr::get($funnel->conditions, 'run_multiple') == 'yes';
            if ($multipleRun) {
                FunnelHelper::removeSubscribersFromFunnel($funnel->id, [$subscriber->id]);
            } else {
                return false;
            }
        }

        return true;
    }
}
