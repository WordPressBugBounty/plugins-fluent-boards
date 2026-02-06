<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM\Automations;

use FluentBoards\App\Services\Helper;
use FluentCrm\App\Services\Funnel\BaseTrigger;
use FluentCrm\App\Services\Funnel\FunnelHelper;
use FluentCrm\App\Services\Funnel\FunnelProcessor;
use FluentCrm\Framework\Support\Arr;

class ContactAddedBoardTrigger extends BaseTrigger
{
    public function __construct()
    {
        $this->triggerName = 'fluent_boards/contact_added_to_board';
        $this->actionArgNum = 2;
        $this->priority = 20;

        parent::__construct();
    }

    public function getTrigger()
    {
        return [
            'category'    => __('FluentBoards', 'fluent-boards'),
            'label'       => __('Contact Added to Board', 'fluent-boards'),
            'description' => __('This will run when a contact will be added to a board', 'fluent-boards'),
            'icon'        => 'fc-icon-list_applied_2'
        ];
    }

    public function getFunnelSettingsDefaults()
    {
        return [
            'boards'       => [],
            'select_type'  => 'any'
        ];
    }

    public function getSettingsFields($funnel)
    {
        return [
            'title'       => __('Contact Added to Board', 'fluent-boards'),
            'sub_title'   => __('This will run when a contact will be added to a board', 'fluent-boards'),
            'fields'      => [
                'boards'       => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'boards',
                    'is_multiple' => true,
                    'label'       => __('Select Board', 'fluent-boards'),
                    'placeholder' => __('Select Board', 'fluent-boards'),
                ],
                'select_type' => [
                    'label'      => __('Run When', 'fluent-boards'),
                    'type'       => 'radio',
                    'options'    => [
                        [
                            'id'    => 'any',
                            'title' => __('Contact added to any of the selected boards', 'fluent-boards')
                        ]
                    ],
                    'dependency' => [
                        'depends_on' => 'boards',
                        'operator'   => '!=',
                        'value'      => []
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
        $board = $originalArgs[0];
        $contactId = $originalArgs[1];

        $subscriber = Helper::crm_contact($contactId);


        $willProcess = $this->isProcessable($funnel, $board,  $subscriber);

        if (!$willProcess) {
            return;
        }

        (new FunnelProcessor())->startFunnelSequence($funnel, $subscriber, [
            'source_trigger_name' => $this->triggerName
        ]);
    }

    private function isProcessable($funnel, $board, $subscriber)
    {
        $boards = $funnel->settings['boards'];

        if($boards){
            $attachIntersection = array_intersect($boards, [$board->id]);
            if(!$attachIntersection) {
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