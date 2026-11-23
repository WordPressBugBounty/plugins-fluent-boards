<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;

/**
 * Rules for the Yes/No `condition` step.
 *
 * settings = ['match' => 'all'|'any', 'rules' => [['field', 'operator', 'value'], ...]]
 */
class AutomationConditions
{
    /**
     * Field definitions sent to the editor (registry `step_types.condition.condition_fields`).
     *
     * @return array
     */
    public static function fieldDefinitions()
    {
        $inOperators = [
            ['id' => 'in', 'title' => __('is any of', 'fluent-boards')],
            ['id' => 'not_in', 'title' => __('is none of', 'fluent-boards')],
        ];

        return [
            'stage' => [
                'title' => __('Stage', 'fluent-boards'),
                'operators' => $inOperators,
                'value_type' => 'multi-select',
                'option_key' => 'stages',
            ],
            'priority' => [
                'title' => __('Priority', 'fluent-boards'),
                'operators' => $inOperators,
                'value_type' => 'multi-select',
                'options' => [
                    ['id' => 'low', 'title' => __('Low', 'fluent-boards')],
                    ['id' => 'medium', 'title' => __('Medium', 'fluent-boards')],
                    ['id' => 'high', 'title' => __('High', 'fluent-boards')],
                ],
            ],
            'label' => [
                'title' => __('Label', 'fluent-boards'),
                'operators' => [
                    ['id' => 'in', 'title' => __('has any of', 'fluent-boards')],
                    ['id' => 'not_in', 'title' => __('has none of', 'fluent-boards')],
                ],
                'value_type' => 'multi-select',
                'option_key' => 'labels',
            ],
            'assignee' => [
                'title' => __('Assignee', 'fluent-boards'),
                'operators' => [
                    ['id' => 'in', 'title' => __('includes any of', 'fluent-boards')],
                    ['id' => 'not_in', 'title' => __('includes none of', 'fluent-boards')],
                ],
                'value_type' => 'multi-select',
                'option_key' => 'users',
            ],
            'status' => [
                'title' => __('Status', 'fluent-boards'),
                'operators' => [
                    ['id' => 'is', 'title' => __('is', 'fluent-boards')],
                ],
                'value_type' => 'select',
                'options' => [
                    ['id' => 'open', 'title' => __('Open', 'fluent-boards')],
                    ['id' => 'closed', 'title' => __('Completed', 'fluent-boards')],
                ],
            ],
            'due_date' => [
                'title' => __('Due date', 'fluent-boards'),
                'operators' => [
                    ['id' => 'is_set', 'title' => __('is set', 'fluent-boards')],
                    ['id' => 'not_set', 'title' => __('is not set', 'fluent-boards')],
                    ['id' => 'overdue', 'title' => __('is overdue', 'fluent-boards')],
                ],
                'value_type' => 'none',
            ],
        ];
    }

