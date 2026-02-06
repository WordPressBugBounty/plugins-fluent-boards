<?php
//if accessed directly exit
if (!defined('ABSPATH')) exit;

use FluentBoards\App\Http\Controllers\BoardController;
use FluentBoards\App\Http\Controllers\CommentController;
use FluentBoards\App\Http\Controllers\LabelController;
use FluentBoards\App\Http\Controllers\NotificationController;
use FluentBoards\App\Http\Controllers\OptionsController;
use FluentBoards\App\Http\Controllers\ReportController;
use FluentBoards\App\Http\Controllers\StageController;
use FluentBoards\App\Http\Controllers\TaskController;
use FluentBoards\App\Http\Controllers\UserController;
use FluentBoards\App\Http\Controllers\WebhookController;


/**
 * @var $router \FluentBoards\Framework\Http\Router
 */

$router->prefix('tasks')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('/top-in-boards', [TaskController::class, 'getTopTasksForBoards']);
    $router->get('/crm-associated-tasks/{associated_id}', [TaskController::class, 'getAssociatedTasks'])->int('associated_id');
    $router->get('/stage/{task_id}', [TaskController::class, 'getStageByTask']); //FUTURE: this api need to be relocated

    $router->get('/boards-by-type/{type}', [BoardController::class, 'getBoardsByType']);
    $router->get('/{task_id}/labels', [TaskController::class, 'getLabelsByTask']);

    // Task tabs configuration
    $router->get('/task-tabs/config', [TaskController::class, 'getTaskTabsConfig']);
    $router->post('/task-tabs/config', [TaskController::class, 'saveTaskTabsConfig']);
});

$router->withPolicy('BoardUserPolicy')->group(function ($router) {

    $router->get('/quick-search', [OptionsController::class, 'quickSearch']);

    $router->get('/member-associated-users/{id}', [UserController::class, 'memberAssociatedTaskUsers']);
    $router->get('ajax-options', [OptionsController::class, 'selectorOptions']);
    $router->post('update-global-notification-settings', [OptionsController::class, 'updateGlobalNotificationSettings']);
    $router->get('get-global-notification-settings', [OptionsController::class, 'getGlobalNotificationSettings']);
    $router->put('update-dashboard-view-settings', [OptionsController::class, 'updateDashboardViewSettings']);
    $router->get('get-dashboard-view-settings', [OptionsController::class, 'getDashboardViewSettings']);
    $router->get('projects/reports', [ReportController::class, 'getBoardReports']);
    $router->get('reports/timesheet', [ReportController::class, 'getTimeSheetReport']);

});

$router->prefix('projects')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('/', [BoardController::class, 'getBoards']);
    $router->post('/', [BoardController::class, 'create']);
    $router->get('/get-default-board-colors', [BoardController::class, 'getBoardDefaultBackgroundColors']);
    $router->get('/list-of-boards', [BoardController::class, 'getBoardsList']); // it is using for to get all boards by user
    $router->get('/user-accessible-boards', [BoardController::class, 'getOnlyBoardsByUser']);
    $router->get('/crm-associated-boards/{id}', [BoardController::class, 'getAssociatedBoards'])->int('associated_id');
    $router->get('/currencies', [BoardController::class, 'getCurrencies']);
    $router->get('/user-admin-in-boards', [BoardController::class, 'getUsersOfBoards']);
    $router->get('/recent-boards', [BoardController::class, 'getRecentBoards']);
    $router->get('/pinned-boards', [BoardController::class, 'getPinnedBoards']);

    $router->post('/onboard', [BoardController::class, 'createFirstBoard']);
    $router->put('/skip-onboarding', [BoardController::class, 'skipOnboarding']);


});

