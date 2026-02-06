<?php
//if accessed directly exit
if (!defined('ABSPATH')) exit;

use FluentBoards\App\Services\Helper;

/**
 * All registered action's handlers should be in app\Hooks\Handlers,
 * addAction is similar to add_action and addCustomAction is just a
 * wrapper over add_action which will add a prefix to the hook name
 * using the plugin slug to make it unique in all WordPress plugins,
 * ex: $app->addCustomAction('foo', ['FooHandler', 'handleFoo']) is
 * equivalent to add_action('slug-foo', ['FooHandler', 'handleFoo']).
 */

/**
 * @var $app FluentBoards\Framework\Foundation\Application
 */

(new \FluentBoards\App\Hooks\Handlers\AdminMenuHandler())->register();

//$app->addCustomAction('task_prop_changed', 'ActivityHandler@logActivity', 10, 3);
$app->addCustomAction('task_board_changed', 'ActivityHandler@logMoveTaskToAnotherBoardActivity', 10, 2); //will call from service after board change code merged
$app->addCustomAction('task_due_date_changed', 'ActivityHandler@logDueDateActivity', 10, 2);
$app->addCustomAction('task_due_date_removed', 'ActivityHandler@logDueDateRemoveActivity', 10, 1);
$app->addCustomAction('task_start_date_changed', 'ActivityHandler@logStartDateActivity', 10, 2);

$app->addCustomAction('task_priority_changed', 'ActivityHandler@logPriorityChangeActivity', 10, 2);
$app->addCustomAction('comment_created', 'ActivityHandler@logCommentCreateActivity', 10, 1);
$app->addCustomAction('comment_updated', 'ActivityHandler@logCommentUpdateActivity', 10, 2);
$app->addCustomAction('comment_deleted', 'ActivityHandler@logCommentDeleteActivity', 10, 1);
$app->addCustomAction('subtask_added', 'ActivityHandler@logSubtaskAddedActivity', 10, 2);
$app->addCustomAction('subtask_cloned', 'ActivityHandler@logSubtaskCloneActivity', 10, 2);
$app->addCustomAction('subtask_group_created', 'ActivityHandler@logSubtaskGroupAddedActivity', 10, 2);
$app->addCustomAction('subtask_deleted_activity', 'ActivityHandler@logSubtaskDeletedActivity', 10, 2);
$app->addCustomAction('subtask_group_deleted_activity', 'ActivityHandler@logSubtaskGroupDeletedActivity', 10, 2);
$app->addCustomAction('subtask_group_title_updated', 'ActivityHandler@logSubtaskGroupTitleUpdatedActivity', 10, 2);
$app->addCustomAction('task_completed_activity', 'ActivityHandler@logTaskCompletedOrReopenActivity', 10, 2);
$app->addCustomAction('task_stage_updated', 'ActivityHandler@logTaskStageUpdatedActivity', 10, 2);
$app->addCustomAction('task_assignee_added', 'ActivityHandler@logAssigneeAddedActivity', 10, 2);
$app->addCustomAction('task_assignee_removed', 'ActivityHandler@logAssigneeRemovedActivity', 10, 2);
$app->addCustomAction('task_added_from_fluent_form', 'ActivityHandler@taskAddedFromFluentForms', 10, 1);
$app->addCustomAction('repeat_task_created', 'ActivityHandler@logRepeatTaskCreatedActivity', 10, 2);
$app->addCustomAction('repeat_task_set', 'ActivityHandler@logRepeatTaskSet', 10, 1);
$app->addCustomAction('repeat_task_updated', 'ActivityHandler@logRepeatTaskUpdated', 10, 1);
$app->addCustomAction('task_created', 'ActivityHandler@logTaskCreationActivity', 10, 1);
$app->addCustomAction('task_content_updated', 'ActivityHandler@logTaskContentUpdatedActivity', 10, 3);
$app->addCustomAction('task_deleted', 'TaskHandler@taskDeleted', 10, 1);
$app->addCustomAction('task_archived', 'ActivityHandler@taskArchived', 10, 1);
//$app->addCustomAction('task_custom_field_changed', 'ActivityHandler@logCustomFieldActivity', 10, 5); // commented for now, will implement later

