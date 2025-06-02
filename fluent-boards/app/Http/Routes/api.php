<?php
//if accessed directly exit
if (!defined('ABSPATH')) exit;

/**
 * @var $router \FluentBoards\Framework\Http\Router
 */

$router->prefix('tasks')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('/top-in-boards', 'TaskController@getTopTasksForBoards');
    $router->get('/crm-associated-tasks/{associated_id}', 'TaskController@getAssociatedTasks')->int('associated_id');
    $router->get('/stage/{task_id}', 'TaskController@getStageByTask'); //FUTURE: this api need to be relocated

    $router->post('/create-first-task', 'TaskController@createFirstTask');
    $router->get('/boards-by-type/{type}', 'BoardController@getBoardsByType');
    $router->get('/{task_id}/labels', 'TaskController@getLabelsByTask');

    // Task tabs configuration
    $router->get('/task-tabs/config', 'TaskController@getTaskTabsConfig');
    $router->post('/task-tabs/config', 'TaskController@saveTaskTabsConfig');
});

$router->withPolicy('BoardUserPolicy')->group(function ($router) {
    $router->get('/member-associated-users/{id}', 'UserController@memberAssociatedTaskUsers');
    $router->get('/search-member-users/{id}', 'UserController@searchMemberUser');
    $router->get('get-user-permissions', 'OptionsController@getUserPermission');
    $router->get('ajax-options', 'OptionsController@selectorOptions');
    $router->put('update-user-permissions', 'OptionsController@updatedUserPermission');
    $router->post('set-permission-all-board-admin', 'OptionsController@SetUserPermissionAllBoardAdmin');
    $router->delete('remove-user-from-board', 'OptionsController@removeUserFromBoard');
    $router->get('/fluent-boards-users', 'UserController@allFluentBoardsUsers');
    $router->get('/search-fluent-boards-users', 'UserController@searchFluentBoardsUser');
    $router->post('update-global-notification-settings', 'OptionsController@updateGlobalNotificationSettings');
    $router->get('get-global-notification-settings', 'OptionsController@getGlobalNotificationSettings');
    $router->put('update-dashboard-view-settings', 'OptionsController@updateDashboardViewSettings');
    $router->get('get-dashboard-view-settings', 'OptionsController@getDashboardViewSettings');
});

$router->prefix('projects')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('/', 'BoardController@getBoards');
    $router->post('/', 'BoardController@create');
    $router->get('/get-default-board-colors', 'BoardController@getBoardDefaultBackgroundColors');
    $router->get('/list-of-boards', 'BoardController@getBoardsList'); // it is using for to get all boards by user
    $router->get('/crm-associated-boards/{id}', 'BoardController@getAssociatedBoards')->int('associated_id');
    $router->get('/currencies', 'BoardController@getCurrencies');
    $router->get('/user-admin-in-boards', 'BoardController@getUsersOfBoards');
    $router->get('/recent-boards', 'BoardController@getRecentBoards');
    $router->post('/onboard', 'BoardController@createFirstBoard');
    $router->put('/skip-onboarding', 'BoardController@skipOnboarding');
    $router->get('/pinned-boards', 'BoardController@getPinnedBoards');
});

