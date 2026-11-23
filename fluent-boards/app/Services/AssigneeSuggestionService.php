<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Meta;

/**
 * Personal assignee suggestions for the task assignee picker.
 *
 * Each user gets one meta row per board that remembers who they assigned, how often and when.
 * The picker shows the best scoring people first, where the score blends frequency and recency.
 *
 * Only assignments a person made in the app UI are recorded (see TaskController). Default stage
 * assignees, automations, recurring tasks, MCP tools and the public API never call this class,
 * so they cannot skew a user's suggestions.
 */
class AssigneeSuggestionService
{
    /**
     * Record the difference between a task's assignees before and after a UI change.
     *
     * @param int   $boardId
     * @param int   $taskId
     * @param array $beforeIds Assignee user ids before the request.
     * @param array $afterIds  Assignee user ids after the request.
     * @return void
     */
    public function recordChange($boardId, $taskId, $beforeIds, $afterIds)
    {
        $beforeIds = $this->cleanIds($beforeIds);
        $afterIds = $this->cleanIds($afterIds);

        $this->recordUnassigned($boardId, $taskId, array_diff($beforeIds, $afterIds));
        $this->recordAssigned($boardId, $taskId, array_diff($afterIds, $beforeIds));
    }

    /**
     * Remember that the current user assigned these people on a board.
     *
     * @param int   $boardId
     * @param int   $taskId      0 for a bulk action, which counts once however many tasks it touched.
     * @param array $assigneeIds
     * @return void
     */
    public function recordAssigned($boardId, $taskId, $assigneeIds)
    {
        $userId = get_current_user_id();
        $boardId = absint($boardId);
        $taskId = absint($taskId);
        $assigneeIds = $this->cleanIds($assigneeIds);

        if (!$userId || !$boardId || !$assigneeIds) {
            return;
        }

        $history = $this->getHistory($userId, $boardId);
        $now = time();

        foreach ($assigneeIds as $assigneeId) {
            $entry = $this->normalizeEntry($history[$assigneeId] ?? []);

            // Assigning the same person to the same task again is one choice, not two.
            $isRepeatOnSameTask = $taskId && $entry['count'] && $entry['last_task_id'] === $taskId;

            if (!$isRepeatOnSameTask) {
                $entry['count']++;
                $entry['prev_assigned_at'] = $entry['last_assigned_at'];
            }

            $entry['last_assigned_at'] = $now;
            $entry['last_task_id'] = $taskId;
            $history[$assigneeId] = $entry;
        }

        $this->saveHistory($userId, $boardId, $history);
    }

    /**
     * Undo the current user's latest assignment of these people when they unassign it again.
     *
     * Only an unassign on the task the user last assigned that person to counts as an undo; removing
     * somebody from an older task says nothing about who the user likes to pick.
     *
     * @param int   $boardId
     * @param int   $taskId
     * @param array $assigneeIds
     * @return void
     */
    public function recordUnassigned($boardId, $taskId, $assigneeIds)
    {
        $userId = get_current_user_id();
        $boardId = absint($boardId);
        $taskId = absint($taskId);
        $assigneeIds = $this->cleanIds($assigneeIds);

        if (!$userId || !$boardId || !$taskId || !$assigneeIds) {
            return;
        }

        $history = $this->getHistory($userId, $boardId);
        $changed = false;

        foreach ($assigneeIds as $assigneeId) {
            if (!isset($history[$assigneeId])) {
                continue;
            }

            $entry = $this->normalizeEntry($history[$assigneeId]);
            if ($entry['last_task_id'] !== $taskId) {
                continue;
            }

            $entry['count']--;
            $entry['last_assigned_at'] = $entry['prev_assigned_at'];
            $entry['prev_assigned_at'] = 0;
            $entry['last_task_id'] = 0;
            $changed = true;

            if ($entry['count'] < 1) {
                unset($history[$assigneeId]);
            } else {
                $history[$assigneeId] = $entry;
            }
        }

        if ($changed) {
            $this->saveHistory($userId, $boardId, $history);
        }
    }