$app->addCustomAction('board_created', 'BoardHandler@boardCreated', 10, 1);
$app->addCustomAction('before_board_deleted', 'BoardHandler@beforeBoardDeleted', 10, 2);
$app->addCustomAction('board_deleted', 'BoardHandler@boardDeleted', 10, 1);
$app->addCustomAction('board_updated', 'BoardHandler@boardUpdated', 10, 2);
$app->addCustomAction('stage_updated', 'BoardHandler@boardStageUpdated', 10, 3);
$app->addCustomAction('board_stage_moved', 'BoardHandler@boardStageMoved', 10, 2);
$app->addCustomAction('board_stage_added', 'BoardHandler@boardStageAdded', 10, 2);
$app->addCustomAction('stage_deleted', 'BoardHandler@boardStageDeleted', 10, 2);
$app->addCustomAction('stage_archived', 'BoardHandler@boardStageArchived', 10, 2);
$app->addCustomAction('board_stage_restored', 'BoardHandler@boardArchivedStageRestore', 10, 2);
$app->addCustomAction('task_created', 'BoardHandler@taskCreatedOnBoard', 10, 1);
$app->addCustomAction('before_task_deleted', 'BoardHandler@beforeTaskDeleted', 10, 2);
$app->addCustomAction('task_deleted', 'BoardHandler@taskdeleted', 10, 1);
$app->addCustomAction('task_moved_from_board', 'BoardHandler@taskMovedFromBoard', 10, 3);
$app->addCustomAction('task_moved_from_board', 'ActivityHandler@taskMovedFromBoard', 10, 3);
$app->addCustomAction('task_archived', 'BoardHandler@taskArchivedOnBoard', 10, 1);
$app->addCustomAction('board_member_added', 'BoardHandler@boardMemberAdded', 10, 2);
$app->addCustomAction('board_viewer_added', 'BoardHandler@boardViewerAdded', 10, 2);
$app->addCustomAction('board_member_removed', 'BoardHandler@boardMemberRemoved', 10, 2);
$app->addCustomAction('board_admin_added', 'BoardHandler@boardAdminAdded', 10, 2);
$app->addCustomAction('board_admin_removed', 'BoardHandler@boardAdminRemoved', 10, 2);
$app->addCustomAction('associate_user_add_change_remove_activity', 'ActivityHandler@associateUserAddChangeRemoveActivity', 10, 3);
$app->addCustomAction('task_assignee_leave', 'ActivityHandler@taskAssigneeLeave', 10, 1);
$app->addCustomAction('task_assignee_join', 'ActivityHandler@taskAssigneeJoin', 10, 1);
$app->addCustomAction('task_label', 'ActivityHandler@taskLabelActivity', 10, 3);
$app->addCustomAction('label_manage_for_task_activity', 'ActivityHandler@labelManageForTaskActivity', 10, 2);
$app->addCustomAction('board_label_created', 'BoardHandler@boardLabelCreatedActivity', 10, 1);
$app->addCustomAction('board_label_updated', 'BoardHandler@boardLabelUpdatedActivity', 10, 1);
$app->addCustomAction('board_label_deleted', 'BoardHandler@boardLabelDeletedActivity', 10, 1);
$app->addCustomAction('board_stages_reordered', 'BoardHandler@boardStagesReOrdered', 10, 2);
$app->addCustomAction('default_assignees_updated', 'BoardHandler@defaultAssigneesUpdated', 10, 2);

$app->addCustomAction('comment_created', 'NotificationHandler@addCommentNotification', 10, 1);
$app->addCustomAction('mention_comment_notification', 'NotificationHandler@mentionInCommentNotification', 10, 2);
$app->addCustomAction('subtask_added', 'NotificationHandler@addSubtaskNotification', 10, 2);
$app->addCustomAction('task_due_date_changed', 'NotificationHandler@changeDueDateNotification', 10, 2);
$app->addCustomAction('task_start_date_changed', 'NotificationHandler@changeStartDateNotification', 10, 2);
$app->addCustomAction('task_stage_updated', 'NotificationHandler@changeStageNotification', 10, 2);
$app->addCustomAction('task_priority_changed', 'NotificationHandler@changePriorityNotification', 10, 2);
$app->addCustomAction('task_archived', 'NotificationHandler@taskArchiveNotification', 10, 1);
$app->addCustomAction('task_content_updated', 'NotificationHandler@changeTitleOrDescriptionNotification', 10, 3);
//$app->addCustomAction('description_changed_notification', 'NotificationHandler@changeDescriptionNotification', 10, 1);
$app->addCustomAction('board_changed_notification', 'NotificationHandler@changeBoardNotification', 10, 2);
$app->addCustomAction('task_assignee_added', 'NotificationHandler@assigneeAddedNotification', 10, 2);
$app->addCustomAction('task_assignee_removed', 'NotificationHandler@assigneeRemovedNotification', 10, 2);