$router->prefix('projects/{board_id}')->withPolicy('SingleBoardPolicy')->group(function ($router) {
    $router->get('/', [BoardController::class, 'find'])->int('board_id');
    $router->get('/has-data-changed', [BoardController::class, 'hasDataChanged'])->int('board_id');
    $router->put('/update-board-properties', [BoardController::class, 'updateBoardProperties'])->int('board_id');
    $router->put('/', [BoardController::class, 'update'])->int('board_id');
    $router->delete('/', [BoardController::class, 'delete'])->int('board_id');
    $router->get('/labels', [LabelController::class, 'getLabelsByBoard']);
    $router->post('/labels', [LabelController::class, 'createLabel']);
    $router->get('/labels/used-in-tasks', [LabelController::class, 'getLabelsByBoardUsedInTasks']);
    $router->get('/tasks/{task_id}/labels', [LabelController::class, 'getLabelsByTask']); //FUTURE: these api need to be relocated
    $router->post('/labels/task', [LabelController::class, 'createLabelForTask']);
    $router->put('/labels/{label_id}', [LabelController::class, 'editLabelofBoard']);
    $router->delete('/labels/{label_id}', [LabelController::class, 'deleteLabelOfBoard']);
    $router->delete('/tasks/{task_id}/labels/{label_id}', [LabelController::class, 'deleteLabelOfTask']);
    $router->put('/pin-board', [BoardController::class, 'pinBoard']);
    $router->put('/unpin-board', [BoardController::class, 'unpinBoard']);

    $router->get('/users', [BoardController::class, 'getBoardUsers']);
    $router->post('/user/{user_id}/remove', [BoardController::class, 'removeUserFromBoard'])->int('board_id')->int('user_id');
    $router->post('/add-members', [BoardController::class, 'addMembersInBoard']);

    $router->get('/assignees', [BoardController::class, 'getAssigneesByBoard'])->int('board_id');
    $router->get('/activities', [BoardController::class, 'getActivities'])->int('board_id');

    $router->put('/stage-move-all-task', [BoardController::class, 'moveAllTasks'])->int('board_id');
    $router->post('/stage-create', [BoardController::class, 'createStage'])->int('board_id');
    $router->put('/stage/{stage_id}/sort-task', [StageController::class, 'sortStageTasks'])->int('board_id')->int('stage_id');
    $router->put('/stage/{stage_id}/archive-all-task', [BoardController::class, 'archiveAllTasksInStage'])->int('board_id')->int('stage_id');
    $router->put('/re-position-stages', [BoardController::class, 'repositionStages'])->int('board_id');
    $router->put('/update-stage/{stage_id}', [StageController::class, 'updateStage'])->int('board_id'); //Todo:: will delete later
    $router->put('/update-stage-property/{stage_id}', [StageController::class, 'updateStageProperty'])->int('board_id');
    $router->put('/archive-stage/{stage_id}', [BoardController::class, 'archiveStage'])->int('board_id');
    $router->put('/stage-view/{stage_id}', [BoardController::class, 'changeStageView'])->int('board_id');
    $router->put('/restore-stage/{stage_id}', [BoardController::class, 'restoreStage'])->int('board_id');
    $router->put('/drag-stage', [StageController::class, 'dragStage'])->int('board_id');
    $router->get('/archived-stages', [BoardController::class, 'getArchivedStage'])->int('board_id');
    $router->get('/archived-tasks', [TaskController::class, 'getArchivedTasks'])->int('board_id');
    $router->put('/bulk-restore-tasks', [TaskController::class, 'bulkRestoreTasks'])->int('board_id');
    $router->delete('/bulk-delete-tasks', [TaskController::class, 'bulkDeleteTasks'])->int('board_id');
    $router->get('/stage-task-available-positions/{stage_id}', [BoardController::class, 'getStageTaskAvailablePositions'])->int('board_id')->int('stage_id');

    $router->post('/crm-contact', [BoardController::class, 'updateAssociateCrmContact'])->int('board_id');
    $router->get('/crm-contacts', [BoardController::class, 'getAssociateCrmContacts'])->int('board_id');
    $router->delete('/crm-contact/{contact_id}', [BoardController::class, 'deleteAssociateCrmContact'])->int('board_id')->int('contact_id');

    $router->get('/notification-settings', [NotificationController::class, 'getBoardNotificationSettings'])->int('board_id');
    $router->put('/update-notification-settings', [NotificationController::class, 'updateBoardNotificationSettings'])->int('board_id');

    $router->post('/duplicate-board', [BoardController::class, 'duplicateBoard'])->int('board_id');
    $router->post('/import-from-board', [BoardController::class, 'importFromBoard'])->int('board_id');

    $router->put('/upload/background', [BoardController::class, 'setBoardBackground'])->int('board_id');
    $router->post('/upload/background-image', [BoardController::class, 'uploadBoardBackground'])->int('board_id');
    $router->put('/archive-board', [BoardController::class, 'archiveBoard'])->int('board_id');
    $router->put('/restore-board', [BoardController::class, 'restoreBoard'])->int('board_id');
    $router->get('/board-menu-items', [BoardController::class, 'getBoardMenuItems'])->int('board_id');
    $router->get('/stage-wise-reports', [ReportController::class, 'getStageWiseBoardReports'])->int('board_id');


    //# Tasks under a single board routes
    //# Route prefix: /projects/{id}/tasks
    $router->prefix('/tasks')->group(function ($router) {
        $router->get('/', [TaskController::class, 'getTasksByBoard'])->int('board_id');
        $router->get('/by-stage', [TaskController::class, 'getTasksByBoardStage'])->int('board_id');
        $router->post('/', [TaskController::class, 'create']);
        $router->post('/create-task-from-image', [TaskController::class, 'createTaskFromImage'])->int('board_id');
        $router->get('/archived', [TaskController::class, 'getArchivedTasks'])->int('board_id');
        $router->get('/{task_id}', [TaskController::class, 'find'])->int('board_id')->int('task_id')->int('task_id');
        $router->put('/{task_id}', [TaskController::class, 'updateTaskProperties'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/dates', [TaskController::class, 'updateTaskDates'])->int('board_id')->int('task_id');
        $router->put('/{task_id}/move-task', [TaskController::class, 'moveTask'])->int('board_id')->int('task_id');
        $router->post('/bulk-actions', [TaskController::class, 'bulkActions'])->int('board_id');
        $router->post('/update-cover-photo/{task_id}', [TaskController::class, 'updateTaskCoverPhoto'])->int('task_id');
        $router->post('/status-update/{task_id}', [TaskController::class, 'taskStatusUpdate'])->int('task_id');
        $router->delete('/{task_id}', [TaskController::class, 'deleteTask'])->int('board_id')->int('task_id');
        $router->put('/{task_id}/move-to-next-stage', [TaskController::class, 'moveTaskToNextStage'])->int('board_id')->int('task_id');

        // Comments Routes Area
        $router->get('/{task_id}/comments', [CommentController::class, 'getComments'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/comments', [CommentController::class, 'create'])->int('board_id')->int('task_id');
        $router->put('/comments/{comment_id}', [CommentController::class, 'update'])->int('board_id')->int('comment_id');
        $router->put('/reply/{reply_id}', [CommentController::class, 'updateReply'])->int('board_id')->int('reply_id');
        $router->delete('/comments/{comment_id}', [CommentController::class, 'deleteComment'])->int('board_id')->int('comment_id');
        $router->delete('/reply/{reply_id}', [CommentController::class, 'deleteReply'])->int('board_id')->int('reply_id');
        $router->put('/comments/{comment_id}/privacy', [CommentController::class, 'updateCommentPrivacy'])->int('board_id')->int('comment_id');


        // Activities Area
        $router->get('/{task_id}/activities', [TaskController::class, 'getActivities'])->int('board_id')->int('task_id');

        $router->post('/{task_id}/assign-yourself', [TaskController::class, 'assignYourselfInTask'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/detach-yourself', [TaskController::class, 'detachYourselfFromTask'])->int('board_id')->int('task_id');
        $router->get('/{task_id}/comments-and-activities', [TaskController::class, 'getCommentsAndActivities'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/comment-image-upload', [CommentController::class, 'handleImageUpload'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/task-cover-image-upload', [TaskController::class, 'handleTaskCoverImageUpload'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/remove-task-cover', [TaskController::class, 'removeTaskCover'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/wp-editor-media-file-upload', [TaskController::class, 'uploadMediaFileFromWpEditor'])->int('board_id')->int('task_id');
        $router->post('/{task_id}/clone-task', [TaskController::class, 'cloneTask'])->int('board_id')->int('task_id');
    });
});

$router->prefix('admin')->withPolicy('AdminPolicy')->group(function ($router) {

    $router->get('/feature-modules', [OptionsController::class, 'getAddonsSettings']);
    $router->post('/feature-modules', [OptionsController::class, 'saveAddonsSettings']);
    $router->post('/feature-modules/install-plugin', [OptionsController::class, 'installPlugin']);

    $router->get('/general-settings', [OptionsController::class, 'getGeneralSettings']);
    $router->post('/general-settings', [OptionsController::class, 'saveGeneralSettings']);

    $router->get('pages', [OptionsController::class, 'getPages']);

});

$router->prefix('webhooks')->withPolicy('WebhookPolicy')->group(function ($router) {
    $router->get('/', [WebhookController::class, 'index']);
    $router->post('/', [WebhookController::class, 'create']);
    $router->put('/{id}', [WebhookController::class, 'update'])->int('id');
    $router->delete('/{id}', [WebhookController::class, 'delete'])->int('id');
});

// Add outgoing webhook routes
$router->prefix('outgoing-webhooks')->withPolicy('WebhookPolicy')->group(function ($router) {
    $router->get('/', [WebhookController::class, 'outgoingWebhooks']);
    $router->post('/', [WebhookController::class, 'createOutgoingWebhook']);
    $router->put('/{id}', [WebhookController::class, 'updateOutgoingWebhook'])->int('id');
    $router->delete('/{id}', [WebhookController::class, 'deleteOutgoingWebhook'])->int('id');
});


$router->prefix('member/{id}')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('/', [UserController::class, 'getMemberInfo']);
    $router->get('/projects', [UserController::class, 'getMemberBoards']);
    $router->get('/tasks', [UserController::class, 'getMemberAssociatedTasks']);
    $router->get('/activities', [UserController::class, 'getMemberRelatedAcitivies']);
});

/*
* TODO: I guess we can minimize the number of routes. and Backend code needs to be refactored
*/

// Notification routes
$router->prefix('notifications')->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('/', [NotificationController::class, 'getAllNotifications']);
    $router->get('/unread', [NotificationController::class, 'getAllUnreadNotifications']);
    $router->get('/unread-count', [NotificationController::class, 'newNotificationNumber']);
    $router->put('/read', [NotificationController::class, 'readNotification']);
});

$router->prefix('contacts/{board_id}')->withPolicy('SingleBoardPolicy')->group(function ($router) {
    $router->get('/', [TaskController::class, 'getAssociatedCrmContacts'])->int('board_id');
});

// User utility routes
$router->withPolicy('UserPolicy')->group(function ($router) {
    $router->get('/quick-search', [OptionsController::class, 'quickSearch']);
});


$router->prefix('options')->withPolicy('AuthPolicy')->group(function ($router) {
    $router->get('members', [OptionsController::class, 'getBoardMembers']);
    $router->get('projects', [OptionsController::class, 'getBoards']);
});
