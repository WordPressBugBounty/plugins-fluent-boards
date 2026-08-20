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
                'title' => 'Task from automation of {{contact.email}}',
                'created_by' => get_current_user_id(),
                'assignees' => [],
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

                'created_by' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'board_members',
                    'is_multiple' => false,
                    'label'       => __('Created by', 'fluent-boards'),
                    'placeholder' => __('Select a task creator', 'fluent-boards'),
                ],

                'assignees' => [
                    'type'        => 'rest_selector',
                    'option_key'  => 'board_members',
                    'is_multiple' => true,
                    'label'       => __('Assign to', 'fluent-boards'),
                    'placeholder' => __('Select task assignees', 'fluent-boards'),
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
                    'inline_help' =>  __('Keeping it blank will create the task with no priority', 'fluent-boards'),
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
        $creatorId = $this->resolveCreatorId($data, $sequence);
        $configuredAssignees = Arr::get($data, 'assignees', []);
        $hasConfiguredAssignees = !empty($configuredAssignees);
        $assigneeIds = $this->resolveAssigneeIds($configuredAssignees);

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
            $task['created_by'] = $creatorId;
            $task['comments_count'] = 0;
            $task->moveToNewPosition(1);
            $task->save();

            if ($hasConfiguredAssignees) {
                foreach ($assigneeIds as $assigneeId) {
                    $taskService->updateAssignee($assigneeId, $task);
                }
            } else {
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
                'priority'       => $priority ?? '',
                'due_at'         => $due_date ?? null,
                'position'       => (new TaskService())->getLastPositionOfTasks($stageId),
                'created_by'     => $creatorId,
                'assignees'      => $assigneeIds,
            ]);
        }

        FunnelHelper::changeFunnelSubSequenceStatus($funnelSubscriberId, $sequence->id, 'completed');

    }

    /**
     * Resolve a valid WordPress user to own tasks created by the automation.
     */
    private function resolveCreatorId($data, $sequence): int
    {
        $candidateIds = [
            Arr::get($data, 'created_by'),
            $sequence->created_by ?? 0,
            get_current_user_id(),
        ];
        $existingIds = $this->getExistingUserIds($candidateIds);

        return $existingIds[0] ?? 0;
    }

    /**
     * Normalize configured task assignees to existing WordPress user IDs.
     */
    private function resolveAssigneeIds($assignees): array
    {
        return $this->getExistingUserIds((array) $assignees);
    }

    /**
     * Keep positive, unique IDs that still belong to WordPress users.
     */
    private function getExistingUserIds($userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('absint', (array) $userIds))));

        if (!$userIds) {
            return [];
        }

        $users = get_users([
            'include' => $userIds,
            'fields'  => 'ID',
        ]);
        $existingIds = array_map(function ($user) {
            return absint(is_object($user) ? $user->ID : $user);
        }, $users);
        $existingLookup = array_flip($existingIds);

        return array_values(array_filter($userIds, function ($userId) use ($existingLookup) {
            return isset($existingLookup[$userId]);
        }));
    }

}
