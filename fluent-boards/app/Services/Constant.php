<?php

namespace FluentBoards\App\Services;


class Constant{

    const BOARD_EMAIL_COMMENT =  'email_after_comment';
    const BOARD_EMAIL_TASK_ASSIGN =  'email_after_task_assign';
    const BOARD_EMAIL_STAGE_CHANGE =  'email_after_task_stage_change';

    const BOARD_EMAIL_DUE_DATE_CHANGE =  'email_after_task_due_date_change';
    const BOARD_EMAIL_REMOVE_FROM_TASK =  'email_after_remove_from_task';
    const BOARD_EMAIL_TASK_ARCHIVE =  'email_after_task_archive';



    const ACTIVITY_TASK =  'task_activity';
    const ACTIVITY_BOARD =  'board_activity';

    const TASK_ASSIGNEE =  'task_assignee';

    const OBJECT_TYPE_BOARD_USER_EMAIL_NOTIFICATION = 'board_user_email';
    const OBJECT_TYPE_BOARD_USER_NOTIFICATION = 'board_user_notification';
    const OBJECT_TYPE_TASK_ASSIGNEE = 'task_assignee';
    const OBJECT_TYPE_TASK_LABEL = 'task_label';
    const OBJECT_TYPE_BOARD_USER = 'board_user';

    const OBJECT_TYPE_USER = 'user';

    const TASK_WATCH_NOTIFICATION_TYPES = [
        'comment_add'               => true,
        'comment_update'            => true,
        'description_update'        => true,
        'title_change'              => true,
        'move_to_another_board'     => true,
        'Label'                     => true,
        'due_at'                    => true,
        'priority'                  => true,
        'assignee_added'            => true,
        'assignee_removed'          => true
    ];

    const BOARD_NOTIFICATION_TYPES = [
        'email_after_comment'       => true,
        'email_after_task_assign'    => true,
        'email_after_task_stage_change'    => true,
        'email_after_task_due_date_change' => true,
        'email_after_remove_from_task' => true,
        'email_after_task_archive' => true,
        'dashboard_notification' => true
    ];

    const OBJECT_TYPE_BOARD_NOTIFICATION = 'board_notification';

    const OBJECT_TYPE_USER_TASK_WATCH = 'task_user_watch';

    const IS_TASK_TEMPLATE = 'is_template';

    const BOARD_USER_SETTINGS = [
        'is_admin'           => false
    ];
    const BOARD_USER_VIEWER_ONLY_SETTINGS = [
        'is_admin'           => false,
        'is_viewer_only'      => true
    ];

    const FLUENT_BOARD_ADMIN = 'fluent_board_admin';

    const IS_BOARD_ADMIN = 'is_admin';

    const USER_RECENT_BOARDS = 'user_recent_boards';
    const USER_GLOBAL_NOTIFICATIONS = 'user_global_notifications';

    const OBJECT_TYPE_BOARD = 'board';

    const OBJECT_TYPE_FOLDER = 'folder';
    const OBJECT_TYPE_TASK = 'TASK';
    const CRM_CONTACT = 'crm_subscriber';
    const CRM_CONTACT_ASSOCIATED_BOARD = 'contact_associated_board';
    const BOARD_ASSOCIATED_CRM_CONTACT = 'board_associated_contact';

    const BOARD_INVITATION = 'board_email_invitation';

    const GLOBAL_EMAIL_NOTIFICATION_COMMENT = 'email_after_comment';
    const GLOBAL_EMAIL_NOTIFICATION_TASK_ASSIGN = 'email_after_task_assign';
    const GLOBAL_EMAIL_NOTIFICATION_STAGE_CHANGE = 'email_after_task_stage_change';
    const GLOBAL_EMAIL_NOTIFICATION_DUE_DATE = 'email_after_task_due_date_change';
    const GLOBAL_EMAIL_NOTIFICATION_REMOVE_FROM_TASK = 'email_after_remove_from_task';
    const GLOBAL_EMAIL_NOTIFICATION_TASK_ARCHIVE = 'email_after_task_archive';
    const GLOBAL_EMAIL_NOTIFICATION_CREATING_TASK = 'watch_on_creating_task';
    const GLOBAL_EMAIL_NOTIFICATION_COMMENTING = 'watch_on_commenting';
    const GLOBAL_EMAIL_NOTIFICATION_ASSIGNING= 'watch_on_assigning';
    const GLOBAL_DASHBOARD_NOTIFICATION = 'dashboard_notification';

