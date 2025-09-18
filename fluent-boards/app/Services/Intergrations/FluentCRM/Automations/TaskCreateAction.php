<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM\Automations;

use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;
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
                'create_task_type' => 'new',
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

                'create_task_type' => [
                    'type'          => 'radio',
                    'wrapper_class' => 'fc_half_field',
                    'label'         => __('Task Create Type', 'fluent-boards'),
                    'options'       => [
                        [
                            'id'    => 'new',
                            'title' => __('Create from stratch', 'fluent-boards')
                        ],
                        [
                            'id'    => 'template',
                            'title' => __('Create from template', 'fluent-boards')
                        ]
                    ]
                ],

                'task_template'       => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'task_templates',
                    'is_multiple' => false,
                    'label'       => __('Select Task Template', 'fluent-boards'),
                    'placeholder' => __('Select Template', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'template'
                    ]
                ],

                'title_template' => [
                    'type'        => 'input-text-popper',
                    'field_type'  => 'text',
                    'label'       => __('Task Title', 'fluent-boards'),
                    'placeholder' => __('Task Title', 'fluent-boards'),
                    'inline_help' =>  __('Leaving it blank will make the title the same as the template task title.', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'template'
                    ]
                ],

                'title' => [
                    'type'        => 'input-text-popper',
                    'field_type'  => 'text',
                    'label'       => __('Task Title', 'fluent-boards'),
                    'placeholder' => __('Task Title', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'new'
                    ]
                ],

                'due_day' => [
                    'label'         => __('Due Date', 'fluent-boards'),
                    'type'          => 'input-number',
                    'inline_help'   => __('Set to your_input days after task creation, values less than zero will set due date to null', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'new'
                    ]
                ],

                'description' => [
                    'type'          => 'html_editor',
                    'smart_codes'   => 'yes',
                    'context_codes' => 'yes',
                    'label'         => __('Description', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'new'
                    ]
                ],

                'priority' => [
                    'type'        => 'select',
                    'label'       => __('Select Priority', 'fluent-boards'),
                    'options'     => Helper::getPriorityOptions(),
                    'inline_help' =>  __('Keeping it blank will select priority to low', 'fluent-boards'),
                    'dependency'    => [
                        'depends_on' => 'create_task_type',
                        'operator'   => '=',
                        'value'      => 'new'
                    ]
                ]
            ]
        ];
    }

    public function handle( $subscriber, $sequence, $funnelSubscriberId, $funnelMetric )
    {
        $data = $sequence->settings;

        $createType = Arr::get($data, 'create_task_type');
        $stage = Arr::get($data, 'stage'); // this is a string of the pipeline stage id

        if ( empty($stage) ) {
            FunnelHelper::changeFunnelSubSequenceStatus( $funnelSubscriberId, $sequence->id, 'skipped' );
            return;
        }
        
        $stageId = (int) $stage;
        if ($stageId <= 0) {
            return;
        }

        $board = Helper::getBoardByStageId( $stageId );

        if ($createType === 'template') {
            $templateTaskId = Arr::get($data, 'task_template');
            if (!$templateTaskId) {
                FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'skipped');
                return;
            }

            $title = Arr::get($data, 'title_template');

            $templateTask = Task::find($templateTaskId);

            $taskData = array();
            $taskData['board_id'] = $board->id;
            $taskData['stage_id'] = $stageId;
            $taskData['assignee'] = true;
            $taskData['label'] = true;

            $taskService = new TaskService();

            $task = new Task();

            $task->fill($templateTask->toArray());

            if (!empty($title)) {
                $task['title'] = $title;
            }

            $task['board_id'] = $taskData['board_id'];
            $task['stage_id'] = $taskData['stage_id'];
            $task['created_by'] = get_current_user_id();
            $task['comments_count'] = 0;
            $task->moveToNewPosition(1);
            $task->save();

            if (isset($taskData['assignee']) && $taskData['assignee'] == 'true') {
                $templateTask->load('assignees');
                foreach ($templateTask->assignees as $assignee) {
                    $taskService->updateAssignee($assignee->ID, $task);
                }
            }
            if (isset($taskData['label']) && $taskData['label'] == 'true') {
                $templateTask->load('labels');
                foreach ($templateTask->labels as $label) {
                    $task->labels()->syncWithoutDetaching([$label->id => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
                }
            }
        } else {
            $title = Arr::get( $data, 'title');
            $priority = Arr::get( $data, 'priority');
            $due_day = Arr::get( $data, 'due_day');

            if(isset($due_day)) {
                $due_date = Helper::dueDateConversion($due_day, 'day');
            }

            $description = Arr::get( $data, 'description');

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
                'priority'       => $priority ?? 'low',
                'due_at'         => $due_date ?? null,
                'position'       => (new TaskService())->getLastPositionOfTasks($stageId),
            ]);
        }

        FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'completed');

    }

}