    /**
     * Ranked user ids the current user is most likely to assign on this board, best first.
     *
     * People who lost board access are skipped. A user with no history gets an empty list.
     *
     * @param int $boardId
     * @return int[]
     */
    public function getSuggestedIds($boardId)
    {
        $userId = get_current_user_id();
        $boardId = absint($boardId);

        if (!$userId || !$boardId) {
            return [];
        }

        $rankedIds = array_keys($this->sortByScore($this->getHistory($userId, $boardId)));
        if (!$rankedIds) {
            return [];
        }

        // getUserIdsWithBoardAccess keeps the order of the ids it is given.
        $allowedIds = PermissionManager::getUserIdsWithBoardAccess($boardId, $rankedIds);

        return array_slice($allowedIds, 0, Constant::ASSIGNEE_SUGGESTION_LIMIT);
    }

    /**
     * Remove every user's suggestion history for a board that is being deleted.
     *
     * @param int $boardId
     * @return void
     */
    public function deleteForBoard($boardId)
    {
        $boardId = absint($boardId);
        if (!$boardId) {
            return;
        }

        Meta::where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', $this->metaKey($boardId))
            ->delete();
    }

    /**
     * Blend of how often and how recently somebody was assigned, from 0 to 1.
     *
     * Frequency is logarithmic and saturates at 10 assignments, so one very old habit cannot
     * outrank everybody forever. Recency halves after 14 days.
     *
     * @param array $entry
     * @return float
     */
    private function score($entry)
    {
        $frequency = min(log($entry['count'] + 1) / log(11), 1);

        $days = 365;
        if ($entry['last_assigned_at']) {
            $days = max(0, time() - $entry['last_assigned_at']) / DAY_IN_SECONDS;
        }
        $recency = 1 / (1 + $days / 14);

        return 0.55 * $frequency + 0.45 * $recency;
    }

    /**
     * @param array $history assigneeId => entry
     * @return array Same map, best score first. Ties go to the more recent, then the more frequent.
     */
    private function sortByScore($history)
    {
        $scores = [];
        foreach ($history as $assigneeId => $entry) {
            $history[$assigneeId] = $this->normalizeEntry($entry);
            $scores[$assigneeId] = $this->score($history[$assigneeId]);
        }

        uksort($history, function ($a, $b) use ($history, $scores) {
            if (abs($scores[$a] - $scores[$b]) > 0.00001) {
                return $scores[$a] < $scores[$b] ? 1 : -1;
            }

            if ($history[$a]['last_assigned_at'] !== $history[$b]['last_assigned_at']) {
                return $history[$a]['last_assigned_at'] < $history[$b]['last_assigned_at'] ? 1 : -1;
            }

            return $history[$b]['count'] <=> $history[$a]['count'];
        });

        return $history;
    }

    private function normalizeEntry($entry)
    {
        $entry = is_array($entry) ? $entry : [];

        return [
            'count'            => max(0, (int) ($entry['count'] ?? 0)),
            'last_assigned_at' => max(0, (int) ($entry['last_assigned_at'] ?? 0)),
            'prev_assigned_at' => max(0, (int) ($entry['prev_assigned_at'] ?? 0)),
            'last_task_id'     => max(0, (int) ($entry['last_task_id'] ?? 0)),
        ];
    }

    private function cleanIds($ids)
    {
        return array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }

    private function metaKey($boardId)
    {
        return Constant::USER_ASSIGNEE_SUGGESTIONS_PREFIX . absint($boardId);
    }

    private function findMeta($userId, $boardId)
    {
        return Meta::where('object_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_USER)
            ->where('key', $this->metaKey($boardId))
            ->first();
    }

    private function getHistory($userId, $boardId)
    {
        $meta = $this->findMeta($userId, $boardId);
        $history = $meta ? $meta->value : [];

        return is_array($history) ? $history : [];
    }

    private function saveHistory($userId, $boardId, $history)
    {
        // Keep the row small: beyond the cap, the weakest suggestions are forgotten first.
        if (count($history) > Constant::ASSIGNEE_SUGGESTION_HISTORY_LIMIT) {
            $history = array_slice($this->sortByScore($history), 0, Constant::ASSIGNEE_SUGGESTION_HISTORY_LIMIT, true);
        }

        $meta = $this->findMeta($userId, $boardId);

        if (!$history) {
            if ($meta) {
                $meta->delete();
            }
            return;
        }

        if (!$meta) {
            $meta = new Meta();
            $meta->object_id = $userId;
            $meta->object_type = Constant::OBJECT_TYPE_USER;
            $meta->key = $this->metaKey($boardId);
        }

        $meta->value = $history;
        $meta->save();
    }
}
