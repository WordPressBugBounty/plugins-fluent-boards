<?php

namespace FluentBoards\App\Services\Automations;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Label;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;

class AutomationFieldOptions
{
    public function get($optionKey, array $filters = [])
    {
        $optionKey = sanitize_key($optionKey);

        if ($optionKey === 'boards') {
            return $this->boards();
        }

        if ($optionKey === 'stages') {
            return $this->stages(absint($filters['board_id'] ?? 0));
        }

        if ($optionKey === 'labels') {
            return $this->labels(absint($filters['board_id'] ?? 0));
        }

        if ($optionKey === 'users') {
            $include = array_filter(array_map('absint', (array) ($filters['include'] ?? [])));
            return $this->users(sanitize_text_field($filters['search'] ?? ''), absint($filters['board_id'] ?? 0), $include);
        }

        if ($optionKey === 'task_templates') {
            return $this->taskTemplates(absint($filters['board_id'] ?? 0));
        }

        return apply_filters('fluent_boards/automation_field_options_' . $optionKey, [], $filters);
    }

    protected function boards()
    {
        return Board::whereNull('archived_at')
            ->orderBy('title', 'ASC')
            ->get(['id', 'title'])
            ->map(function ($board) {
                return [
                    'id' => (int) $board->id,
                    'title' => $board->title,
                ];
            })
            ->toArray();
    }

    protected function stages($boardId)
    {
        $boardIds = $this->activeBoardIds($boardId);
        if (!$boardIds) {
            return [];
        }

        $boardTitles = $this->boardTitles($boardId, $boardIds);
        $query = Stage::whereIn('board_id', $boardIds)
            ->whereNull('archived_at')
            ->orderBy('position', 'ASC');

        return $query->get(['id', 'board_id', 'title', 'archived_at'])
            ->map(function ($stage) use ($boardTitles) {
                return [
                    'id' => (int) $stage->id,
                    'board_id' => (int) $stage->board_id,
                    'title' => $stage->title,
                    'archived_at' => $stage->archived_at,
                    'board_title' => $boardTitles[$stage->board_id] ?? '',
                ];
            })
            ->toArray();
    }

    protected function labels($boardId)
    {
        $boardIds = $this->activeBoardIds($boardId);
        if (!$boardIds) {
            return [];
        }

        $boardTitles = $this->boardTitles($boardId, $boardIds);

        $query = Label::whereIn('board_id', $boardIds)
            ->whereNull('archived_at')
            ->orderBy('title', 'ASC');

        return $query->get(['id', 'board_id', 'title', 'slug', 'bg_color', 'color'])
            ->map(function ($label) use ($boardTitles) {
                $title = $this->labelTitle($label);

                return [
                    'id' => (int) $label->id,
                    'board_id' => (int) $label->board_id,
                    'title' => $title,
                    'label' => $title,
                    'text' => $title,
                    'bg_color' => $label->bg_color,
                    'color' => $label->color,
                    'board_title' => $boardTitles[$label->board_id] ?? '',
                ];
            })
            ->toArray();
    }

    /**
     * Users to pick from; scoped to people with access to the board when one is given.
     * $include adds already-picked users, so they keep their names beyond the 50-row page.
     */
    protected function users($search = '', $boardId = 0, array $include = [])
    {
        $query = User::query();

        if ($boardId) {
            $query->whereIn('ID', $this->boardUserIds($boardId));
        }

        // Email addresses are only shown to, and searchable by, users who may list users (matches User::getHidden()).
        $canListUsers = current_user_can('list_users');

        if ($search) {
            $like = '%' . $GLOBALS['wpdb']->esc_like($search) . '%';
            $query->where(function ($q) use ($like, $canListUsers) {
                $q->where('display_name', 'LIKE', $like);
                if ($canListUsers) {
                    $q->orWhere('user_email', 'LIKE', $like);
                }
            });
        }

        $users = $query->limit(50)->get();
        $missingIds = array_diff($include, $users->pluck('ID')->map('intval')->toArray());
        if ($missingIds) {
            // Same board scope as the list, so include can't reveal people outside the board.
            $missingQuery = User::whereIn('ID', $missingIds);
            if ($boardId) {
                $missingQuery->whereIn('ID', $this->boardUserIds($boardId));
            }
            $users = $users->merge($missingQuery->get());
        }

        return $users
            ->map(function ($user) use ($canListUsers) {
                $option = [
                    'id' => (int) $user->ID,
                    'title' => $user->display_name,
                    'name' => $user->display_name,
                ];

                if ($canListUsers) {
                    $option['title'] = $user->display_name . ' (' . $user->user_email . ')';
                    $option['email'] = $user->user_email;
                }

                return $option;
            })
            ->toArray();
    }

    /** Board members, Fluent Boards admins and WP admins: everyone who can be assigned on the board. */
    protected function boardUserIds($boardId)
    {
        $memberIds = Relation::where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->pluck('foreign_id')
            ->toArray();

        $adminIds = Meta::where('object_type', Constant::FLUENT_BOARD_ADMIN)
            ->pluck('object_id')
            ->toArray();

        $wpAdminIds = get_users(['capability' => 'manage_options', 'fields' => 'ID']);

        $ids = array_values(array_unique(array_filter(array_map('absint', array_merge($memberIds, $adminIds, $wpAdminIds)))));

        return $ids ?: [0];
    }

    protected function taskTemplates($boardId)
    {
        $templateTaskIds = TaskMeta::where('key', Constant::IS_TASK_TEMPLATE)
            ->where('value', 'yes')
            ->pluck('task_id');

        if ($templateTaskIds && method_exists($templateTaskIds, 'toArray')) {
            $templateTaskIds = $templateTaskIds->toArray();
        }

        $templateTaskIds = array_filter(array_map('absint', (array) $templateTaskIds));

        if (!$templateTaskIds) {
            return [];
        }

        $query = Task::whereIn('id', $templateTaskIds)
            ->whereNull('archived_at')
            ->orderBy('title', 'ASC');

        if ($boardId) {
            $query->where('board_id', $boardId);
        }

        $boardTitles = $boardId ? [] : Board::pluck('title', 'id')->toArray();

        return $query->get(['id', 'board_id', 'title'])
            ->map(function ($task) use ($boardTitles) {
                return [
                    'id' => (int) $task->id,
                    'board_id' => (int) $task->board_id,
                    'title' => $task->title,
                    'board_title' => $boardTitles[$task->board_id] ?? '',
                ];
            })
            ->toArray();
    }

    /** Board names tell same-named stages, labels and templates apart when no board is chosen. */
    protected function boardTitles($boardId, array $boardIds)
    {
        return $boardId ? [] : Board::whereIn('id', $boardIds)->pluck('title', 'id')->toArray();
    }

    protected function activeBoardIds($boardId = 0)
    {
        $query = Board::whereNull('archived_at');

        if ($boardId) {
            $query->where('id', absint($boardId));
        }

        $ids = $query->pluck('id');
        if ($ids && method_exists($ids, 'toArray')) {
            $ids = $ids->toArray();
        }

        return array_values(array_filter(array_map('absint', (array) $ids)));
    }

    protected function labelTitle($label)
    {
        if (!empty($label->title)) {
            return $label->title;
        }

        if (!empty($label->slug)) {
            return ucwords(str_replace(['_', '-'], ' ', $label->slug));
        }

        return __('Untitled label', 'fluent-boards') . ' #' . (int) $label->id;
    }
}
