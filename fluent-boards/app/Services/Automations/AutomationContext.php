<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Models\Task;

class AutomationContext
{
    protected $triggerName;

    protected $originalArgs;

    protected $data;

    public function __construct($triggerName, array $originalArgs = [], array $data = [])
    {
        $this->triggerName = sanitize_key($triggerName);
        $this->originalArgs = $originalArgs;
        // Extension triggers can supply just the task. Snapshot its board for event matching.
        if (!isset($data['board_id']) && isset($data['task']->board_id)) {
            $data['board_id'] = (int) $data['task']->board_id;
        }
        $this->data = $data;
    }

    public static function fromTask($triggerName, Task $task, array $originalArgs = [], array $data = [])
    {
        $data['task'] = $task;
        $data['task_id'] = (int) $task->id;
        $data['board_id'] = (int) $task->board_id;
        $data['stage_id'] = (int) $task->stage_id;
        $data['priority'] = $task->priority;
        $data['status'] = $task->status;
        $data['actor_id'] = absint($data['actor_id'] ?? 0) ?: get_current_user_id();

        return new static($triggerName, $originalArgs, $data);
    }

    public static function fromPayload(array $payload)
    {
        $task = null;
        if (!empty($payload['task_id'])) {
            $task = Task::find(absint($payload['task_id']));
        }

        if ($task) {
            return static::fromTask($payload['trigger_name'] ?? '', $task, [], $payload);
        }

        return new static($payload['trigger_name'] ?? '', [], $payload);
    }

    public function getTriggerName()
    {
        return $this->triggerName;
    }

    public function getTask()
    {
        return $this->data['task'] ?? null;
    }

    public function getTaskId()
    {
        return (int) ($this->data['task_id'] ?? 0);
    }

    /** The task's current board, so steps after a cross-board move see where it went. */
    public function getBoardId()
    {
        $task = $this->getTask();

        return (int) ($task ? $task->board_id : ($this->data['board_id'] ?? 0));
    }

    public function get($key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function all()
    {
        return $this->data;
    }

    /**
     * Smart codes the editor offers under text fields; replaceSmartCodes() fills them in.
     */
    public static function smartCodeOptions()
    {
        return [
            ['code' => '{{task.title}}', 'title' => __('Task title', 'fluent-boards')],
            ['code' => '{{stage.title}}', 'title' => __('Stage', 'fluent-boards')],
            ['code' => '{{board.title}}', 'title' => __('Board', 'fluent-boards')],
            ['code' => '{{task.priority}}', 'title' => __('Priority', 'fluent-boards')],
            ['code' => '{{task.status}}', 'title' => __('Status', 'fluent-boards')],
            ['code' => '{{task.due_at}}', 'title' => __('Due date', 'fluent-boards')],
            ['code' => '{{task.id}}', 'title' => __('Task ID', 'fluent-boards')],
        ];
    }

    /**
     * Replace `{{task.title}}`-style smart codes with values from this run.
     * Extend with the `fluent_boards/automation_smart_codes` filter.
     *
     * Values go in raw. Use this for plain-text output that the caller sanitizes
     * afterwards (titles, subjects). For HTML output use replaceSmartCodesInHtml().
     */
    public function replaceSmartCodes($text)
    {
        return $this->fillSmartCodes($text, false);
    }

    /**
     * Replace smart codes in an HTML template (comment body, email body).
     * Each value is HTML-escaped, quotes too, so a value stays text and cannot
     * add tags or break out of an attribute.
     */
    public function replaceSmartCodesInHtml($text)
    {
        return $this->fillSmartCodes($text, true);
    }

    protected function fillSmartCodes($text, $escapeHtml)
    {
        $text = (string) $text;
        if (strpos($text, '{{') === false) {
            return $text;
        }

        $codes = [];
        $task = $this->getTask();
        if ($task) {
            $codes = [
                '{{task.id}}'       => (string) $task->id,
                '{{task.title}}'    => (string) $task->title,
                '{{task.status}}'   => (string) $task->status,
                '{{task.priority}}' => (string) $task->priority,
                '{{task.due_at}}'   => (string) $task->due_at,
                '{{board.id}}'      => (string) $task->board_id,
                '{{board.title}}'   => $task->board ? (string) $task->board->title : '',
                '{{stage.title}}'   => $task->stage ? (string) $task->stage->title : '',
            ];
        }

        $codes = (array) apply_filters('fluent_boards/automation_smart_codes', $codes, $this);

        if ($escapeHtml) {
            $codes = array_map(function ($value) {
                return is_scalar($value) ? esc_html((string) $value) : '';
            }, $codes);
        }

        return strtr($text, $codes);
    }

    public function toPayload()
    {
        $payload = $this->data;
        unset($payload['task']);

        $payload['trigger_name'] = $this->triggerName;
        $payload['task_id'] = $this->getTaskId();
        $payload['board_id'] = $this->getBoardId();

        return $payload;
    }
}
