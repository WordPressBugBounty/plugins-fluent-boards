<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Hooks\Handlers\AutomationHandler;
use FluentBoards\App\Models\Stage;

/**
 * Base for automation triggers. A trigger listens to one WordPress hook, turns the hook
 * arguments into an AutomationContext and decides whether a saved automation's settings match.
 */
abstract class BaseTrigger
{
    protected $triggerName = '';

    protected $hookName = '';

    protected $priority = 20;

    protected $actionArgNum = 1;

    public function getName()
    {
        return sanitize_key($this->triggerName);
    }

    public function registerHook()
    {
        if (!$this->hookName || !$this->triggerName) {
            return;
        }

        add_action($this->hookName, [$this, 'handleHook'], $this->priority, $this->actionArgNum);
    }

    public function unregisterHook()
    {
        if ($this->hookName) {
            remove_action($this->hookName, [$this, 'handleHook'], $this->priority);
        }
    }

    /**
     * Hook callback. Automations run inside the request that fired the hook, so a broken
     * trigger or action must never break that request (e.g. a user moving a task).
     */
    public function handleHook()
    {
        if (!in_array($this->getName(), AutomationHandler::activeTriggerNames(), true)) {
            return;
        }

        try {
            $context = $this->createContext(func_get_args());

            if ($context instanceof AutomationContext) {
                (new AutomationProcessor(AutomationRegistry::getInstance()))->handleTrigger($this, $context);
            }
        } catch (\Throwable $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- surfaced for site debugging only
            error_log('FluentBoards automation trigger "' . $this->getName() . '" failed: ' . $e->getMessage());
        }
    }

    public function getSettingsDefaults()
    {
        return [
            'board_id' => 0,
        ];
    }

    public function getSettingsFields()
    {
        return [
            [
                'key' => 'board_id',
                'type' => 'select',
                'option_key' => 'boards',
                'label' => __('Board', 'fluent-boards'),
                'placeholder' => __('Any board', 'fluent-boards'),
            ],
        ];
    }

    public function matches(AutomationContext $context, array $settings)
    {
        $boardId = absint($settings['board_id'] ?? 0);

        return !$boardId || (int) $context->get('board_id') === $boardId;
    }

    protected function matchesStage($stageId, $selectedIds, $boardId)
    {
        $ids = array_filter(array_map('absint', (array) $selectedIds));

        return !$ids || (in_array((int) $stageId, $ids, true)
            && Stage::where('board_id', $boardId)->where('id', $stageId)->exists());
    }

    abstract public function getTrigger();

    abstract public function createContext(array $originalArgs);
}