$app->addCustomAction('one_time_schedule_send_email_for_comment', 'ScheduleHandler@sendEmailForComment', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_mention', 'ScheduleHandler@sendEmailForMention', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_add_assignee', 'ScheduleHandler@sendEmailForAddAssignee', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_remove_assignee', 'ScheduleHandler@sendEmailForRemoveAssignee', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_stage_change', 'ScheduleHandler@sendEmailForChangeStage', 10, 3);

//Todo:: update handler
$app->addCustomAction('one_time_schedule_send_email_for_due_date_update', 'ScheduleHandler@sendEmailForDueDateUpdate', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_task_archived', 'ScheduleHandler@sendEmailForArchivedTask', 10, 3);
$app->addCustomAction('one_time_schedule_send_email_for_removed_from_task', 'ScheduleHandler@sendEmailForChangeStage', 10, 3);

$app->addCustomAction('task_attachment_added', 'ActivityHandler@taskAttachmentAdded', 10, 1);
$app->addCustomAction('task_attachment_deleted', 'ActivityHandler@taskAttachmentDeleted', 10, 1);
$app->addCustomAction('task_attachment_deleted', 'TaskHandler@taskAttachmentDeleted', 10, 1);
// Removed: direct call is made from Comment::deleting to TaskHandler@commentImageDeleted
$app->addAction('deleted_user', 'BoardHandler@deleteUserRelatedData', 10, 3);
$app->addAction('delete_attachment', 'FileHandler@mediaFileDeleted', 10, 1);

$app->addCustomAction('task_created', 'TaskHandler@onTaskCreated', 10, 1);
$app->addCustomAction('comment_created', 'TaskHandler@onCommentCreated', 10, 1);
$app->addCustomAction('assign_another_user', 'TaskHandler@onAssignAnotherUser', 10, 2);
$app->addCustomAction('board_background_updated', 'BoardHandler@backgroundUpdated', 10, 2);
$app->addCustomAction('task_cloned', 'TaskHandler@taskCloned', 10, 2);
$app->addCustomAction('task_cloned_activity', 'ActivityHandler@taskCloned', 10, 2);

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('fluent_boards', '\FluentBoards\App\Hooks\Cli\Commands');
}


add_action('init', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing parameter check, no data modification
    if (!isset($_REQUEST['fbs'])) {
        return;
    }
});

/*
 * IMPORTANT
 * External Pages Handler
 * Each Request must have fbs=1 as a query parameter, then the plugin will handle the request.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing logic, security validated in individual handlers
if(isset($_GET['fbs']) && $_GET['fbs'] == 1) {

    // For viewing attachment
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing logic, security validated in handler
    if(isset($_GET['route']) && ($_GET['route'] == 'task')) {
        add_action('init', function() {
            (new \FluentBoardsPro\App\Hooks\Handlers\ExternalPages())->handleTaskWebhook();
        });
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public file serving endpoint, security validated by hash in handler
    if(isset($_GET['fbs_comment_image'])) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public routing check, security validated in handler
        if(isset($_GET['fbs_type']) == 'public_url') {
            add_action('init', function() {
                (new \FluentBoards\App\Hooks\Handlers\ExternalPages())->view_uploaded_comment_image();
            });
        } else {
            add_action('init', function() {
                (new \FluentBoards\App\Hooks\Handlers\ExternalPages())->view_comment_image();
            });
        }

    }

}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public redirect endpoint, no sensitive operations
if(isset($_GET['redirect']) && $_GET['redirect'] == 'to_task') {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public redirect endpoint, validation performed in handler
    if (isset($_GET['taskId'])) {
        add_action('init', function() {
            (new \FluentBoards\App\Hooks\Handlers\ExternalPages())->redirectToPage();
        });
    }
}

