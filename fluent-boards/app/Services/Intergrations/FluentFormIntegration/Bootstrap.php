<?php

namespace FluentBoards\App\Services\Intergrations\FluentFormIntegration;

use FluentBoards\App\Hooks\Handlers\AdminMenuHandler;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\TaskService;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Helper;
use FluentCrm\App\Models\Subscriber;
use FluentBoards\App\App;


class Bootstrap extends IntegrationManagerController
{
    public $disableGlobalSettings = 'yes';

    public function __construct($app)
    {
        parent::__construct(
            $app,
            'Fluent Boards',
            'fluent_boards',
            '_fluentform_fluent_boards_settings',
            'fluentform_fluent_boards_feed',
            30
        );

        $fluentBoards = App::getInstance();
        $this->logo = $fluentBoards['url.assets'] . 'images/icon.png';

        $this->description = 'Connect Fluent Boards with WP Fluent Forms and create a task when a form is submitted.';

        $this->registerAdminHooks();

        add_action('wp_ajax_fluentform_fluent_board_config', array($this, 'getBoardConfigOptions'));
//        add_filter('fluentform/notifying_async_fluent_boards', '__return_false');
    }

    public function addGlobalMenu($setting)
    {
        return $setting;
    }

    public function pushIntegration($integrations, $formId)
    {
        $integrations[$this->integrationKey] = [
            'title'                   => $this->title . ' Integration',
            'logo'                    => $this->logo,
            'disable_global_settings' => 'yes',
            'category'                => '',
            'is_active'               => $this->isConfigured()
        ];

        return $integrations;
    }

    public function isConfigured()
    {
        return true;
    }

    public function getIntegrationDefaults($settings, $formId)
    {
        return [
            'name'            => '',
            'list_id'         => '',
            'board_config'    => [
                'board_id'       => '',
                'stage_id'       => '',
                'board_label_id' => [],
                'member_ids'     => [],
                'crm_contact_id' => '',
                'priority'       => ''
            ],
            'task_title'      => '',
            'submitter_name'  => '',
            'submitter_email' => '',
            'priority'        => '',
            'description'     => '',
            'position'        => 'bottom',
            'due_at_days'     => '',
            'create_crm_contact'     => false,
            'conditionals' => [
                'conditions' => [],
                'status'     => false,
                'type'       => 'all'
            ],


            'enabled' => true
        ];
    }

    public function getSettingsFields($settings, $formId)
    {
        $data = [
            'fields'              => [
                [
                    'key'            => 'board_config',
                    'label'          => 'Fluent Boards Configuration',
                    'required'       => true,
                    'component'      => 'chained_select',
                    'primary_key'    => 'board_id',
                    'fields_options' => [
                        'board_id'       => [],
                        'stage_id'       => [],
                        'board_label_id' => [],
                        'member_ids'     => [],
                        'priority'       => []
                    ],
                    'options_labels' => [
                        'board_id'       => [
                            'label'       => 'Select Board',
                            'type'        => 'select',
                            'placeholder' => 'Select Board'
                        ],
                        'stage_id'       => [
                            'label'       => 'Select Stage',
                            'type'        => 'select',
                            'placeholder' => 'Select Stage'
                        ],
                        'board_label_id' => [
                            'label'       => 'Select Labels',
                            'type'        => 'multi-select',
                            'placeholder' => 'Select Labels'
                        ],
                        'member_ids'     => [
                            'label'       => 'Select Assignees',
                            'type'        => 'multi-select',
                            'placeholder' => 'Select Assignees'
                        ],
                        'priority'       => [
                            'label'       => 'Select Priority',
                            'type'        => 'select',
                            'placeholder' => 'Priority'
                        ]
                    ],
                    'remote_url'     => admin_url('admin-ajax.php?action=fluentform_fluent_board_config')
                ],
                [
                    'key'         => 'task_title',
                    'label'       => 'Task Title',
                    'required'    => true,
                    'placeholder' => 'Task Title',
                    'component'   => 'value_text'
                ],
                [
                    'key'         => 'description',
                    'label'       => 'Description',
                    'required'    => false,
                    'placeholder' => 'Describe your task',
                    'component'   => 'wp_editor',
                ],

                [
                    'key'         => 'submitter_name',
                    'label'       => 'Submitter Name',
                    'required'    => true,
                    'placeholder' => 'Submitter Name',
                    'component'   => 'value_text'
                ],
                [
                    'key'         => 'submitter_email',
                    'label'       => 'Submitter Email',
                    'required'    => true,
                    'placeholder' => 'Submitter Email',
                    'component'   => 'value_text'
                ],
                [
                    'key'         => 'position',
                    'label'       => 'Task Position',
                    'required'    => true,
                    'placeholder' => 'Position',
                    'component'   => 'radio_choice',
                    'options'     => [
                        'bottom' => 'Bottom',
                        'top'    => 'Top'
                    ]
                ],
                [
                    'key'       => 'due_at_days',
                    'label'     => 'Due Date',
                    'tips'      => 'Days after form submission, values less than zero will set due date to null.',
                    'component' => 'number'
                ],
                [
                    'key'            => 'map_files',
                    'label'          => 'Files/Attachments',
                    'tips'           => "This will map all the Files/Images from the form submission to the task.",
                    'component'      => 'checkbox-single',
                    'checkbox_label' => 'Map Files/Attachments to Task',
                ],
                [
                    'key'          => 'conditionals',
                    'label'        => 'Conditional Logics',
                    'tips'         => 'Allow integration conditionally based on your submission values',
                    'component'    => 'conditional_block'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => 'Status',
                    'component'      => 'checkbox-single',
                    'checkbox_label' => 'Enable This feed'
                ]
            ],
            'button_require_list' => false,
            'integration_title'   => $this->title
        ];
        if (function_exists('fluentCrm')) {
            $addToCrmField = [
                'key'            => 'create_crm_contact',
                'label'          => 'FluentCRM',
                'tips'           => "This will create a FluentCRM Contact, if submitter email doesn't exists",
                'component'      => 'checkbox-single',
                'checkbox_label' => 'Create FluentCRM Contact',
            ];
            array_splice($data['fields'], 7, 0, [$addToCrmField]);
        }
        return $data;
    }


