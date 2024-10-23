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

            $preparedBody = 'commented on '.$taskLinkTag.' on '
                .$boardLinkTag.' board.';
            $preHeader = 'New comment has been added on task';
            $mailSubject = 'New comment has been added on task';


            if ($comment->type == 'reply')
            {
                $preparedBody = 'replied on your comment on '.$taskLinkTag.' on '
                    .$boardLinkTag.' board.';

                $preHeader = 'A reply has been added to your comment on a task.';
                $mailSubject = 'New Reply on Your Task Comment';
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

            $data = [
                'body'        => 'mentioned you in a comment on '.$taskLinkTag.' on '
                    .$boardLinkTag.' board.',
                'comment_link' => $page_url,
                'pre_header'  => 'You are mentioned in a comment',
                'show_footer' => true,
                'comment'     => $comment->description,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];

            $mailSubject = 'You are mentioned in a comment';
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
                $data        = [
                    'body'        => 'has assigned you to task '.$taskLinkTag
                                     .' on the board '.$boardLinkTag,
                    'pre_header'  => 'you have been assigned to task',
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

                $data = [
                    'body'        => 'has assigned you to subtask <strong>'
                                     .$task->title.'</strong> of task '
                                     .$taskLinkTag.' on the board '
                                     .$boardLinkTag,
                    'pre_header'  => 'you have been assigned to subtask',
                    'show_footer' => true, 'user' => $assignee,
                    'userData'    => $userData,
                    'site_url'    => site_url(),
                    'site_title'  => get_bloginfo('name'),
                    'site_logo'   => fluent_boards_site_logo(),
                ];
            }

            $mailSubject = 'You have been assigned to task';
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];


            \wp_mail($assignee->user_email, $mailSubject, $message, $headers);

        } catch (\Exception $e) {
            throw new \Exception('Error in sending mail to new assignees', 1);
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
                $data        = [
                    'body'        => 'has removed you from task '.$taskLinkTag
                                     .' on the board '.$boardLinkTag,
                    'pre_header'  => 'you have been removed from task',
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

                $data = [
                    'body'        => 'has removed you from subtask <strong>'
                                     .$task->title.'</strong> of task '
                                     .$taskLinkTag.' on the board '
                                     .$boardLinkTag,
                    'pre_header'  => 'you have been removed from subtask',
                    'show_footer' => true,
                    'userData'    => $userData,
                ];
            }

            $mailSubject = 'You have been removed from task';
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            \wp_mail($assignee->user_email, $mailSubject, $message, $headers);

        } catch (\Exception $e) {
            throw new \Exception('Error in sending mail to new assignees', 1);
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

            $data = [
                'body'        => 'has moved '.$taskLinkTag.' task to <strong>'
                                 .$task->stage->title
                                 .'</strong> stage of board '.$boardLinkTag,
                'pre_header'  => 'Task stage has been changed',
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = 'Task stage has been changed';
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception('Error in sending mail to new assignees', 1);
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

            $data = [
                'body'        => 'has updated due date of '.$taskLinkTag
                                 .' task to <strong>'.$task->due_at
                                 .'</strong> of board '.$boardLinkTag,
                'pre_header'  => 'Task due date has been changed',
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = 'Task due date has been changed';
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception('Error in sending mail to new assignees', 1);
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

            $data = [
                'body'        => 'has archived '.$taskLinkTag.' task of board '
                                 .$boardLinkTag,
                'pre_header'  => 'Task has been archived',
                'show_footer' => true,
                'userData'    => $userData,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];


            $mailSubject = 'Task has been archived';
            $message     = Helper::loadView('emails.assignee2', $data);
            $headers     = ['Content-Type: text/html; charset=UTF-8'];

            foreach ($newAssigneeEmails as $assignee_email) {
                \wp_mail($assignee_email, $mailSubject, $message, $headers);
            }
        } catch (\Exception $e) {
            throw new \Exception('Error in sending mail to new assignees', 1);
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

    public function sendInvitationViaEmail($boardId, $email, $current_user_id)
    {
        try {
            $userData = $this->getUserData($current_user_id);

            $board = Board::findOrFail($boardId) ?? null;
            if (!$board) {
                return;
            }

            $page_url = fluent_boards_page_url();

            $hashCode = $this->hashGenerate(20);

            $siteUrl = add_query_arg( array(
                'fbs' => 1,
                'invitation' => 'board',
                'email' => $email,
                'hash' => $hashCode,
                'bid' => $boardId
            ), site_url('index.php') );

            $data = [
                'body'        => 'has invited you to join board: '. $board->title ,
                'pre_header'  => __('join board invitation in fluent boards', 'fluent-boards'),
                'btn_title'   => __('Join Board', 'fluent-boards'),
                'show_footer' => true,
                'userData'    => $userData,
                'boardLink'   => $siteUrl,
                'site_url'    => site_url(),
                'site_title'  => get_bloginfo('name'),
                'site_logo'   => fluent_boards_site_logo(),
            ];

            $mailSubject = 'Invitation for joining board';
            $message = Helper::loadView('emails.invite-external', $data);
            $headers = ['Content-Type: text/html; charset=UTF-8'];

            $this->saveHashByEmail($boardId, $email, $hashCode);

            \wp_mail($email, $mailSubject, $message, $headers);
        } catch (\Exception $e) {
            // do nothing // better to log here
        }
    }

    private function hashGenerate($chars)
    {
        $data = '1234567890ABCDEFGHIJKLMNOPQRSTUVWXYZabcefghijklmnopqrstuvwxyz';
        return substr(str_shuffle($data), 0, $chars);
    }

    private function saveHashByEmail($boardId, $email, $hash)
    {
        $this->deleteHashCode($boardId, $email);

        $fbs_meta = new Meta();
        $fbs_meta->object_id = $boardId;
        $fbs_meta->object_type = Constant::OBJECT_TYPE_BOARD;
        $fbs_meta->key = Constant::BOARD_INVITATION;
        $fbs_meta->value = ['email' => $email, 'hash' => $hash];
        $fbs_meta->save();
    }

    private function deleteHashCode($boardId, $email)
    {
        $activeHashCodes = $this->getActiveHashCodes($boardId);

        foreach ($activeHashCodes as $savedHash) {
            $value = maybe_unserialize($savedHash->value);
            if($value['email'] == $email){
                Meta::where('id', $savedHash->id)->delete();
            }
        }
    }

    private function getActiveHashCodes($boardId)
    {
        return Meta::query()->where('object_id', $boardId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', Constant::BOARD_INVITATION)
            ->get();
    }

}