    const USER_DASHBOARD_VIEW = 'user_dashboard_view';

    const USER_LISTVIEW_PREFERENCES = 'user_listview_preferences';
    const USER_TABLEVIEW_PREFERENCES = 'user_tableview_preferences';

    const DEFAULT_DASHBOARD_VIEW_PREFERENCES = [
        'dashboard_view_label' => true,
        'dashboard_view_priority' => true,
        'dashboard_view_assignee' => true,
        'dashboard_view_subtask' => true,
        'dashboard_view_attachment' => true,
        'dashboard_view_due_date' => true,
        'dashboard_view_comment' => true,
        'dashboard_view_notification' => true
    ];

    const DEFAULT_TABLEVIEW_VIEW_PREFERENCES = [
        'table_view_priority' => true,
        'table_view_status' => true,
        'table_view_dates' => true,
        'table_view_assignees' => true,
        'table_view_labels' => true,
        'table_view_created_at' => true,
    ];


    const BOARD_DEFAULT_IMAGE = 'board_default_image';
    const BOARD_DEFAULT_IMAGE_URL = 'https://fluentboards.com/shared-files/';


    const TRELLO_COLOR_MAP = [
        "green" => "#4bce97",
        "yellow" => "#f5cd47",
        "orange" => "#fea362",
        "red" => "#f87168",
        "purple" => "#9f8fef",
        "blue" => "#579dff",
        "sky" => "#6cc3e0",
        "lime" => "#94c748",
        "pink" => "#e774bb",
        "black" => "#8590a2",
        "green_dark" => "#1f845a",
        "yellow_dark" => "#946f00",
        "orange_dark" => "#c25100",
        "red_dark" => "#ae2e24",
        "purple_dark" => "#5e4db2",
        "blue_dark" => "#0c66e4",
        "sky_dark" => "#206a83",
        "lime_dark" => "#5b7f24",
        "pink_dark" => "#943d73",
        "black_dark" => "#44546f",
        "green_light" => "#baf3db",
        "yellow_light" => "#f8e6a0",
        "orange_light" => "#fedec8",
        "red_light" => "#ffd5d2",
        "purple_light" => "#dfd8fd",
        "blue_light" => "#cce0ff",
        "sky_light" => "#c6edfb",
        "lime_light" => "#d3f1a7",
        "pink_light" => "#fdd0ec",
        "black_light" => "#dcdfe4"
    ];

    const TEXT_COLOR_MAP = [
        "green" => "#1B2533",
        "yellow" => "#1B2533",
        "orange" => "#1B2533",
        "red" => "#1B2533",
        "purple" => "#1B2533",
        "blue" => "#1B2533",
        "sky" => "#1B2533",
        "lime" => "#1B2533",
        "pink" => "#1B2533",
        "black" => "#1B2533",
        "green_dark" => "#fff",
        "yellow_dark" => "#fff",
        "orange_dark" => "#fff",
        "red_dark" => "#fff",
        "purple_dark" => "#fff",
        "blue_dark" => "#fff",
        "sky_dark" => "#fff",
        "lime_dark" => "#fff",
        "pink_dark" => "#fff",
        "black_dark" => "#fff",
        "green_light" => "#1B2533",
        "yellow_light" => "#1B2533",
        "orange_light" => "#1B2533",
        "red_light" => "#1B2533",
        "purple_light" => "#1B2533",
        "blue_light" => "#1B2533",
        "sky_light" => "#1B2533",
        "lime_light" => "#1B2533",
        "pink_light" => "#1B2533",
        "black_light" => "#1B2533"
    ];