    public function getBoardConfigOptions()
    {
        $requestInfo = $this->app->request->get('settings');
        $boardConfig = Arr::get($requestInfo, 'board_config');

        $boardId = Arr::get($boardConfig, 'board_id');

        $data = [
            'board_id'       => $this->getBoards(),
            'stage_id'       => [],
            'board_label_id' => [],
            'member_ids'     => [],
            'priority'       => apply_filters('fluent_boards/task_priorities', (new AdminMenuHandler())->getDefaultPriorities()),
        ];

        if ($boardId) {
            $data['stage_id'] = $this->getStages($boardId);
            $data['board_label_id'] = $this->getBoardLabels($boardId);
            $data['member_ids'] = $this->getBoardMembers($boardId);
        }

        wp_send_json_success([
            'fields_options' => $data
        ], 200);
    }

    private function getBoards()
    {
        $boards = Board::query()->whereNull('archived_at')->get()->toArray();

        $formattedBoards = [];
        foreach ($boards as $board) {
            if (is_array($board)) {
                $formattedBoards[$board['id']] = $board['title'];
            }
        }

        return $formattedBoards;
    }

    private function getStages($boardId)
    {
        $stages = Stage::where('board_id', $boardId)->whereNull('archived_at')->get()->toArray();

        $formattedStages = [];
        foreach ($stages as $stage) {
            if (is_array($stage)) {
                $formattedStages[$stage['id']] = $stage['title'];
            }
        }

        return $formattedStages;
    }


    /**
     * Prepare Fluent Boards forms for feed field.
     *
     * @return array
     */

    /*
     * Submission Broadcast Handler
     */

