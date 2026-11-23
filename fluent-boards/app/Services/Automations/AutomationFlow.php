<?php

namespace FluentBoards\App\Services\Automations;

/**
 * Helpers for the automation step tree.
 *
 * Steps are a list. Branching steps hold more lists in `branches`, keyed by path:
 * - `condition` (Yes / No): `branches.yes`, `branches.no`.
 * - `router` (multi path): one list per path in `settings.paths` (keyed by the path id),
 *   plus `branches.else` for tasks that match no path.
 * After a branch the run continues with the steps after the branching step.
 *
 * A position in the tree is a path such as [2, 'yes', 0]: step 0 of the Yes branch of
 * top-level step 2. Paths are stored on waiting runs as "2.yes.0" or "1.path_ab.0".
 */
class AutomationFlow
{
    const STEP_TYPES = ['action', 'delay', 'condition', 'router'];

    const BRANCHES = ['yes', 'no'];

    /** Branch key of a router's "everyone else" path. */
    const ROUTER_ELSE = 'else';

    const MAX_ROUTER_PATHS = 10;

    const MAX_DEPTH = 5;

    const MAX_STEPS = 100;

    /** Whether a step holds branches (Yes / No condition or multi path). */
    public static function isBranching($step)
    {
        return is_array($step) && in_array($step['type'] ?? '', ['condition', 'router'], true);
    }

    /**
     * The branch keys of a branching step, in display and run order.
     *
     * @param array $step
     * @return string[]
     */
    public static function branchKeys($step)
    {
        if (!is_array($step)) {
            return [];
        }

        if (($step['type'] ?? '') === 'condition') {
            return self::BRANCHES;
        }

        if (($step['type'] ?? '') === 'router') {
            $keys = [];
            foreach ((array) ($step['settings']['paths'] ?? []) as $path) {
                $id = is_array($path) ? sanitize_key($path['id'] ?? '') : '';
                if ($id) {
                    $keys[] = $id;
                }
            }
            $keys[] = self::ROUTER_ELSE;

            return $keys;
        }

        return [];
    }

    /**
     * Every step in the tree, parents before children, in run order (Yes before No).
     *
     * @param array $steps
     * @return array
     */
    public static function flatten($steps)
    {
        $flat = [];

        foreach ((array) $steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $flat[] = $step;

            if (self::isBranching($step)) {
                foreach (self::branchKeys($step) as $branch) {
                    foreach (self::flatten($step['branches'][$branch] ?? []) as $child) {
                        $flat[] = $child;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Sanitize a step tree. Unknown step types are dropped; nesting and size are capped.
     *
     * @param mixed    $steps
     * @param callable $sanitizeSettings Sanitizes one step's settings array.
     * @param int      $depth
     * @param int      $count Running total of kept steps, shared across the tree.
     * @return array
     */
    public static function sanitizeSteps($steps, callable $sanitizeSettings, $depth = 1, &$count = 0)
    {
        if (!is_array($steps) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $sanitized = [];

        foreach (array_values($steps) as $index => $step) {
            if (!is_array($step) || $count >= self::MAX_STEPS) {
                continue;
            }

            $type = sanitize_key($step['type'] ?? '');
            if (!in_array($type, self::STEP_TYPES, true)) {
                continue;
            }

            $count++;

            $item = [
                'id' => sanitize_key($step['id'] ?? '') ?: 'step_' . $depth . '_' . ($index + 1),
                'type' => $type,
                'key' => $type === 'action' ? sanitize_key($step['key'] ?? '') : '',
                'settings' => call_user_func($sanitizeSettings, $step['settings'] ?? []),
            ];

            if ($type === 'condition' || $type === 'router') {
                $item['settings'] = $type === 'condition'
                    ? AutomationConditions::sanitize($step['settings'] ?? [])
                    : self::sanitizeRouterSettings($step['settings'] ?? []);
                $item['branches'] = [];
                foreach (self::branchKeys($item) as $branch) {
                    $item['branches'][$branch] = self::sanitizeSteps(
                        $step['branches'][$branch] ?? [],
                        $sanitizeSettings,
                        $depth + 1,
                        $count
                    );
                }
            }

            $sanitized[] = $item;
        }

        return $sanitized;
    }

    /**
     * Router settings: up to MAX_ROUTER_PATHS paths, each with a unique id, a name and a rule set.
     * Path ids are branch keys, so they must be keys that are not the else key or a step index.
     *
     * @param mixed $settings
     * @return array
     */
    public static function sanitizeRouterSettings($settings)
    {
        $settings = is_array($settings) ? $settings : [];
        $paths = [];
        $seen = [self::ROUTER_ELSE => true];

        foreach ((array) ($settings['paths'] ?? []) as $index => $path) {
            if (!is_array($path) || count($paths) >= self::MAX_ROUTER_PATHS) {
                continue;
            }

            $id = sanitize_key($path['id'] ?? '');
            if (!$id || isset($seen[$id]) || ctype_digit($id)) {
                $id = 'path_' . ($index + 1);
                while (isset($seen[$id])) {
                    $id .= '_x';
                }
            }
            $seen[$id] = true;

            $condition = AutomationConditions::sanitize($path['settings'] ?? []);
            $name = sanitize_text_field($path['name'] ?? '');

            $paths[] = [
                'id' => $id,
                /* translators: %d is the path's position in a multi path step */
                'name' => mb_substr($name !== '' ? $name : sprintf(__('Path %d', 'fluent-boards'), count($paths) + 1), 0, 80),
                'settings' => $condition,
            ];
        }

        return ['paths' => $paths];
    }

    /**
     * Whether a step tree is larger or deeper than sanitizeSteps() keeps, so saving it would drop steps.
     *
     * @param mixed $steps
     * @param int   $depth
     * @param int   $count Running total of steps, shared across the tree.
     * @return bool
     */
    public static function exceedsLimits($steps, $depth = 1, &$count = 0)
    {
        if (!is_array($steps) || !$steps) {
            return false;
        }

        if ($depth > self::MAX_DEPTH) {
            return true;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            if (++$count > self::MAX_STEPS) {
                return true;
            }

            if (self::isBranching($step)) {
                foreach (self::branchKeys($step) as $branch) {
                    if (self::exceedsLimits($step['branches'][$branch] ?? [], $depth + 1, $count)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Turn "2.yes.0" into [2, 'yes', 0]. Returns null for a malformed path.
     *
     * @param string $path
     * @return array|null
     */
    public static function parsePath($path)
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        $parts = explode('.', $path);
        $parsed = [];

        foreach ($parts as $position => $part) {
            if ($position % 2 === 0) {
                if (!ctype_digit($part)) {
                    return null;
                }
                $parsed[] = (int) $part;
            } else {
                // A branch key: yes / no, a router path id, or else. Never a bare number.
                if ($part === '' || $part !== sanitize_key($part) || ctype_digit($part)) {
                    return null;
                }
                $parsed[] = $part;
            }
        }

        // A path always ends on a step index.
        return count($parsed) % 2 === 1 ? $parsed : null;
    }

    /**
     * @param array $path
     * @return string
     */
    public static function pathToString(array $path)
    {
        return implode('.', $path);
    }
}