$router->prefix('projects/{board_id}')->withPolicy('SingleBoardPolicy')->group(function ($router) {
    $router->get('/', 'BoardController@find')->int('board_id');
    $router->get('/has-data-changed', 'BoardController@hasDataChanged')->int('board_id');
    $router->put('/update-board-properties', 'BoardController@updateBoardProperties')->int('board_id');
    $router->put('/', 'BoardController@update')->int('board_id');
    $router->delete('/', 'BoardController@delete')->int('board_id');
    $router->get('/labels', 'LabelController@getLabelsByBoard');
    $router->post('/labels', 'LabelController@createLabel');
    $router->get('/labels/used-in-tasks', 'LabelController@getLabelsByBoardUsedInTasks');
    $router->get('/tasks/{task_id}/labels', 'LabelController@getLabelsByTask'); //FUTURE: these api need to be relocated
    $router->post('/labels/task', 'LabelController@createLabelForTask');
    $router->put('/labels/{label_id}', 'LabelController@editLabelofBoard');
    $router->delete('/labels/{label_id}', 'LabelController@deleteLabelOfBoard');
    $router->delete('/tasks/{task_id}/labels/{label_id}', 'LabelController@deleteLabelOfTask');
    $router->put('/pin-board', 'BoardController@pinBoard');
    $router->put('/unpin-board', 'BoardController@unpinBoard');

    $router->get('/users', 'BoardController@getBoardUsers');
    $router->post('/user/{user_id}/remove', 'BoardController@removeUserFromBoard')->int('board_id')->int('user_id');
    $router->post('/add-members', 'BoardController@addMembersInBoard');

    $router->get('/assignees', 'BoardController@getAssigneesByBoard')->int('board_id');
    $router->get('/activities', 'BoardController@getActivities')->int('board_id');

    $router->put('/stage-move-all-task', 'BoardController@moveAllTasks')->int('board_id');
    $router->post('/stage-create', 'BoardController@createStage')->int('board_id');
    $router->put('/stage/{stage_id}/sort-task', 'StageController@sortStageTasks')->int('board_id')->int('stage_id');
    $router->put('/stage/{stage_id}/archive-all-task', 'BoardController@archiveAllTasksInStage')->int('board_id')->int('stage_id');
    $router->put('/change-stage-position', 'BoardController@changePositionOfStage')->int('board_id');
    $router->put('/re-position-stages', 'BoardController@repositionStages')->int('board_id');
    $router->put('/update-stage/{stage_id}', 'StageController@updateStage')->int('board_id'); //Todo:: will delete later
    $router->put('/update-stage-property/{stage_id}', 'StageController@updateStageProperty')->int('board_id');
    $router->put('/archive-stage/{stage_id}', 'BoardController@archiveStage')->int('board_id');
    $router->put('/stage-view/{stage_id}', 'BoardController@changeStageView')->int('board_id');
    $router->put('/restore-stage/{stage_id}', 'BoardController@restoreStage')->int('board_id');
    $router->put('/drag-stage', 'StageController@dragStage')->int('board_id');
    $router->get('/archived-stages', 'BoardController@getArchivedStage')->int('board_id');
    $router->get('/archived-tasks', 'TaskController@getArchivedTasks')->int('board_id');
    $router->get('/stage-task-available-positions/{stage_id}', 'BoardController@getStageTaskAvailablePositions')->int('board_id')->int('stage_id');

    $router->post('/crm-contact', 'BoardController@updateAssociateCrmContact')->int('board_id');
    $router->get('/crm-contacts', 'BoardController@getAssociateCrmContacts')->int('board_id');
    $router->delete('/crm-contact/{contact_id}', 'BoardController@deleteAssociateCrmContact')->int('board_id')->int('contact_id');

    $router->get('/notification-settings', 'NotificationController@getBoardNotificationSettings')->int('board_id');
    $router->put('/update-notification-settings', 'NotificationController@updateBoardNotificationSettings')->int('board_id');

    $router->post('/duplicate-board', 'BoardController@duplicateBoard')->int('board_id');
    $router->post('/import-from-board', 'BoardController@importFromBoard')->int('board_id');

    $router->put('/upload/background', 'BoardController@setBoardBackground')->int('board_id');
    $router->post('/upload/background-image', 'BoardController@uploadBoardBackground')->int('board_id');
    $router->put('/archive-board', 'BoardController@archiveBoard')->int('board_id');
    $router->put('/restore-board', 'BoardController@restoreBoard')->int('board_id');



    //# Tasks under a single board routes
    //# Route prefix: /projects/{id}/tasks
    $router->prefix('/tasks')->group(function ($router) {
        $router->get('/', 'TaskController@getTasksByBoard')->int('board_id');
        $router->get('/by-stage', 'TaskController@getTasksByBoardStage')->int('board_id');
        $router->post('/', 'TaskController@create');
        $router->post('/create-task-from-image', 'TaskController@createTaskFromImage')->int('board_id');
        $router->get('/archived', 'TaskController@getArchivedTasks')->int('board_id');
        $router->get('/{task_id}', 'TaskController@find')->int('board_id')->int('task_id')->int('task_id');
        $router->put('/{task_id}', 'TaskController@updateTaskProperties')->int('board_id')->int('task_id');
        $router->post('/{task_id}/dates', 'TaskController@updateTaskDates')->int('board_id')->int('task_id');
        $router->put('/{task_id}/move-task', 'TaskController@moveTask')->int('board_id')->int('task_id');
        $router->post('/update-cover-photo/{task_id}', 'TaskController@updateTaskCoverPhoto')->int('task_id');
        $router->post('/status-update/{task_id}', 'TaskController@taskStatusUpdate')->int('task_id');
        $router->delete('/{task_id}', 'TaskController@deleteTask')->int('board_id')->int('task_id');
        $router->put('/{task_id}/move-to-next-stage', 'TaskController@moveTaskToNextStage')->int('board_id')->int('task_id');

        // Comments Routes Area
        $router->get('/{task_id}/comments', 'CommentController@getComments')->int('board_id')->int('task_id');
        $router->post('/{task_id}/comments', 'CommentController@create')->int('board_id')->int('task_id');
        $router->put('/comments/{comment_id}', 'CommentController@update')->int('board_id')->int('comment_id');
        $router->put('/reply/{reply_id}', 'CommentController@updateReply')->int('board_id')->int('reply_id');
        $router->delete('/comments/{comment_id}', 'CommentController@deleteComment')->int('board_id')->int('comment_id');
        $router->delete('/reply/{reply_id}', 'CommentController@deleteReply')->int('board_id')->int('reply_id');


        // Activities Area
        $router->get('/{task_id}/activities', 'TaskController@getActivities')->int('board_id')->int('task_id');

        $router->post('/{task_id}/assign-yourself', 'TaskController@assignYourselfInTask')->int('board_id')->int('task_id');
        $router->post('/{task_id}/detach-yourself', 'TaskController@detachYourselfFromTask')->int('board_id')->int('task_id');
        $router->get('/{task_id}/comments-and-activities', 'TaskController@getCommentsAndActivities')->int('board_id')->int('task_id');
        $router->post('/{task_id}/comment-image-upload', 'CommentController@handleImageUpload')->int('board_id')->int('task_id');
        $router->post('/{task_id}/task-cover-image-upload', 'TaskController@handleTaskCoverImageUpload')->int('board_id')->int('task_id');
        $router->post('/{task_id}/remove-task-cover', 'TaskController@removeTaskCover')->int('board_id')->int('task_id');
        $router->post('/{task_id}/wp-editor-media-file-upload', 'TaskController@uploadMediaFileFromWpEditor')->int('board_id')->int('task_id');
    });
});