    public function notify($feed, $formData, $entry, $form)
    {
        if (function_exists('fluentBoards')) {
            $feedData = $feed['processedValues'];
            $taskName = trim(Arr::get($feedData, 'task_title'));
            $boardId = Arr::get($feedData, 'board_config.board_id');
            $stageId = Arr::get($feedData, 'board_config.stage_id');
            $boardLabels = Arr::get($feedData, 'board_config.board_label_id');
            $assignees = Arr::get($feedData, 'board_config.member_ids');
            $priority = Arr::get($feedData, 'board_config.priority');
            $description = Arr::get($feedData, 'description');
            $position = Arr::get($feedData, 'position');
            $crmContactId = Arr::get($feedData, 'board_config.crm_contact_id');
            $submitterName = trim(Arr::get($feedData, 'submitter_name'));
            $submitterEmail = Arr::get($feedData, 'submitter_email');
            $due_at_days = Arr::get($feedData, 'due_at_days');
            $mapFiles = Arr::get($feedData, 'map_files');
            $watcher = Arr::get($feedData, 'watcher_id');
            $crateCRMContact = Arr::get($feedData, 'create_crm_contact');

            if (!$boardId) {
                do_action('fluentform/integration_action_result', $feed, 'failed', 'Board is required');
                return;
            }
            $board = Board::find($boardId)->toArray();
            if (!$board) {
                do_action('fluentform/integration_action_result', $feed, 'failed', "Board doesn't exist");
                return;
            }
            if (!$stageId) {
                do_action('fluentform/integration_action_result', $feed, 'failed', "Stage is required");
                return;
            }
            $stage = Stage::find($stageId)->toArray();
            if (!$stage) {
                do_action('fluentform/integration_action_result', $feed, 'failed', "Stage doesn't exist");
                return;
            }
            if (!$taskName) {
                do_action('fluentform/integration_action_result', $feed, 'failed', "Task title is required");
                return;
            } else if (!is_string($taskName)) {
                do_action('fluentform/integration_action_result', $feed, 'failed', "Invalid Task title is required");
                return;
            }

            $data = [
                'title'          => $taskName,
                'board_id'       => $boardId,
                'stage_id'       => $stageId,
                'priority'       => $priority,
                'description'    => $description,
                'crm_contact_id' => $crmContactId,
                'position'       => $this->getLastPositionOfStageTask($boardId, $stageId),
                'due_at'         => $this->dueDateConvertion($due_at_days, 'day'),
                'source'         => 'FluentForm'

            ];

            $formSubmitter = User::where('user_email', $submitterEmail)->first();
            if (isset($formSubmitter['ID'])) {
                $data['created_by'] = $formSubmitter->ID;
            } else {
                $data['settings']['author'] = [
                    'name'          => sanitize_text_field($submitterName),
                    'email'         => sanitize_email($submitterEmail),
                    'cover'         => [
                        'backgroundColor' => ''
                    ],
                    'subtask_count' => 0
                ];
            }

            if (function_exists('fluentCrm')) {
                $crmConatct = Subscriber::where('email', $submitterEmail)->first();
                if ($crmConatct) {
                    $data['crm_contact_id'] = $crmConatct->id;
                } else {
                    if ($crateCRMContact) {
                        // create crm contact
                    }
                }
            }

            $data = Helper::sanitizeTask($data);
            $task = (new Task())->createTask($data);

            if ($task->id) {
                do_action('fluent_boards/task_added_from_fluent_form', $task);
                if ($position == 'top') {
                    $task->moveToNewPosition(1);
                }

                if ($assignees) {
                    foreach ($assignees as $assignee) {
                        $operation = $task->addOrRemoveAssignee($assignee);
                        $task->load('assignees');
                        $task->updated_at = current_time('mysql');

                        $task->save();

                        if ($operation == 'added') {
                            if ((new NotificationService())->checkIfEmailEnable($assignee, Constant::BOARD_EMAIL_TASK_ASSIGN, $task->board_id)) {
                                (new TaskService())->sendMailAfterTaskModify('add_assignee', $assignee, $task->id);
                            }
                            do_action('fluent_boards/task_assignee_added', $task, $assignee);
                        }
                    }
                }

                if ($boardLabels) {
                    foreach ($boardLabels as $label) {
                        $task->labels()->syncWithoutDetaching([$label => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
                    }
                }

                if ($mapFiles) {
                    $results = [];

                    // Loop through the array to find keys starting with 'image-upload' or 'file-upload'
                    foreach ($formData as $key => $value) {
                        if (preg_match('/^(image-upload|file-upload)/', $key)) {
                            foreach ($value as $file_url) {
                                $results[] = $file_url;
                            }
                        }
                    }


                    foreach ($results as $attachment) {
                        $urlMeta = [
                            'title' => 'Uploaded from FluentForm',
                            'type'  => 'meta_data',
                            'url' => esc_url($attachment)
                        ];
                        $urlData['settings'] = ['meta' => $urlMeta];
                        // creating new attachment object
                        $attachment = new TaskAttachment();
                        $attachment->object_id = $task->id;
                        $attachment->object_type = \FluentBoards\App\Services\Constant::TASK_ATTACHMENT;
                        $attachment->attachment_type = 'url';
                        $attachment->title = 'Uploaded File from Fluent Form';
                        $attachment->full_url = $urlMeta['url'];
                        $attachment->settings = $urlData['settings'] ?? '';
                        $attachment->driver = 'local';
                        $attachment->save();
                    }
                }

                $successMessage = __('Fluent Boards feed has been successfully initialized and data has been pushed.', 'fluent-boards');
                do_action('fluentform/integration_action_result', $feed, 'success', $successMessage);
            } else {
                $error = __('Error when submitting data to Fluent Board server.', 'fluent-boards');
                do_action('fluentform/integration_action_result', $feed, 'failed', $error);
            }
        } else {
            do_action('fluentform/integration_action_result', $feed, 'failed', "Fluent Board doesn't exists.");
        }
    }

    public function getMergeFields($list, $listId, $formId)
    {
        return false;
    }

    private function getLastPositionOfStageTask($board_id, $stage_id)
    {
        $lastPosition = Task::query()
            ->where('board_id', $board_id)
            ->where('parent_id', null)
            ->where('stage_id', $stage_id)
            ->whereNull('archived_at')
            ->orderBy('position', 'desc')
            ->pluck('position')
            ->first();

        return $lastPosition + 1;
    }

    private function getBoardLabels($boardId)
    {
        $labels = Label::where('board_id', $boardId)->whereNull('archived_at')->orderBy('position', 'asc')->get();

        $formattedLabels = [];
        foreach ($labels as $label) {
            $formattedLabels[$label['id']] = $label['title'] ?? $label['slug'];
        }

        return $formattedLabels;
    }

    private function getBoardMembers($boardId)
    {
        $board = Board::findOrFail($boardId);
        $boardUsers = $board->users;
        $formattedBoardUsers = [];
        foreach ($boardUsers as $boardUser) {
            $formattedBoardUsers[$boardUser['ID']] = $boardUser['user_login'] . " (" . $boardUser['user_email'] . ")";
        }
        return $formattedBoardUsers;
    }

    private function dueDateConvertion($due_time, $unit)
    {
        if ($due_time > 0) {
            $currentTime = current_time('mysql');
            $readyString = '+' . $due_time . ' ' . $unit;
            return date('Y-m-d H:i:s', strtotime($readyString, strtotime($currentTime)));
        }
        return null;
    }

}
