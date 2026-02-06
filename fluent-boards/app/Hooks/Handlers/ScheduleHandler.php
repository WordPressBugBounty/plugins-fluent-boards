<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;

class ScheduleHandler
{
    public function sendEmailForComment(
        $commentId,
        $usersToSendEmail,
        $current_user_id
    ) {
        try {
            $comment = Comment::find($commentId) ?? null;
            if ( ! $comment) {
                return;
            }
            if (!in_array($comment->type, ['comment', 'reply'])) {
                return;
            }

            $task = Task::findOrFail($comment->task_id);
            if ( ! $task) {
                return;
            }

            $board    = $task->board;

            $page_url = site_url('/') . '?redirect=to_task&taskId='.$task->id;

            $user = $task->user($comment->created_by);

            $userData = $this->getUserData($current_user_id);

            $boardUrl = $page_url.'boards/'.$board->id;
            $taskUrl  = $page_url.'boards/'.$board->id.'/tasks/'.$task->id.'-'
                        .substr($task->title, 0, 10);

            $userLinkTag  = '<strong>'.htmlspecialchars($user->display_name)
                            .'</strong>';
            $taskLinkTag  = '<a target="_blank" href="'
                            .htmlspecialchars($taskUrl).'">'
                            .htmlspecialchars($task->title).'</a>';
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            // Consolidated translation with placeholders
            // translators: %1$s is the task link, %2$s is the board link
            $preparedBody = sprintf(__('commented on %1$s on %2$s board.', 'fluent-boards'), $taskLinkTag, $boardLinkTag);
            $preHeader = __('New comment has been added on task','fluent-boards');
            $mailSubject = __('New comment has been added on task','fluent-boards');


            if ($comment->type == 'reply')
            {
                // translators: %1$s is the task link, %2$s is the board link
                $preparedBody = sprintf(__('replied on your comment on %1$s on %2$s board.', 'fluent-boards'), $taskLinkTag, $boardLinkTag);

                $preHeader = __('A reply has been added to your comment on a task.','fluent-boards');
                $mailSubject = __('New Reply on Your Task Comment','fluent-boards');
            }

            $data = [
                'body'        => $preparedBody,
                'comment_link' => $page_url,
                'pre_header'  => $preHeader,
                'show_footer' => true,
                'comment'     => $comment->description,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];

            $message     = Helper::loadView('emails.comment2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($usersToSendEmail as $email) {
                \wp_mail($email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            // do nothing // better to log here
        }
    }

    public function sendEmailForMention($commentId, $usersToSendEmail, $current_user_id)
    {
        try {
            $comment = Comment::find($commentId) ?? null;
            if ( ! $comment) {
                return;
            }

            if (!in_array($comment->type, ['comment', 'reply'])) {
                return;
            }

            $task = Task::findOrFail($comment->task_id);
            if ( ! $task) {
                return;
            }
//            $assignees = $task->assignees;
            $board    = $task->board;

            $page_url = site_url('/') . '?redirect=to_task&taskId='.$task->id;

            $user = $task->user($comment->created_by);

            $userData = $this->getUserData($current_user_id);

            $boardUrl = $page_url.'boards/'.$board->id;
            $taskUrl  = $page_url.'boards/'.$board->id.'/tasks/'.$task->id.'-'
                .substr($task->title, 0, 10);

            $userLinkTag  = '<strong>'.htmlspecialchars($user->display_name)
                .'</strong>';
            $taskLinkTag  = '<a target="_blank" href="'
                .htmlspecialchars($taskUrl).'">'
                .htmlspecialchars($task->title).'</a>';
            $boardLinkTag = '<a target="_blank" href="'
                .htmlspecialchars($boardUrl).'">'
                .htmlspecialchars($board->title).'</a>';

            $commentLink = $taskUrl . '?comment='.$comment->id;

            // translators: %1$s is the task link, %2$s is the board link
            $bodyText = sprintf(__('mentioned you in a comment on %1$s on %2$s board.', 'fluent-boards'), $taskLinkTag, $boardLinkTag);

            $data = [
                'body'        => $bodyText,
                'comment_link' => $page_url,
                'pre_header'  => __('You are mentioned in a comment','fluent-boards'),
                'show_footer' => true,
                'comment'     => $comment->description,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];

            $mailSubject = __('You are mentioned in a comment','fluent-boards');
            $message     = Helper::loadView('emails.comment2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($usersToSendEmail as $email) {
                \wp_mail($email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            // do nothing // better to log here
        }
    }

    public function sendEmailForAddAssignee(
        $taskId,
        $newAssigneeId,
        $current_user_id
    ) {
        try {
            $task     = Task::findOrFail($taskId);
            $board    = $task->board;
            $assignee = User::findOrFail($newAssigneeId);

            $userData = $this->getUserData($current_user_id);

            $page_url     = fluent_boards_page_url();
            $boardUrl     = $page_url.'boards/'.$board->id;
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            if (null == $task->parent_id) {
                // this is a task
                $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'
                               .$task->id.'-'.substr($task->title, 0, 10);
                $taskLinkTag = '<a target="_blank" href="'
                               .htmlspecialchars($taskUrl).'">'
                               .htmlspecialchars($task->title).'</a>';
                // translators: %1$s is the task link, %2$s is the board link
                $bodyText = sprintf(__('has assigned you to task %1$s on %2$s board.', 'fluent-boards'), $taskLinkTag, $boardLinkTag);
                
                $data        = [
                    'body'        => $bodyText,
                    'pre_header'  => __('you have been assigned to task','fluent-boards'),
                    'show_footer' => true,
                    'userData'    => $userData,
                    'site_url'    => site_url(),
                    'site_title'  => get_bloginfo('name'),
                    'site_logo'   => fluent_boards_site_logo(),
                ];
            } else {
                // this is a subtask
                $task = $task->parentTask($task->parent_id);

                $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'
                               .$task->id.'-'.substr($task->title, 0, 10);
                $taskLinkTag = '<a target="_blank" href="'
                               .htmlspecialchars($taskUrl).'">'
                               .htmlspecialchars($task->title).'</a>';

                // translators: %1$s is the subtask title, %2$s is the task link, %3$s is the board link
                $bodyText = sprintf(__('has assigned you to subtask <strong>%1$s</strong> of task %2$s on the board %3$s', 'fluent-boards'), $task->title, $taskLinkTag, $boardLinkTag);

                $data = [
                    'body'        => $bodyText,
                    'pre_header'  => __('you have been assigned to subtask','fluent-boards'),
                    'show_footer' => true, 'user' => $assignee,
                    'userData'    => $userData,
                    'site_url'    => site_url(),
                    'site_title'  => get_bloginfo('name'),
                    'site_logo'   => fluent_boards_site_logo(),
                ];
            }

            $mailSubject = __('You have been assigned to task','fluent-boards');
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];


            \wp_mail($assignee->user_email, $mailSubject, $message, $headers);

        } catch (\Exception $e) {
            throw new \Exception(esc_html__('Error in sending mail to new assignees', 'fluent-boards'), 1);
        }
    }

    public function sendEmailForRemoveAssignee(
        $taskId,
        $newAssigneeId,
        $current_user_id
    ) {
        try {
            $task     = Task::findOrFail($taskId);
            $board    = $task->board;
            $assignee = User::findOrFail($newAssigneeId);

            $userData = $this->getUserData($current_user_id);

            $page_url = fluent_boards_page_url();

            $boardUrl     = $page_url.'boards/'.$board->id;
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            if (null == $task->parent_id) {
                // this is a task
                $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'
                               .$task->id.'-'.substr($task->title, 0, 10);
                $taskLinkTag = '<a target="_blank" href="'
                               .htmlspecialchars($taskUrl).'">'
                               .htmlspecialchars($task->title).'</a>';
                // translators: %1$s is the task link, %2$s is the board link
                $bodyText = sprintf(__('has removed you from task %1$s on the board %2$s', 'fluent-boards'), $taskLinkTag, $boardLinkTag);
                
                $data        = [
                    'body'        => $bodyText,
                    'pre_header'  => __('you have been removed from task','fluent-boards'),
                    'show_footer' => true,
                    'userData'    => $userData,
                    'site_url'    => site_url(),
                    'site_title'  => get_bloginfo('name'),
                    'site_logo'   => fluent_boards_site_logo(),
                ];
            } else {
                // this is a subtask
                $task = $task->parentTask($task->parent_id);

                $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'
                               .$task->id.'-'.substr($task->title, 0, 10);
                $taskLinkTag = '<a target="_blank" href="'
                               .htmlspecialchars($taskUrl).'">'
                               .htmlspecialchars($task->title).'</a>';

                // translators: %1$s is the subtask title, %2$s is the task link, %3$s is the board link
                $bodyText = sprintf(__('has removed you from subtask <strong>%1$s</strong> of task %2$s on the board %3$s', 'fluent-boards'), $task->title, $taskLinkTag, $boardLinkTag);

                $data = [
                    'body'        => $bodyText,
                    'pre_header'  => __('you have been removed from subtask','fluent-boards'),
                    'show_footer' => true,
                    'userData'    => $userData,
                ];
            }

            $mailSubject = __('You have been removed from task','fluent-boards');
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            \wp_mail($assignee->user_email, $mailSubject, $message, $headers);

        } catch (\Exception $e) {
            throw new \Exception(esc_html__('Error in sending mail to new assignees', 'fluent-boards'), 1);
        }
    }


    public function sendEmailForChangeStage(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url     = fluent_boards_page_url();
            $boardUrl     = $page_url.'boards/'.$board->id;
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'.$task->id
                           .'-'.substr($task->title, 0, 10);
            $taskLinkTag = '<a target="_blank" href="'
                           .htmlspecialchars($taskUrl).'">'
                           .htmlspecialchars($task->title).'</a>';

            // translators: %1$s is the task link, %2$s is the stage title, %3$s is the board link
            $bodyText = sprintf(__('has moved %1$s task to <strong>%2$s</strong> stage of board %3$s', 'fluent-boards'), $taskLinkTag, $task->stage->title, $boardLinkTag);

            $data = [
                'body'        => $bodyText,
                'pre_header'  => __('Task stage has been changed','fluent-boards'),
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = __('Task stage has been changed','fluent-boards');
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            // Silent fail for email sending
        }
    }

    public function sendEmailForDueDateUpdate(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url     = fluent_boards_page_url();
            $boardUrl     = $page_url.'boards/'.$board->id;
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'.$task->id
                           .'-'.substr($task->title, 0, 10);
            $taskLinkTag = '<a target="_blank" href="'
                           .htmlspecialchars($taskUrl).'">'
                           .htmlspecialchars($task->title).'</a>';

            // translators: %1$s is the task link, %2$s is the due date, %3$s is the board link
            $bodyText = sprintf(__('has updated due date of %1$s task to <strong>%2$s</strong> of board %3$s', 'fluent-boards'), $taskLinkTag, $task->due_at, $boardLinkTag);

            $data = [
                'body'        => $bodyText,
                'pre_header'  => __('Task due date has been changed','fluent-boards'),
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = __('Task due date has been changed','fluent-boards');
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception(esc_html__('Error in sending mail to new assignees', 'fluent-boards'), 1);
        }
    }

    public function sendEmailForArchivedTask(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url     = fluent_boards_page_url();
            $boardUrl     = $page_url.'boards/'.$board->id;
            $boardLinkTag = '<a target="_blank" href="'
                            .htmlspecialchars($boardUrl).'">'
                            .htmlspecialchars($board->title).'</a>';

            $taskUrl     = $page_url.'boards/'.$board->id.'/tasks/'.$task->id
                           .'-'.substr($task->title, 0, 10);
            $taskLinkTag = '<a target="_blank" href="'
                           .htmlspecialchars($taskUrl).'">'
                           .htmlspecialchars($task->title).'</a>';

            // translators: %1$s is the task link, %2$s is the board link
            $bodyText = sprintf(__('has archived %1$s task of board %2$s', 'fluent-boards'), $taskLinkTag, $boardLinkTag);

            $data = [
                'body'        => $bodyText,
                'pre_header'  => __('Task has been archived','fluent-boards'),
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = __('Task has been archived','fluent-boards');
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception(esc_html__('Error in sending mail to new assignees', 'fluent-boards'), 1);
        }
    }

    private function getUserData($userId)
    {
        $currentUser   = User::findOrFail($userId);
        $gravaterPhoto = fluent_boards_user_avatar($currentUser->user_email,
            $currentUser->display_name);

        return [
            'display_name' => $currentUser->display_name,
            'photo'        => $gravaterPhoto,
        ];
    }

}