    /**
     * @param mixed $settings
     * @return array
     */
    public static function sanitize($settings)
    {
        $settings = is_array($settings) ? $settings : [];
        $fields = self::fieldDefinitions();
        $rules = [];

        foreach ((array) ($settings['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $field = sanitize_key($rule['field'] ?? '');
            $operator = sanitize_key($rule['operator'] ?? '');

            if (!isset($fields[$field])) {
                $field = '';
            }

            $value = $rule['value'] ?? null;
            if ($field === 'stage' || $field === 'label') {
                $value = self::sanitizeTermSelection($value);
            } elseif (is_array($value)) {
                // Nested arrays are not valid rule values; drop them instead of passing them to sanitize_key().
                $value = array_values(array_filter(array_map(function ($item) {
                    if (!is_scalar($item)) {
                        return '';
                    }
                    return is_numeric($item) ? (int) $item : sanitize_key((string) $item);
                }, $value)));
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            } else {
                $value = is_scalar($value) ? sanitize_key((string) $value) : '';
            }

            $rules[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $value,
            ];

            if (count($rules) >= 20) {
                break;
            }
        }

        return [
            'match' => ($settings['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => $rules,
        ];
    }

    /** Normalize positive term IDs before checking rule completeness or querying integer columns. */
    protected static function sanitizeTermSelection($value)
    {
        $ids = [];
        foreach ((array) $value as $item) {
            if (!is_int($item) && !(is_string($item) && ctype_digit($item))) {
                continue;
            }

            $id = filter_var(ltrim((string) $item, '0'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether a condition has at least one rule and every rule is complete.
     *
     * @param mixed $settings
     * @return bool
     */
    public static function isComplete($settings)
    {
        $rules = self::sanitize($settings)['rules'];

        return $rules && count(array_filter($rules, [self::class, 'isCompleteRule'])) === count($rules);
    }

    /**
     * Whether the task in this run passes the condition. The task is reloaded so changes made by
     * earlier steps of the same run are seen. An unfinished condition fails closed (No path), so
     * Yes-path actions never run without the task being checked.
     *
     * @param AutomationContext $context
     * @param array             $settings
     * @return bool
     */
    public static function passes(AutomationContext $context, array $settings)
    {
        $settings = self::sanitize($settings);

        if (!self::isComplete($settings)) {
            return false;
        }

        $rules = $settings['rules'];

        $task = $context->getTaskId() ? Task::find($context->getTaskId()) : null;
        if (!$task) {
            return false;
        }

        foreach ($rules as $rule) {
            $result = self::rulePasses($task, $rule);

            if ($settings['match'] === 'any' && $result) {
                return true;
            }

            if ($settings['match'] === 'all' && !$result) {
                return false;
            }
        }

        return $settings['match'] === 'all';
    }

    /**
     * @param array $rule
     * @return bool
     */
    public static function isCompleteRule($rule)
    {
        $fields = self::fieldDefinitions();
        $field = $rule['field'] ?? '';

        if (!isset($fields[$field])) {
            return false;
        }

        $operators = array_column($fields[$field]['operators'], 'id');
        if (!in_array($rule['operator'] ?? '', $operators, true)) {
            return false;
        }

        if ($fields[$field]['value_type'] === 'none') {
            return true;
        }

        $value = $rule['value'] ?? null;

        return is_array($value) ? (bool) $value : ($value !== '' && $value !== null);
    }

    protected static function rulePasses(Task $task, array $rule)
    {
        $operator = $rule['operator'];
        $value = $rule['value'];

        switch ($rule['field']) {
            case 'stage':
                $value = Stage::where('board_id', $task->board_id)->whereIn('id', $value)->pluck('id')->all();
                return self::matchIn([(int) $task->stage_id], $value, $operator);

            case 'priority':
                return self::matchIn([sanitize_key((string) $task->priority)], $value, $operator);

            case 'label':
                $value = Label::where('board_id', $task->board_id)->whereIn('id', $value)->pluck('id')->all();
                $labelIds = array_map('intval', $task->labels->pluck('id')->toArray());
                return self::matchIn($labelIds, $value, $operator);

            case 'assignee':
                $userIds = array_map('intval', $task->assignees->pluck('ID')->toArray());
                return self::matchIn($userIds, $value, $operator);

            case 'status':
                return sanitize_key((string) $task->status) === $value;

            case 'due_date':
                if ($operator === 'is_set') {
                    return !empty($task->due_at);
                }
                if ($operator === 'not_set') {
                    return empty($task->due_at);
                }
                return (bool) $task->isOverdue();
        }

        return false;
    }

    /**
     * `in`: the task has at least one of the values. `not_in`: it has none of them.
     */
    protected static function matchIn(array $taskValues, $ruleValues, $operator)
    {
        $ruleValues = (array) $ruleValues;
        $hasAny = (bool) array_intersect($taskValues, $ruleValues);

        return $operator === 'not_in' ? !$hasAny : $hasAny;
    }
}
