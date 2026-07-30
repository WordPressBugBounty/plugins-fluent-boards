<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Database\Orm\Builder;

class DeepIntegration
{
    private const USER_TEMPLATE_OPTION_LIMIT = 50;

    public function init()
    {
        add_filter('fluentcrm_ajax_options_boards', [$this, 'getBoards'], 10, 3);
        add_filter('fluentcrm_ajax_options_task_templates', [$this, 'getTaskTemplates'], 10, 3);
        add_filter('fluentcrm_ajax_options_board_default_templates', [$this, 'getDefaultBoardTemplates'], 10, 3);
        add_filter('fluentcrm_ajax_options_board_user_templates', [$this, 'getUserBoardTemplates'], 10, 3);
        add_filter('fluentcrm_ajax_options_board_members', [$this, 'getBoardMembers'], 10, 3);
    }

    public function getBoards($records, $search, $includeIds)
    {
        $query = Board::select(['id', 'title']);

        if (!empty($search)) {
            $query->where('title', 'like', "%$search%");
        }

        $boards = $query->orderBy('title', 'ASC')->get();

        return $this->getFormattedBoards($boards);
    }

    public function getFormattedBoards($boards)
    {
        $formattedBoards = [];
        foreach ($boards as $board) {
            $formattedBoards[] = [
                'id'    => strval($board->id),
                'title' => $board->title
            ];
        }
        return $formattedBoards;
    }

    public function getTaskTemplates($records, $search, $includeIds)
    {
        if (!defined('FLUENT_BOARDS_PRO')) {
            return [];
        }

        $userId = get_current_user_id();
        $currentUser = User::find($userId);

        $relatedBoardsQuery = Board::query();

        if (!PermissionManager::isAdmin($userId)) {
            $relatedBoardsQuery->whereIn('id', $currentUser->whichBoards->pluck('id'));
        }

        $templateTaskIds = TaskMeta::where('key', 'is_template')->where('value', 'yes')->pluck('task_id');
        $query = Task::whereIn('id', $templateTaskIds)
            ->where('archived_at', null)
            ->whereIn('board_id', $relatedBoardsQuery->pluck('id'))
            ->with('assignees', 'labels');


        if (!empty($search)) {
            $query->where('title', 'like', "%$search%");
        }

        $templateTasks = $query->orderBy('title', 'ASC')->get();

        $formattedTemplateTasks = [];
        foreach ($templateTasks as $task) {
            $formattedTemplateTasks[] = [
                'id'    => strval($task->id),
                'title' => $task->title
            ];
        }
        return $formattedTemplateTasks;
    }

    public function getDefaultBoardTemplates($records, $search, $includeIds)
    {
        // Built-in board templates are coming soon. Keep this selector empty so
        // FluentCRM option lookups do not request the external default-template API.
        return [];
    }

    /**
     * Return bounded user-template options for the FluentCRM selector.
     *
     * @param array  $records
     * @param string $search
     * @param array  $includeIds
     * @return array
     */
    public function getUserBoardTemplates($records, $search, $includeIds)
    {
        if (!defined('FLUENT_BOARDS_PRO')) {
            return [];
        }

        global $wpdb;

        $search = is_scalar($search) ? sanitize_text_field((string) $search) : '';
        $includeIds = array_slice(array_values(array_unique(array_filter(
            array_map('absint', (array) $includeIds)
        ))), 0, self::USER_TEMPLATE_OPTION_LIMIT);

        $query = $this->newUserTemplateOptionsQuery();

        if ($search !== '') {
            $query->where('title', 'like', '%' . $wpdb->esc_like($search) . '%');
        }

        $templates = $query->orderBy('title', 'ASC')
            ->limit(self::USER_TEMPLATE_OPTION_LIMIT)
            ->get();
        $options = $this->formatUserTemplateOptions($templates);

        // Preserve saved selections even when they fall outside the current result page.
        $missingIds = array_diff($includeIds, array_keys($options));
        if ($missingIds) {
            $selectedTemplates = $this->newUserTemplateOptionsQuery()
                ->whereIn('id', $missingIds)
                ->get();
            $options += $this->formatUserTemplateOptions($selectedTemplates);
        }

        $selectedOptions = array_intersect_key($options, array_flip($includeIds));
        $regularOptions = array_diff_key($options, $selectedOptions);
        $options = array_merge(
            array_values($selectedOptions),
            array_slice(
                array_values($regularOptions),
                0,
                max(0, self::USER_TEMPLATE_OPTION_LIMIT - count($selectedOptions))
            )
        );

        usort($options, function ($first, $second) {
            return strcasecmp($first['title'], $second['title']);
        });

        return $options;
    }

    /**
     * Create a fresh query for lightweight user-template selector options.
     *
     * @return Builder
     */
    private function newUserTemplateOptionsQuery()
    {
        return Board::select(['id', 'title'])
            ->whereIn('type', ['to-do', 'roadmap'])
            ->onlyTemplates();
    }

    /**
     * Format template models as selector options keyed by integer ID.
     *
     * @param iterable $templates
     * @return array
     */
    private function formatUserTemplateOptions($templates)
    {
        $options = [];

        foreach ($templates as $template) {
            $options[(int) $template->id] = [
                'id'    => (int) $template->id,
                'title' => $template->title,
            ];
        }

        return $options;
    }

    public function getBoardMembers($records, $search, $includeIds)
    {
        // Get all WordPress users
        $args = [
            'orderby' => 'display_name',
            'order' => 'ASC',
        ];

        // Add search filter if provided
        if (!empty($search)) {
            $args['search'] = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        // Include specific IDs if provided
        if (!empty($includeIds)) {
            $args['include'] = $includeIds;
        }

        $users = get_users($args);

        $formattedMembers = [];
        foreach ($users as $user) {
            $formattedMembers[] = [
                'id'    => strval($user->ID),
                'title' => $user->display_name . ' (' . $user->user_email . ')'
            ];
        }

        return $formattedMembers;
    }
}
