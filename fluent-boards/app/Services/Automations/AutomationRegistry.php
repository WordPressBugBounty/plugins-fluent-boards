<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Services\Automations\Actions\AddAssigneeAction;
use FluentBoards\App\Services\Automations\Actions\AddCommentAction;
use FluentBoards\App\Services\Automations\Actions\ApplyLabelAction;
use FluentBoards\App\Services\Automations\Actions\CreateTaskFromTemplateAction;
use FluentBoards\App\Services\Automations\Actions\DuplicateTaskAction;
use FluentBoards\App\Services\Automations\Actions\MoveTaskAction;
use FluentBoards\App\Services\Automations\Actions\SendEmailAction;
use FluentBoards\App\Services\Automations\Triggers\AssigneeAddedTrigger;
use FluentBoards\App\Services\Automations\Triggers\CommentAddedTrigger;
use FluentBoards\App\Services\Automations\Triggers\DueDateChangedTrigger;
use FluentBoards\App\Services\Automations\Triggers\LabelAddedTrigger;
use FluentBoards\App\Services\Automations\Triggers\SubtaskAddedTrigger;
use FluentBoards\App\Services\Automations\Triggers\TaskCompletedTrigger;
use FluentBoards\App\Services\Automations\Triggers\TaskCreatedTrigger;
use FluentBoards\App\Services\Automations\Triggers\TaskMovedTrigger;

/**
 * Holds every automation trigger and action, built-in or third party.
 *
 * Extensions register from the `fluent_boards/automation_register` action, which fires
 * once on `init` so plugins loaded after Fluent Boards can still take part:
 *
 *     add_action('fluent_boards/automation_register', function ($registry) {
 *         $registry->registerTrigger(new MyTrigger());
 *         $registry->registerAction(new MyAction());
 *     });
 */
class AutomationRegistry
{
    protected static $instance;

    protected $booted = false;

    /** Whether trigger hooks are attached. Stays false until the automation tables exist. */
    protected $listening = false;

    protected $triggers = [];

    protected $actions = [];

    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static();
            static::$instance->registerBuiltIns();
        }

        return static::$instance;
    }

    /**
     * Collects extensions and starts listening to trigger hooks. Hooked on `init`.
     *
     * @param bool $listen Attach trigger hooks; false only collects definitions.
     */
    public function boot($listen = true)
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        do_action('fluent_boards/automation_register', $this);

        if ($listen) {
            $this->listen();
        }
    }

    /**
     * Attach every trigger hook. Called from boot() once the schema is ready, or later in the same
     * request after the schema is installed (e.g. a cron resume that upgraded it).
     */
    public function listen()
    {
        if ($this->listening) {
            return;
        }

        $this->listening = true;

        foreach ($this->triggers as $trigger) {
            $trigger->registerHook();
        }
    }

    public function registerTrigger(BaseTrigger $trigger)
    {
        $name = $trigger->getName();
        if (!$name) {
            return;
        }

        // Registered while listening: swap hook listeners so only the latest definition runs.
        // Before that (including inside the register action) listen() attaches it later.
        if ($this->listening) {
            if (isset($this->triggers[$name])) {
                $this->triggers[$name]->unregisterHook();
            }
            $trigger->registerHook();
        }

        $this->triggers[$name] = $trigger;
    }

    public function registerAction(BaseAction $action)
    {
        $name = $action->getName();
        if ($name) {
            $this->actions[$name] = $action;
        }
    }

    public function getTrigger($name)
    {
        $name = sanitize_key($name);

        return $this->triggers[$name] ?? null;
    }

    public function getAction($name)
    {
        $name = sanitize_key($name);

        return $this->actions[$name] ?? null;
    }

    public function toArray()
    {
        $triggers = [];
        foreach ($this->triggers as $name => $trigger) {
            $triggers[$name] = array_merge($trigger->getTrigger(), [
                'name' => $name,
                'settings_defaults' => $trigger->getSettingsDefaults(),
                'fields' => $trigger->getSettingsFields(),
            ]);
        }

        $actions = [];
        foreach ($this->actions as $name => $action) {
            $actions[$name] = array_merge($action->getBlock(), [
                'name' => $name,
                'settings_defaults' => $action->getSettingsDefaults(),
                'fields' => $action->getFields(),
            ]);
        }

        return [
            'triggers' => $triggers,
            'actions' => $actions,
            'step_types' => $this->getStepTypes(),
            'smart_codes' => AutomationContext::smartCodeOptions(),
        ];
    }

    protected function registerBuiltIns()
    {
        $this->registerTrigger(new TaskCreatedTrigger());
        $this->registerTrigger(new TaskMovedTrigger());
        $this->registerTrigger(new TaskCompletedTrigger());
        $this->registerTrigger(new DueDateChangedTrigger());
        $this->registerTrigger(new AssigneeAddedTrigger());
        $this->registerTrigger(new LabelAddedTrigger());
        $this->registerTrigger(new SubtaskAddedTrigger());
        $this->registerTrigger(new CommentAddedTrigger());

        $this->registerAction(new MoveTaskAction());
        $this->registerAction(new AddAssigneeAction());
        $this->registerAction(new ApplyLabelAction());
        $this->registerAction(new AddCommentAction());
        $this->registerAction(new SendEmailAction());
        $this->registerAction(new CreateTaskFromTemplateAction());
        $this->registerAction(new DuplicateTaskAction());
    }

    protected function getStepTypes()
    {
        return [
            'delay' => [
                'name' => 'delay',
                'title' => __('Delay', 'fluent-boards'),
                'description' => __('Wait before continuing to the next action.', 'fluent-boards'),
                'fields' => [
                    [
                        'key' => 'delay_amount',
                        'type' => 'number',
                        'label' => __('Wait amount', 'fluent-boards'),
                        'min' => 1,
                        // Shortest wait when the unit is minutes.
                        'min_minutes' => AutomationProcessor::MIN_DELAY_SECONDS / MINUTE_IN_SECONDS,
                    ],
                    [
                        'key' => 'delay_unit',
                        'type' => 'select',
                        'label' => __('Wait unit', 'fluent-boards'),
                        'options' => [
                            ['id' => 'minutes', 'title' => __('Minutes', 'fluent-boards')],
                            ['id' => 'hours', 'title' => __('Hours', 'fluent-boards')],
                            ['id' => 'days', 'title' => __('Days', 'fluent-boards')],
                            ['id' => 'weeks', 'title' => __('Weeks', 'fluent-boards')],
                        ],
                    ],
                ],
            ],
            'condition' => [
                'name' => 'condition',
                'title' => __('Yes / No condition', 'fluent-boards'),
                'description' => __('Check the task, then continue down the Yes or the No path.', 'fluent-boards'),
                'fields' => [],
                'condition_fields' => AutomationConditions::fieldDefinitions(),
            ],
            'router' => [
                'name' => 'router',
                'title' => __('Multi path', 'fluent-boards'),
                'description' => __('Send the task down the first path whose rules match, or down Everyone else.', 'fluent-boards'),
                'fields' => [],
                'max_paths' => AutomationFlow::MAX_ROUTER_PATHS,
                'condition_fields' => AutomationConditions::fieldDefinitions(),
            ],
        ];
    }
}
