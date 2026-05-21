<?php

namespace FluentBoards\App\Services\Intergrations\FluentCRM;


use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\TaskMeta;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\PermissionManager;

class DeepIntegration
{
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
        if (!defined('FLUENT_BOARDS_PRO')) {
            return [];
        }

        $templateService = new \FluentBoardsPro\App\Services\TemplateService();
        $templates = $templateService->getDefaultTemplates();

        $formattedTemplates = [];
        foreach ($templates as $template) {
            // Filter by search if provided
            if (!empty($search)) {
                $title = strtolower($template['title'] ?? '');
                $description = strtolower($template['description'] ?? '');
                $searchLower = strtolower($search);
                
                if (strpos($title, $searchLower) === false && strpos($description, $searchLower) === false) {
                    continue;
                }
            }

            $formattedTemplates[] = [
                'id'    => $template['id'],
                'title' => $template['title']
            ];
        }

        return $formattedTemplates;
    }

    public function getUserBoardTemplates($records, $search, $includeIds)
    {
        if (!defined('FLUENT_BOARDS_PRO')) {
            return [];
        }

        $templateService = new \FluentBoardsPro\App\Services\TemplateService();
        $templates = $templateService->getUserTemplates();

        $formattedTemplates = [];
        foreach ($templates as $template) {
            // Filter by search if provided
            if (!empty($search)) {
                $title = strtolower($template['title'] ?? '');
                $description = strtolower($template['description'] ?? '');
                $searchLower = strtolower($search);
                
                if (strpos($title, $searchLower) === false && strpos($description, $searchLower) === false) {
                    continue;
                }
            }

            $formattedTemplates[] = [
                'id'    => strval($template['id']),
                'title' => $template['title']
            ];
        }

        return $formattedTemplates;
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