$router->prefix('admin')->withPolicy('AdminPolicy')->group(function ($router) {

    $router->get('/feature-modules', 'OptionsController@getAddonsSettings');
    $router->post('/feature-modules', 'OptionsController@saveAddonsSettings');
    $router->post('/feature-modules/install-plugin', 'OptionsController@installPlugin');

    $router->get('/general-settings', 'OptionsController@getGeneralSettings');
    $router->post('/general-settings', 'OptionsController@saveGeneralSettings');

    $router->get('pages', 'OptionsController@getPages');

});

$router->prefix('webhooks')->withPolicy('WebhookPolicy')->group(function ($router) {
    $router->get('/', 'WebhookController@index');
    $router->post('/', 'WebhookController@create');
    $router->put('/{id}', 'WebhookController@update')->int('id');
    $router->delete('/{id}', 'WebhookController@delete')->int('id');
});


$router->prefix('member/{id}')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('/', 'UserController@getMemberInfo');
    $router->get('/projects', 'UserController@getMemberBoards');
    $router->get('/tasks', 'UserController@getMemberAssociatedTasks');
    $router->get('/activities', 'UserController@getMemberRelatedAcitivies');
});

/*
* TODO: I guess we can minimize the number of routes. and Backend code needs to be refactored
*/

$router->withPolicy('UserPolicy')->get('/all-notifications', 'NotificationController@getAllNotifications');
$router->withPolicy('UserPolicy')->get('/all-unread-notifications', 'NotificationController@getAllUnreadNotifications');
$router->withPolicy('UserPolicy')->get('notification/unread-count', 'NotificationController@newNotificationNumber');
$router->withPolicy('UserPolicy')->put('notification/read', 'NotificationController@readNotification');
$router->withPolicy('UserPolicy')->get('quick-search', 'OptionsController@quickSearch');
$router->withPolicy('UserPolicy')->get('contacts/{board_id}', 'TaskController@getAssociatedCrmContacts')->int('board_id');

$router->prefix('options')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('members', 'OptionsController@getBoardMembers');
    $router->get('projects', 'OptionsController@getBoards');
});
