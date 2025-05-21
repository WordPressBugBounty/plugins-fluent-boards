<?php

namespace FluentBoards\App\Http\Controllers;

use FluentBoards\App\App;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Services\BoardService;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\OptionService;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\Framework\Http\Request\Request;
use FluentBoards\Framework\Support\Arr;
use FluentBoardsPro\App\Hooks\Handlers\ProScheduleHandler;

class OptionsController extends Controller
{
    private $boardService;
    private $optionService;

    public function __construct(BoardService $boardService, OptionService $optionService)
    {
        parent::__construct();
        $this->boardService = $boardService;
        $this->optionService = $optionService;
    }

    public function selectorOptions(Request $request)
    {
        try {
            $optionKey = $request->getSafe('option_key');
            $search = $request->getSafe('search');
            $includedIds = $request->getSafe('values');
            $boardId = $request->getSafe('board_id');

            $options = [];
            if ('users' === $optionKey || 'task_assignees' === $optionKey) {  // no ajax/code is designed to handle this eventually will goto else

                if (!PermissionManager::isBoardManager($boardId)) {
                    throw new \Exception('You do not have permission to access this route');
                }

                if (!defined('FLUENT_BOARDS_PRO')) {
                    // get who has 'manage_options' capability
                    $users = PermissionManager::getAll_WP_Admins($search);

                } else {
                    // Search by user login, email, and nicename, first_name , last_name
                    $users = Helper::searchWordPressUsers($search);
                    $users = Helper::sanitizeUsersArray($users, $boardId);

                }

                $options = $this->addUserDataAsSelectorOption($users);

            } elseif ('boards' === $optionKey) {
                $boards = Board::query()
                    ->when($search, function ($query) use ($search) {
                        return $query->where('title', 'LIKE', '%' . $search . '%');
                    })->take(20)->get();

                foreach ($boards as $board) {
                    $options[] = [
                        'id'               => $board->id,
                        'title'            => $board->title,
                        'left_side_value'  => $board->title,
                        'right_side_value' => $board->slug,
                    ];
                }
            } elseif ('tasks' === $optionKey) {
                $tasks = Task::where('board_id', $boardId)
                    ->whereNull('archived_at')
                    ->whereNull('parent_id')
                    ->when($search, function ($query) use ($search) {
                        return $query->where('title', 'LIKE', '%' . $search . '%');
                    })->take(20)->get();

                foreach ($tasks as $task) {
                    $options[] = [
                        'id'               => $task->id,
                        'title'            => $task->title,
                        'board_id'         => $task->board_id,
                        'subtask_groups'   => $task->subtaskGroup
                    ];
                }

            } elseif ('assigned_in_task' == $optionKey) {
                $users = (new BoardService())->getAssigneesByBoard($boardId, $search);
                $options = $this->addUserDataAsSelectorOption($users);
            } else {
                $options = apply_filters('fluent_boards/ajax_options_' . $optionKey, [], $search, $includedIds);
            }

            return $this->sendSuccess([
                'options' => $options,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    private function addUserDataAsSelectorOption($users)
    {
        $options = [];
        foreach ($users as $user) {
            $options[] = [
                'id'               => $user->ID,
                'email'            => $user->user_email,
                'name'             => $user->display_name ?? $user->user_email,
                'title'            => $user->display_name . ' (' . $user->user_email . ')',
                'photo'            => get_avatar_url($user->user_email),
            ];
        }
        return $options;
    }

    public function getCurrentUserPermissions()
    {
        try {
            $currentUserBoards = Relation::query()
                ->where('user_id', get_current_user_id())
                ->whereNotNull('board_id')
                ->where('status', 'ACTIVE')
                ->get();
            foreach ($currentUserBoards as &$currentUserBoardPermission) {
                $currentUserBoardPermission->permissions = \maybe_unserialize($currentUserBoardPermission->permissions);
            }

            return $this->sendSuccess(
                $currentUserBoards, 200
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getUserPermission(Request $request)
    {
        try {
            $boardId = $request->getSafe('boardId');
            $userId = $request->getSafe('userId');

            $boardUser = Relation::where('board_id', $boardId)
                ->where('user_id', $userId)
                ->where('status', 'ACTIVE')->first();
            if (!$boardUser->is_admin) {
                $boardUser->permissions = \maybe_unserialize($boardUser->permissions);
            }

            return $this->sendSuccess(
                $boardUser, 200
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updatedUserPermission(Request $request)
    {
        try {
            $permission = $request->getSafe('userPermission');
            $updateType = $request->getSafe('updateType');
            $boardId = $request->getSafe('boardId');
            $userId = $request->getSafe('userId');

            $boardUser = Relation::where('board_id', $boardId)->where('user_id', $userId)->status('ACTIVE')->first();

            if ('Board Admin' == $permission) {
                $boardUser->is_admin = 'add' == $updateType ? 1 : 0;
                $boardUser->save();
            } else {
                if (0 == $boardUser->is_admin) {
                    $permissionsAlreadyHave = maybe_unserialize($boardUser->permissions);
                    if (!in_array($permission, $permissionsAlreadyHave) && 'add' == $updateType) {
                        array_push($permissionsAlreadyHave, $permission);
                    } elseif (in_array($permission, $permissionsAlreadyHave) && 'remove' == $updateType) {
                        if (($key = array_search($permission, $permissionsAlreadyHave)) !== false) {
                            unset($permissionsAlreadyHave[$key]);
                        }
                    }
                    $boardUser->permissions = serialize($permissionsAlreadyHave);
                    $boardUser->save();
                }
            }

            $boardUser->permissions = maybe_unserialize($boardUser->permissions);

            return $this->sendSuccess(
                $boardUser, 200
            );
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function SetUserSuperAdmin($userId)
    {
        try {
            $this->optionService->createSuperAdmin($userId);
            return $this->sendSuccess([
                'message' => __('Member has been set super admin successfully!', 'fluent-boards')
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function removeUserSuperAdmin($userId)
    {
        try {
            $this->optionService->removeUserSuperAdmin($userId);

            return $this->sendSuccess([
                'message' => __('User has been removed as super admin successfully!', 'fluent-boards'),
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function IsUserAllBoardAdmin(Request $request)
    {
        try {
            $userId = $request->getSafe('id');
            $isSuperAdmin = false;
            $superAdmin = Relation::where('board_id', null)->where('user_id', $userId)->where('status', 'ACTIVE')->first();
            $totalSuperAdmin = Relation::where('board_id', null)->where('status', 'ACTIVE')->count();
            $permissions = [];
            if ($superAdmin) {
                $isSuperAdmin = true;
                $permissions = \maybe_unserialize($superAdmin->permissions);
            }

            return $this->sendSuccess([
                'allBoardAdmin'      => $isSuperAdmin,
                'permissions'        => $permissions,
                'numberOfSuperAdmin' => $totalSuperAdmin,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function RemoveUserFromSuperAdmin(Request $request, $id)
    {
        try {
            $userId = $request->getSafe('id');

            $superAdmin = Relation::where('board_id', null)->where('user_id', $userId)->first();
            $superAdmin->status = 'INACTIVE';
            $superAdmin->save();

            return $this->sendSuccess([
                'message' => __('User removed as super admin', 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function removeUserFromBoard(Request $request)
    {
        try {
            $boardId = $request->getSafe('boardId');
            $userId = $request->getSafe('userId');

            $this->boardService->removeUserFromBoard($boardId, $userId);

            if (!PermissionManager::isAdmin($userId)) {
                $this->boardService->removeFromRecentlyOpened($boardId, $userId);
            }

            return $this->sendSuccess([
                'message' => __('User Removed from Board successfully!', 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function addAsSuperAdmin(Request $request)
    {
        try {
            $userIds = $request->getSafe('memberIds');
            foreach ($userIds as $userId) {
                $this->createSuperAdmin($userId);
            }

            return $this->sendSuccess([
                'message' => __('Fluent boards admin added', 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    private function createSuperAdmin($userId)
    {
        try {
            $existUser = Meta::where('object_id', $userId)->first();
            if (!$existUser) {
                $meta = new Meta();
                $meta->object_id = $userId;
                $meta->object_type = Constant::FLUENT_BOARD_ADMIN;
                $meta->save();
            }
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function addMembersInBoards(Request $request)
    {
        try {
            $userIds = $request->getSafe('memberIds');
            $boardIds = $request->getSafe('boardIds');

            foreach ($userIds as $userId) {
                foreach ($boardIds as $boardId) {
                    $this->boardService->addMembersInBoard($boardId, $userId);
                }
            }

            return $this->sendSuccess([
                'message' => __('Members added to boards', 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }


    public function updateGlobalNotificationSettings(Request $request)
    {
        try {
            $newSettings = $request->getSafe('updatedSettings');

            $this->optionService->updateGlobalNotificationSettings($newSettings);

            return $this->sendSuccess([
                'message' => __("Notification settings are updated", 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getGlobalNotificationSettings()
    {
        try {
            $globalSettings = $this->optionService->getGlobalNotificationSettings();
            if ($globalSettings->value)
                $currentSettings = maybe_unserialize($globalSettings->value);

            return $this->sendSuccess([
                'currentSettings' => $currentSettings,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function getBoardMembers(Request $request)
    {
        if (!PermissionManager::userHasAnyBoardAccess()) {
            return $this->sendError([
                'message' => __('You do not have permission to access this route', 'fluent-boards')
            ]);
        }

        $boardId = $request->getSafe('boardId', 'intval');

        $memberUserIds = Relation::where('object_type', 'board_user')
            ->select(['foreign_id'])
            ->groupBy('foreign_id');

        if ($boardId) {
            $memberUserIds = $memberUserIds->where('object_id', $boardId);
        }

        $members = [];

        $memberUserIds = $memberUserIds->get()
            ->pluck('foreign_id')->toArray();


        if ($memberUserIds) {
            $memberUsers = get_users([
                'include' => $memberUserIds
            ]);


            foreach ($memberUsers as $memberUser) {
                $name = trim($memberUser->first_name . ' ' . $memberUser->last_name);
                if (!$name) {
                    $name = $memberUser->display_name;
                }

                $members[$memberUser->ID] = [
                    'ID'           => $memberUser->ID,
                    'display_name' => $name,
                    'photo'        => get_avatar_url($memberUser->user_email)
                ];
            }

        }

        $adminUsers = get_users([
            'role'    => 'administrator',
            'exclude' => $memberUserIds
        ]);

        foreach ($adminUsers as $user) {
            $name = trim($user->first_name . ' ' . $user->last_name);
            if (!$name) {
                $name = $user->display_name;
            }
            $members[$user->ID] = [
                'ID'           => $user->ID,
                'display_name' => $name,
                'photo'        => get_avatar_url($user->user_email)
            ];
        }

        $members = array_values($members);

        // sort members by name
        usort($members, function ($a, $b) {
            return strcmp($a['display_name'], $b['display_name']);
        });

        return [
            'members' => $members
        ];
    }

    public function quickSearch()
    {
        $currentUserId = get_current_user_id();

        $query = sanitize_text_field($_REQUEST['query']);
        $query = strtolower($query);
        $scope = sanitize_text_field($_REQUEST['scope']);

        $firstThreeChars = substr($query, 0, 3);
        $firstNineChars = substr($query, 0, 9);

        if($firstThreeChars == 'id:') {
            $idPart = substr($query, 3);
            $idPart = preg_replace('/[^a-zA-Z0-9]/', '', $idPart);
            $tasksQuery = Task::query()->where('parent_id', null)->whereRaw('id LIKE ?', ['%' . $idPart . '%']);
        }elseif($firstNineChars == 'archived:') {
            $archivedPart = substr($query, 9);
            $archivedPart = trim($archivedPart);
            $tasksQuery = Task::query()->where('parent_id', null)->whereNotNull('archived_at')->whereRaw('LOWER(title) LIKE ?', ['%' . $archivedPart . '%']);
        } else {
            $tasksQuery = Task::query()->where('parent_id', null)->whereRaw('LOWER(title) LIKE ?', ['%' . $query . '%']);
        }

        $isUserAdmin = PermissionManager::isAdmin($currentUserId);

        // This is a check for deleted tasks to be excluded from search results
        $allActiveBoardsIds = Board::query()->where('archived_at', null)->pluck('id')->toArray();

        if ($scope == 'all') {
            if($firstThreeChars == 'id:'){
                $idPart = substr($query, 3);
                $idPart = preg_replace('/[^a-zA-Z0-9]/', '', $idPart);
                $boardQuery = Board::query()->whereRaw('id LIKE ?', ['%' . $idPart . '%']);
            }elseif ($firstNineChars == 'archived:') {
                $archivedPart = substr($query, 9);
                $archivedPart = trim($archivedPart);
                $boardQuery = Board::query()->whereNotNull('archived_at')->whereRaw('LOWER(title) LIKE ?', ['%' . $archivedPart . '%']);
            } else {
                $boardQuery = Board::query()->whereRaw('LOWER(title) LIKE ?', ['%' . $query . '%']);
            }
            if ($isUserAdmin) {
                $boards = $boardQuery->limit(10)->get();
                $tasks = $tasksQuery->limit(10)->get();
            } else {
                $boardIds = PermissionManager::getBoardIdsForUser($currentUserId);
                $boards = $boardQuery->whereIn('id', $boardIds)->limit(10)->get();
                $tasks = $tasksQuery->whereIn('board_id', $boardIds)->limit(10)->get();
            }
        } else {
            $boards = []; // boards results is not needed in scoped search
            $inBoard = (int)$scope;
            if ($isUserAdmin || in_array($inBoard, $boardIds = PermissionManager::getBoardIdsForUser($currentUserId))) {
                $tasks = $tasksQuery->where('board_id', $inBoard)->limit(10)->get();
            } else {
                // Out of permission scope search
                $tasks = [];
            }
        }


        $formattedTasks = [];
        $formattedBoards = [];
        foreach ($boards as $board) {
            $formattedBoards[] = [
                'type'        => 'board',
                'id'          => $board->id,
                'title'       => $board->title,
                'description' => $board->description,
            ];
        }
        foreach ($tasks as $task) {

            if (!in_array($task->board_id, $allActiveBoardsIds)) {
                // if the task is not in an active board, skip it
                continue;
            }

            $board = $task->board;

            $formattedTasks[] = [
                'type'        => 'task',
                'id'          => $task->id,
                'title'       => $task->title,
                'description' => $task->description,
                'board_id'    => $task->board_id,
                'board'       => [
                    'id'    => $board->id,
                    'title' => $board->title,
                    'url'   => Helper::getBoardUrl($board->id)
                ],
                'stage'       => [
                    'id'    => $task->stage_id,
                    'title' => $task->stage->title ?? '',
                ],

            ];
        }
        return $this->sendSuccess([
            'tasks'  => $formattedTasks,
            'boards' => $formattedBoards
        ], 200);

    }

    public function getDashboardViewSettings()
    {
        try {
            $globalSettings = $this->optionService->getDashboardViewSettings();
            if ($globalSettings->value)
                $currentSettings = maybe_unserialize($globalSettings->value);

            return $this->sendSuccess([
                'currentSettings' => $currentSettings,
            ], 200);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }

    public function updateDashboardViewSettings(Request $request)
    {
        try {
            $newSettings = $request->getSafe('updatedSettings');

            $this->optionService->updateDashboardViewSettings($newSettings);

            return $this->sendSuccess([
                'message' => __("Dashboard view settings are updated", 'fluent-boards'),
            ], 201);
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage(), 404);
        }
    }


    public function getAddonsSettings()
    {
        $addOns = [
            'fluent-crm'     => [
                'title'          => __('FluentCRM', 'fluent-boards'),
                'logo'           => fluent_boards_mix('images/addons/fluent-crm.svg'),
                'is_installed'   => defined('FLUENTCRM'),
                'learn_more_url' => 'https://fluentcrm.com/',
                'associate_doc'  => 'https://fluentboards.com/docs/fluentboards-integration-with-fluentcrm/',
                'action_text'    => $this->isPluginInstalled('fluent-crm/fluent-crm.php') ? __('Activate FluentCRM', 'fluent-boards') : __('Install FluentCRM', 'fluent-boards'),
                'description'    => __('FluentCRM is a Self Hosted Email Marketing Automation Plugin for WordPress. Manage your leads and customers, email campaigns, automated email sequencing and many more', 'fluent-boards'),
                'short_desc'     => __('Email marketing automation', 'fluent-boards')
            ],
            'fluentform'     => [
                'title'          => __('Fluent Forms', 'fluent-boards'),
                'logo'           => fluent_boards_mix('images/addons/fluentform.png'),
                'is_installed'   => defined('FLUENTFORM'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluentform/',
                'associate_doc'  => 'https://fluentboards.com/docs/fluentboards-integration-with-fluent-forms/',
                'action_text'    => $this->isPluginInstalled('fluent-form/fluent-form.php') ? __('Activate Fluent Forms', 'fluent-boards') : __('Install Fluent Forms', 'fluent-boards'),
                'description'    => __('Collect leads and build any type of forms, accept payments, connect with your CRM with the Fastest Contact Form Builder Plugin for WordPress', 'fluent-boards'),
                'short_desc'     => __('Create forms and accept payments', 'fluent-boards')
            ],
            'fluent-support' => [
                'title'          => __('Fluent Support', 'fluent-boards'),
                'logo'           => fluent_boards_mix('images/addons/fluent-support.svg'),
                'is_installed'   => defined('FLUENT_SUPPORT_VERSION'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-connect/',
                'settings_url'   => admin_url('admin.php?page=fluent-support#/'),
                'associate_doc'  => 'https://fluentboards.com/docs/fluentboards-integration-with-fluentsupport/',
                'action_text'    => $this->isPluginInstalled('fluent-support/fluent-support.php') ? __('Activate Fluent Support', 'fluent-boards') : __('Install Fluent Support', 'fluent-boards'),
                'description'    => __('WordPress Helpdesk and Customer Support Ticket Plugin. Provide awesome support and manage customer queries right from your WordPress dashboard.', 'fluent-boards'),
                'short_desc'     => __('Customer support ticketing', 'fluent-boards')
            ],
            'fluent-smtp'    => [
                'title'          => __('Fluent SMTP', 'fluent-boards'),
                'logo'           => fluent_boards_mix('images/addons/fluent-smtp.svg'),
                'is_installed'   => defined('FLUENTMAIL'),
                'learn_more_url' => 'https://wordpress.org/plugins/fluent-smtp/',
                'associate_doc'  => admin_url('options-general.php?page=fluent-mail#/'),
                'action_text'    => $this->isPluginInstalled('fluent-smtp/fluent-smtp.php') ? __('Activate Fluent SMTP', 'fluent-boards') : __('Install Fluent SMTP', 'fluent-boards'),
                'description'    => __('The Ultimate SMTP and SES Plugin for WordPress. Connect with any SMTP, SendGrid, Mailgun, SES, Sendinblue, PepiPost, Google, Microsoft and more.', 'fluent-boards'),
                'short_desc'    => __('Reliable email delivery with SMTP', 'fluent-boards')
            ],
        ];

        $addOns = apply_filters('fluent_boards/addons_settings', $addOns);

        $modules = fluent_boards_get_pref_settings(false);

        if (empty($modules['frontend']['render_type'])) {
            $modules['frontend']['render_type'] = 'standalone';
        }

        $modules['panel_url'] = fluent_boards_page_url();

        return [
            'addons'         => $addOns,
            'featureModules' => $modules
        ];
    }

    public function saveAddonsSettings(Request $request)
    {
        if (!defined('FLUENT_BOARDS_PRO')) {
            return $this->sendError([
                'message' => __('This feature is only available in Fluent Boards Pro', 'fluent-boards')
            ]);
        }

        $settings = $request->get('settings', []);

        $prefSettings = fluent_boards_get_pref_settings(false);

        $settings = wp_parse_args($settings, $prefSettings);

        $settings = Arr::only($settings, array_keys($prefSettings));
        $settings['frontend']['slug'] = sanitize_title($settings['frontend']['slug']);

        if (empty($settings['frontend']['slug'])) {
            $settings['frontend']['slug'] = 'projects';
        }

        if (defined('FLUENT_BOARDS_SLUG') && FLUENT_BOARDS_SLUG) {
            $settings['frontend']['slug'] = FLUENT_BOARDS_SLUG;
        }

        do_action('fluent_boards/saving_addons', $settings, $prefSettings);

        update_option('fluent_boards_modules', $settings, 'yes');

        if (isset($settings['recurring_task']['enabled']) && $settings['recurring_task']['enabled'] == 'no') {
            do_action('fluent_boards/recurring_task_disabled');
        }

        return $this->sendSuccess([
            'message'        => __('Settings are saved', 'fluent-boards'),
            'featureModules' => $settings
        ]);
    }

    public function installPlugin(Request $request)
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry! you do not have permission to install plugin', 'fluent-boards')
            ]);
        }

        $plugin = $request->getSafe('plugin', 'sanitize_text_field');

        $acceptedFreePlugins = [
            'fluent-crm'     => 'fluent-crm.php',
            'fluentform'     => 'fluentform.php',
            'fluent-support' => 'fluent-support.php',
            'fluent-smtp'    => 'fluent-smtp.php'
        ];

        $acceptedPlugins = apply_filters('fluent_boards/accepted_plugins', $acceptedFreePlugins);

        if (!isset($acceptedPlugins[$plugin])) {
            return $this->sendError([
                'message' => __('Invalid plugin', 'fluent-boards')
            ]);
        }

        $pluginToInstall = [
            'name'      => __('Fluent Plugin', 'fluent-boards'),
            'repo-slug' => $plugin,
            'file'      => $acceptedPlugins[$plugin],
        ];

        // if plugin in free list then run background intaller otherwise call an action to install
        if (isset($acceptedFreePlugins[$plugin])) {
            $this->backgroundInstaller($pluginToInstall, $plugin);
        } else {
            do_action('fluent_boards/install_plugin', $pluginToInstall, $plugin);
        }

        return $this->sendSuccess([
            'message' => __('Plugin is being installed', 'fluent-boards')
        ]);
    }

    private function isPluginInstalled($plugin)
    {
        return file_exists(WP_PLUGIN_DIR . '/' . $plugin);
    }

    private function backgroundInstaller($plugin_to_install, $plugin_id)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_reduce(array_keys(\get_plugins()), array($this, 'associate_plugin_file'), array());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;

            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception($plugin_information->get_error_message());
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception($download->get_error_message());
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception($working_dir->get_error_message());
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }

                    $activate = true;

                } catch (\Exception $e) {
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception($result->get_error_message());
                    }
                } catch (\Exception $e) {
                }
            }
        }
    }

    private function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }

    public function getBoards(Request $request)
    {
        $boards = Board::select(['id', 'title', 'type'])
            ->byAccessUser(get_current_user_id())
            ->orderBy('title', 'ASC')
            ->get();

        return $this->sendSuccess([
            'boards' => $boards
        ]);
    }

    public function getPages(Request $request)
    {

        $db = App::getInstance('db');

        $allPages = $db->table('posts')->where('post_type', 'page')
            ->where('post_status', 'publish')
            ->select(['ID', 'post_title'])
            ->orderBy('post_title', 'ASC')
            ->get();

        $pages = [];
        foreach ($allPages as $page) {
            $pages[] = [
                'id'    => $page->ID,
                'title' => $page->post_title ? $page->post_title : __('(no title)', 'fluent-boards')
            ];
        }

        return $this->sendSuccess([
            'pages' => $pages
        ]);
    }

    public function getGeneralSettings()
    {
        $settings = fluent_boards_get_option('general_settings', []);

        return $this->sendSuccess([
            'settings' => $settings,
            'server_timezone' => \wp_timezone_string()
        ]);

    }

    public function saveGeneralSettings(Request $request)
    {
        // check for pro version
        if (!defined('FLUENT_BOARDS_PRO')) {
            return $this->sendError([
                'message' => __('This feature is only available in Fluent Boards Pro. Please upgrade.', 'fluent-boards')
            ]);
        }
        $settings = $request->getSafe('updatedSettings', []);

        $settings = apply_filters('fluent_boards/save_general_settings', $settings);

        $savedSettings = fluent_boards_update_option('general_settings', $settings);
        $savedGeneralSettings = \maybe_unserialize($savedSettings->value);

        $scheduleHandler = new ProScheduleHandler();

        if ($savedGeneralSettings['daily_reminder_enabled'] || $savedGeneralSettings['daily_reminder_enabled'] == 'true') {
            // force schedule from this settings update
            $scheduleHandler->clearDailyTaskReminderScheduler();
            $scheduleHandler->scheduleDailyTaskReminder();
        }

        return $this->sendSuccess([
            'settings' => $savedGeneralSettings,
            'message' => __('Settings are saved', 'fluent-boards')
        ]);

    }

}
