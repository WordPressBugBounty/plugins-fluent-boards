<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Activity;
use FluentBoards\App\Models\Stage;
use FluentBoards\App\Models\Webhook;
use FluentBoards\Framework\Support\Arr;
use FluentBoards\Framework\Support\Str;
use FluentBoards\App\Models\Board;
use FluentBoards\App\Services\Parsedown;
use FluentBoards\App\Services\PermissionManager;

class Helper
{
    public static function snake_case($string)
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }

    public static function slugify($text, $id = '', $length = 20)
    {
        $text = substr($text, 0, $length); // limiting to max 20 chars
        $text = Str::slug($text);
        if ($id) {
            $text = $id . '-' . $text;
        }

        return $text;
    }

    public static function loadView($template, $data)
    {
        extract($data, EXTR_OVERWRITE);

        $template = sanitize_file_name($template);

        $template = str_replace('.', DIRECTORY_SEPARATOR, $template);

        ob_start();
        include FLUENT_BOARDS_PLUGIN_PATH . 'app/Views/' . $template . '.php';

        return ob_get_clean();
    }

    private static function sanitizeData($data, $fieldMaps)
    {
        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = call_user_func($fieldMaps[$key], $value);
            }
        }

        return $data;
    }

    public static function sanitizeTask($data)
    {
        $fieldMaps = [
            'title'          => 'sanitize_text_field',
            'board_id'       => 'intval',
            'parent_id'      => 'intval',
            'crm_contact_id' => 'intval',
            'type'      => 'sanitize_text_field',
            'stage'          => 'sanitize_text_field',
            'reminder_type'  => 'sanitize_text_field',
            'priority'       => 'sanitize_text_field',
            'lead_value'     => 'doubleval',
            'remind_at'      => 'sanitize_text_field',
            'scope'          => 'sanitize_text_field',
            'source'         => 'sanitize_text_field',
            'description'    => 'wp_kses_post',
            'due_date'       => 'sanitize_text_field',
            'start_at'       => 'sanitize_text_field',
            'log_minutes'    => 'sanitize_text_field',
            'last_completed' => 'sanitize_text_field',
            'assignees'      => 'intval',
            'is_archived'    => 'intval',
            'previous_stage' => 'sanitize_text_field',
            'new_stage'      => 'sanitize_text_field',
            'new_index'      => 'intval',
            'old_index'      => 'intval',
            'new_board_id'   => 'intval',
            'position'       => 'intval'

        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeBoard($data)
    {
        $fieldMaps = [
            'board_id'        => 'intval',
            'title'           => 'sanitize_text_field',
            'parent_id'       => 'intval',
            'type'            => 'sanitize_text_field',
            'description'     => 'wp_kses_post',
            'currency'        => 'sanitize_text_field',
            'image_url'       => 'sanitize_url',
            'is_auth_require' => 'intval',
            'crm_contact_id'  => 'intval',
            'id'              => 'sanitize_text_field',
            'is_image'        => 'rest_sanitize_boolean',
            'color'           => 'sanitize_text_field', // sanitize_hex_color doesn't work when color code is greater than 6 characters
            'created_by'      => 'intval',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeStage($data)
    {
        $fieldMaps = [
            'title'  => 'sanitize_text_field',
            'slug'   => 'sanitize_text_field',
            'type'   => 'sanitize_text_field',
            'status' => 'sanitize_text_field',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeComment($data)
    {
        $fieldMaps = [
            'description' => 'wp_kses_post',
            'created_by'  => 'intval',
            'task_id'     => 'intval',
            'type'        => 'sanitize_text_field',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeLabel($data)
    {
        $fieldMaps = [
            'bg_color'   => 'sanitize_text_field',
            'color'      => 'sanitize_text_field',
            'label'      => 'sanitize_text_field',
            'boardId'    => 'intval',
            'task_id'    => 'intval',
            'meta_value' => 'intval',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeSubtask($data)
    {
        $fieldMaps = [
            'title'       => 'sanitize_text_field',
            'stage'       => 'sanitize_text_field',
            'newPosition' => 'intval',
            'priority'    => 'sanitize_text_field',
            'type'   => 'sanitize_text_field',
            'board_id'    => 'intval',
            'created_by'  => 'intval',
            'due_date'    => 'sanitize_text_field',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function createActivity($data)
    {
        return Activity::create($data);
    }

    public static function sanitizeTaskMeta($data)
    {
        $fieldMaps = [
            'title' => 'sanitize_text_field',
            'url'   => 'sanitize_url',

        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function getFormattedStagesByBoardId($boardId)
    {
        if (!$boardId) {
            return [];
        }

        $board = Board::findOrFail($boardId);

        $stages = $board->stages()->get();
//        return static::formateStage($stages);
        return $stages;
    }

    public static function getStagesByBoardId($boardId)
    {
        if (!$boardId) {
            return [];
        }

        $board = Board::findOrFail($boardId);

        $stages = $board->stages()->get();
        return static::formateStage($stages);
//        return $stages;
    }

    public static function formateStage($stages)
    {
        if (!$stages) return [];

        $formattedStages = [];
        foreach ($stages as $stage) {
            $boardName = $stage->board->title;
            $formattedStages[] = [
                'id'    => strval($stage->id),
                'title' => $boardName . ' - ' . $stage->title
            ];
        }
        return $formattedStages;
    }

    public static function getIdTitleArray($data)
    {
        $array = [];
        foreach ($data as $item) {
            $array[] = [
                'id'    => $item->id,
                'title' => $item->title
            ];
        }
        return $array;
    }

    // get Task Url by task id and board id
    public static function getTaskUrl($taskId, $boardId): string
    {
        if (!$taskId || !$boardId) {
            return '';
        }
        return fluent_boards_page_url() . 'boards/' . $boardId . '/tasks/' . $taskId;
    }

    public static function getTaskUrlByTask($task): string
    {
        if (!$task) {
            return '';
        }
        return fluent_boards_page_url() . 'boards/' . $task->board_id . '/tasks/' . $task->id;
    }

    public static function getBoardUrl($boardId): string
    {
        if (!$boardId) {
            return '';
        }
        return fluent_boards_page_url() . 'boards/' . $boardId;
    }

    public static function crm_contact($id)
    {
        if (!defined('FLUENTCRM')) {
            return '';
        }

        $contact = \FluentCrm\App\Models\Subscriber::with(['tags', 'lists'])->find($id);

        if (!$contact) {
            return null;
        }

        return [
            'id'              => $contact->id,
            'email'           => $contact->email,
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'full_name'       => $contact->full_name,
            'avatar'          => $contact->avatar,
            'photo'           => $contact->photo,
            'status'          => $contact->status,
            'contact_type'    => $contact->contact_type,
            'last_activity'   => $contact->last_activity,
            'life_time_value' => $contact->life_time_value,
            'total_points'    => $contact->total_points,
            'user_id'         => $contact->user_id,
            'created_at'      => $contact->created_at,
            'tags'            => Helper::getIdTitleArray($contact->tags),
            'lists'           => Helper::getIdTitleArray($contact->lists)

        ];

    }

    public static function getStagesByBoardGroup()
    {
        $boards = self::getBoards();

        $groups = [];
        foreach ($boards as $board) {
            $groups[] = [
                'title'   => $board->title,
                'slug'    => 'aaa' . '_' . $board->id,
                'options' => self::getStagesByBoardId($board->id)
            ];
        }
        return $groups;
    }

    public static function getBoards()
    {
        return Board::orderBy('created_at', 'ASC')->get();
    }

    public static function getStage($stageId)
    {
        return Stage::findOrFail($stageId);
    }

    public static function getBoardByStageId($stageId)
    {
        $stage = self::getStage($stageId);
        return $stage->board;
    }

    public static function sanitizeUserCollections($users)
    {
        if (empty($users) || !is_array($users)) {
            return $users;
        }
        
        foreach ($users as $key => $user) {
            if (is_object($user) && isset($user->pivot)) {
                $settings = maybe_unserialize($user->pivot->settings);
                $user->role = Arr::get($settings, 'is_admin')
                    ? 'Admin'
                    : (Arr::has($settings, 'is_viewer_only') && Arr::get($settings, 'is_viewer_only')
                        ? 'Viewer'
                        : 'Member');
            }
        }
        
        if (current_user_can('list_users')) {
            return $users;
        }

        if ($users) {
            $users->makeHidden(['user_email', 'user_nicename', 'user_registered', 'user_url', 'user_status']);
        }

        return $users;
    }

    public static function sanitizeUsersArray($users, $boardId = null)
    {
        if (current_user_can('list_users')) {
            return $users;
        }

        $sanitizedUsers = [];

        if(!PermissionManager::isBoardManager($boardId)) //Todo: may create permission security issue, will be modified later
        {
            $currentUser = wp_get_current_user();
            if($currentUser && isset($currentUser->user_email)){
                $currentUserEmail = $currentUser->user_email;
            }
            foreach ($users as $user) {

                if($user['email'] === $currentUserEmail){
                    $sanitizedUsers[] = $user;
                    continue;
                }

                if (isset($user['email'])) {
                    $user['email'] = self::obfuscateEmail($user['email']);
                }
                if (isset($user['user_email'])) {
                    $user['user_email'] = self::obfuscateEmail($user['user_email']);
                }

                $sanitizedUsers[] = $user;
            }
        } else {
            foreach ($users as $user) {
                $sanitizedUsers[] = $user;
            }
        }

        return $sanitizedUsers;
    }

    public static function obfuscateEmail($email)
    {
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $email; // Not a valid email, return as is
        }

        list($name, $domain) = explode('@', $email, 2);

        // Local part: show first 3 chars, then fixed ****
        $visibleLocal = substr($name, 0, 3);
        $maskedLocal  = $visibleLocal . '****';

        // Domain: mask the main label to **** + last 2 chars, keep rest (TLDs) intact
        $domainParts = explode('.', $domain);
        $mainLabel   = $domainParts[0] ?? '';
        $tail        = strlen($mainLabel) >= 2 ? substr($mainLabel, -2) : $mainLabel;
        $domainParts[0] = '****' . $tail; // e.g., example.com -> ****le.com
        $maskedDomain = implode('.', $domainParts);

        return $maskedLocal . '@' . $maskedDomain;
    }

    public static function getPriorityOptions()
    {
        return [
            [
                'id' => 'low',
                'title' => 'Low'
            ],
            [
                'id' => 'medium',
                'title' => 'Medium'
            ],
            [
                'id' => 'high',
                'title' => 'High'
            ],
        ];
    }

    public static function dueDateConversion($due_time, $unit)
    {
        if($due_time <= 0) {
            return null;
        }
        $currentTime = current_time('mysql');
        $readyString = '+' . $due_time . ' ' . $unit;
        return gmdate('Y-m-d H:i:s',strtotime($readyString,strtotime($currentTime)));
    }

    public static function searchWordPressUsers($searchQuery, $limit = 20)
    {
        $search = sanitize_text_field($searchQuery);

        // Search by user login, email, and nicename
        $args = array(
            'role'   => '',
            'search' => '*' . $search . '*',
            'number' => $limit,
        );

        // Get users by login, email, and nicename
        $user_query = new \WP_User_Query($args);
        $users_by_login = $user_query->get_results();

        // Search by first name and last name
        $meta_query_args = array(
            'relation' => 'OR',
            array(
                'key'     => 'first_name',
                'value'   => $search,
                'compare' => 'LIKE',
            ),
            array(
                'key'     => 'last_name',
                'value'   => $search,
                'compare' => 'LIKE',
            ),
        );

        $meta_query = array(
            'role'       => '',
            'meta_query' => $meta_query_args,
            'number'     => $limit,
        );

        // Get users by first name and last name
        $users_by_meta = get_users($meta_query);

        // Merge and remove duplicates
        $users = array_merge($users_by_login, $users_by_meta);
        $users = array_unique($users, SORT_REGULAR);

        return $users;

    }

    public static function sanitizeTaskAttachment($data)
    {
        $fieldMaps = [
            'title' => 'sanitize_text_field',
            'url'   => 'sanitize_url',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeTaskRepeatData($data)
    {
        $fieldMaps = [
            'create_new'              => 'intval',
            'repeat_in'               => 'intval',
            'repeat_type'             => 'sanitize_text_field',
            'repeat_when_complete'    => 'intval',
            'selected_month'          => 'sanitize_text_field',
            'board_id'                => 'intval',
            'time'                    => 'sanitize_text_field',
            'time_zone'               => 'sanitize_text_field',
            'next_repeat_date'        => 'sanitize_text_field',
            'repeat_in_month_type'    => 'sanitize_text_field',
        ];

        return self::sanitizeData($data, $fieldMaps);
    }

    public static function sanitizeTaskForWebHook($data)
    {
        $fieldMaps = [
            'title'          => 'sanitize_text_field',
            'board_id'       => 'intval',
            'parent_id'      => 'intval',
            'crm_contact_id' => 'intval',
            'type'      => 'sanitize_text_field',
            'stage'          => 'sanitize_text_field',
            'reminder_type'  => 'sanitize_text_field',
            'priority'       => 'sanitize_text_field',
            'lead_value'     => 'doubleval',
            'remind_at'      => 'sanitize_text_field',
            'scope'          => 'sanitize_text_field',
            'source'         => 'sanitize_text_field',
            'description'    => 'wp_kses_post',
            'due_date'       => 'sanitize_text_field',
            'start_at'       => 'sanitize_text_field',
            'log_minutes'    => 'sanitize_text_field',
            'last_completed' => 'sanitize_text_field',
            'is_archived'    => 'intval',
            'previous_stage' => 'sanitize_text_field',
            'new_stage'      => 'sanitize_text_field',
            'new_index'      => 'intval',
            'old_index'      => 'intval',
            'new_board_id'   => 'intval',
            'position'       => 'intval'

        ];

        return self::sanitizeData($data, $fieldMaps);
    }


    public static function taskReminderTypes()
    {
        $allowedTypes = [
                            '30_minutes_before' => __('30 minutes before', 'fluent-boards'),
                            '1_hour_before'     => __('1 hour before', 'fluent-boards'),
                            '2_hours_before'    => __('2 hours before', 'fluent-boards'),
                            '1_day_before'      => __('1 day before', 'fluent-boards'),
                            '2_days_before'     => __('2 days before', 'fluent-boards'),
                            '1_week_before'     => __('1 week before', 'fluent-boards'),
                        ];

        $allowedTypes = apply_filters('fluent_boards/task_reminder_types', $allowedTypes);

        return $allowedTypes;
    }
}