    const FBS_ONBOARDING = 'fbs_onboarding';
    const FBS_RECENTLY_VIEWED_CHECK = 'fbs_recently_viewed_checked';
    const BOARD_BACKGROUND_DEFAULT_SOLID_COLORS = [
        [
            'id' => 'solid_1',
            'value' => '#d1d8e0'
        ],
        [
            'id' => 'solid_2',
            'value' => '#ff9ff3'
        ],
        [
            'id' => 'solid_3',
            'value' => '#a55eea'
        ],
        [
            'id' => 'solid_4',
            'value' => '#5f27cd'
        ],
        [
            'id' => 'solid_5',
            'value' => '#706fd3'
        ],
        [
            'id' => 'solid_6',
            'value' => '#4b7bec'
        ],
        [
            'id' => 'solid_7',
            'value' => '#4640FB'
        ],
        [
            'id' => 'solid_8',
            'value' => '#474787'
        ],
        [
            'id' => 'solid_9',
            'value' => '#3F1770'
        ],
        [
            'id' => 'solid_10',
            'value' => '#182C61'
        ],
        [
            'id' => 'solid_11',
            'value' => '#1B1464'
        ],
        [
            'id' => 'solid_12',
            'value' => '#2f3640'
        ],
        [
            'id' => 'solid_13',
            'value' => '#043D4A'
        ],
        [
            'id' => 'solid_14',
            'value' => '#009432'
        ],
        [
            'id' => 'solid_15',
            'value' => '#10ac84'
        ],
        [
            'id' => 'solid_16',
            'value' => '#ffc048'
        ],
        [
            'id' => 'solid_17',
            'value' => '#ffb142'
        ],
        [
            'id' => 'solid_18',
            'value' => '#F48D45'
        ],
        [
            'id' => 'solid_19',
            'value' => '#EF6A64'
        ],
        [
            'id' => 'solid_20',
            'value' => '#ff5252'
        ],
        [
            'id' => 'solid_21',
            'value' => '#c23616'
        ],
        [
            'id' => 'solid_22',
            'value' => 'hsla(238, 100%, 71%, 1)'
        ],
        [
            'id' => 'solid_23',
            'value' => 'hsla(171, 87%, 67%, 1)'
        ]
    ];

    const BOARD_BACKGROUND_DEFAULT_GRADIENT_COLORS = [
        [
            'id' => 'gradient_1',
            'value' => 'linear-gradient(111.53deg, #4A9B7F 2%, #0A3431 100%)',
        ],
        [
            'id' => 'gradient_2',
            'value' => 'linear-gradient(135deg, #B57BEE -2.09%, #392D69 100%)',
        ],
        [
            'id' => 'gradient_3',
            'value' => 'linear-gradient(111.53deg, #FD792F 0%, #F83D5C 98%)',
        ],
        [
            'id' => 'gradient_4',
            'value' => 'linear-gradient(140.25deg, #0968E5 0.02%, #020344 100%)',
        ],
        [
            'id' => 'gradient_5',
            'value' => 'linear-gradient(90deg, hsla(238, 100%, 71%, 1) 0%, hsla(295, 100%, 84%, 1) 100%)',
        ],
        [
            'id' => 'gradient_6',
            'value' => 'linear-gradient(90deg, hsla(171, 87%, 67%, 1) 0%, hsla(236, 100%, 72%, 1) 100%)',
        ]
    ];

    const ACTIVITY_ACTION_CREATED = 'created';
    const ACTIVITY_ACTION_UPDATED = 'updated';
    const ACTIVITY_ACTION_DELETED = 'deleted';

    const TASK_ATTACHMENT = 'TASK';

    const TASK_DESCRIPTION = 'task_description';

    const COMMENT_IMAGE = 'comment_image';

    const REPEAT_TASK_META = 'repeat_task';

    const TASK_CUSTOM_FIELD = 'task_custom_field';

    const BOARD_CSV_EXPORT = 'csv_export';
    const BOARD_JSON_EXPORT = 'json_export';
    const BOARD_ATTACHMENT = 'BOARD';

    const BOARD_BACKGROUND_IMAGE = 'board_background_image';
    const FBS_TASK_TABS_CONFIG = "fbs_task_tabs_config";

    const FBS_OLD_SUBTASK_SYNC = 'fbs_old_subtask_sync';

    const SUBTASK_GROUP_NAME = 'group_name';
    const SUBTASK_GROUP_CHILD = 'subtask_group_id';

    const USER_PINNED_BOARDS = 'pinned_boards';
}
