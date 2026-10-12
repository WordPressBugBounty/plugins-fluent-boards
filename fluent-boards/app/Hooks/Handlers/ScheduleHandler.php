<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Meta;
use FluentBoards\App\Models\Relation;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\Libs\Mailer;

class ScheduleHandler
{
    public function sendEmailForComment(
        $commentId,
        $recipientUserIds,
        $current_user_id
    ) {
        $context = ['type' => 'comment', 'comment_id' => $commentId];
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

            $usersToSendEmail = $this->getEligibleCommentRecipientEmails(
                $recipientUserIds,
                $task->board_id
            );

            if (!$usersToSendEmail) {
                return;
            }

            $board    = $task->board;

            $page_url = fluent_boards_page_url();
            $redirectUrl = site_url('/') . '?redirect=to_task&taskId='.$task->id;

            $userData = $this->getUserData($current_user_id);

            $taskUrl = $this->taskUrl($page_url, $board->id, $task);

            $isReply = $comment->type == 'reply';

            if ($isReply) {
                $parentComment = $comment->parent_id ? Comment::find($comment->parent_id) : null;

                $data = [
                    'badge'          => ['label' => __('Reply', 'fluent-boards'), 'tone' => 'accent'],
                    // translators: %s is the name of the person who replied
                    'heading'        => sprintf(__('%s replied to your comment', 'fluent-boards'), $userData['display_name']),
                    'parent_comment' => $parentComment ? $parentComment->description : '',
                    'cta'            => ['label' => __('Reply', 'fluent-boards'), 'url' => $redirectUrl],
                    'email_reason'   => __('You’re getting this because someone replied to your comment.', 'fluent-boards'),
                    'pre_header'     => wp_trim_words(wp_strip_all_tags($comment->description), 20),
                ];
                // translators: %s is the name of the person who replied
                $mailSubject = sprintf(__('%s replied to your comment', 'fluent-boards'), $userData['display_name']);
            } else {
                $data = [
                    'badge'        => ['label' => __('New comment', 'fluent-boards'), 'tone' => 'accent'],
                    // translators: %s is the name of the commenter
                    'heading'      => sprintf(__('%s commented on a task', 'fluent-boards'), $userData['display_name']),
                    'cta'          => ['label' => __('Reply to comment', 'fluent-boards'), 'url' => $redirectUrl],
                    'email_reason' => __('You’re getting this because of your email settings for this board.', 'fluent-boards'),
                    'pre_header'   => wp_trim_words(wp_strip_all_tags($comment->description), 20),
                ];
                // translators: %1$s is the commenter name, %2$s is the task title
                $mailSubject = sprintf(__('%1$s commented on “%2$s”', 'fluent-boards'), $userData['display_name'], $task->title);
            }

            $data = array_merge($this->getBrandingData(), $data, [
                'context'     => $this->contextLine($task->title, $taskUrl, $board->title, $page_url.'boards/'.$board->id),
                'comment'     => $comment->description,
                'userData'    => $userData,
                'show_footer' => true,
            ]);

            $message     = Helper::loadView('emails.comment2', $data);

            foreach ($usersToSendEmail as $email) {
                (new Mailer($email, $mailSubject, $message))->sendNotification($context);
            }
        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    public function sendEmailForMention($commentId, $recipientUserIds, $current_user_id)
    {
        $context = ['type' => 'mention', 'comment_id' => $commentId];
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

            $usersToSendEmail = $this->getEligibleCommentRecipientEmails(
                $recipientUserIds,
                $task->board_id
            );
            if (!$usersToSendEmail) {
                return;
            }
            $board    = $task->board;

            $page_url = fluent_boards_page_url();
            $redirectUrl = site_url('/') . '?redirect=to_task&taskId='.$task->id;

            $userData = $this->getUserData($current_user_id);

            $taskUrl = $this->taskUrl($page_url, $board->id, $task);

            $data = array_merge($this->getBrandingData(), [
                'badge'        => ['label' => __('Mentioned you', 'fluent-boards'), 'tone' => 'accent'],
                // translators: %s is the name of the person who mentioned the recipient
                'heading'      => sprintf(__('%s mentioned you in a comment', 'fluent-boards'), $userData['display_name']),
                'context'      => $this->contextLine($task->title, $taskUrl, $board->title, $page_url.'boards/'.$board->id),
                'comment'      => $comment->description,
                'userData'     => $userData,
                'cta'          => ['label' => __('Reply to comment', 'fluent-boards'), 'url' => $redirectUrl],
                'email_reason' => __('You’re getting this because someone mentioned you.', 'fluent-boards'),
                'pre_header'   => wp_trim_words(wp_strip_all_tags($comment->description), 20),
                'show_footer'  => true,
            ]);

            // translators: %1$s is the name of the person who mentioned the recipient, %2$s is the task title
            $mailSubject = sprintf(__('%1$s mentioned you in “%2$s”', 'fluent-boards'), $userData['display_name'], $task->title);
            $message     = Helper::loadView('emails.comment2', $data);

            foreach ($usersToSendEmail as $email) {
                (new Mailer($email, $mailSubject, $message))->sendNotification($context);
            }
        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    public function sendEmailForAddAssignee(
        $taskId,
        $newAssigneeId,
        $current_user_id
    ) {
        $context = ['type' => 'add_assignee', 'task_id' => $taskId];
        try {
            $task     = Task::findOrFail($taskId);
            $board    = $task->board;
            $assignee = User::findOrFail($newAssigneeId);

            $userData = $this->getUserData($current_user_id);
            $page_url = fluent_boards_page_url();
            $boardUrl = $page_url.'boards/'.$board->id;

            if (null == $task->parent_id) {
                // this is a task
                $taskUrl = $this->taskUrl($page_url, $board->id, $task);

                $data = [
                    'badge'      => ['label' => __('Assigned to you', 'fluent-boards'), 'tone' => 'accent'],
                    // translators: %s is the name of the person who assigned the task
                    'heading'    => sprintf(__('%s assigned you to a task', 'fluent-boards'), $userData['display_name']),
                    'task_card'  => [
                        'title' => $task->title,
                        'rows'  => $this->taskRows($task, $board, $boardUrl),
                    ],
                    'cta'        => ['label' => __('Open task', 'fluent-boards'), 'url' => $taskUrl],
                    'pre_header' => $this->taskPreHeader($task, $board),
                ];
                // translators: %s is the task title
                $mailSubject = sprintf(__('You’re assigned to “%s”', 'fluent-boards'), $task->title);
            } else {
                // this is a subtask; subtasks open inside their parent task
                $parentTask = $task->parentTask($task->parent_id);
                $taskUrl    = $this->taskUrl($page_url, $board->id, $parentTask);

                $data = [
                    'badge'      => ['label' => __('Subtask assigned', 'fluent-boards'), 'tone' => 'accent'],
                    // translators: %s is the name of the person who assigned the subtask
                    'heading'    => sprintf(__('%s assigned you to a subtask', 'fluent-boards'), $userData['display_name']),
                    'task_card'  => [
                        'title'        => $task->title,
                        'parent_title' => $parentTask->title,
                        'parent_url'   => $taskUrl,
                        'rows'         => $this->taskRows($task, $board, $boardUrl, false),
                    ],
                    'cta'        => ['label' => __('Open subtask', 'fluent-boards'), 'url' => $taskUrl],
                    'pre_header' => $this->taskPreHeader($task, $board),
                ];
                // translators: %s is the subtask title
                $mailSubject = sprintf(__('You’re assigned to subtask “%s”', 'fluent-boards'), $task->title);
            }

            $data = array_merge($this->getBrandingData(), $data, [
                'email_reason' => __('You’re getting this because you were assigned to this task.', 'fluent-boards'),
                'show_footer'  => true,
                'user'         => $assignee,
                'userData'     => $userData,
            ]);

            $message     = Helper::loadView('emails.assignee2', $data);

            (new Mailer($assignee->user_email, $mailSubject, $message))->sendNotification($context);

        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    public function sendEmailForRemoveAssignee(
        $taskId,
        $newAssigneeId,
        $current_user_id
    ) {
        $context = ['type' => 'remove_assignee', 'task_id' => $taskId];
        try {
            $task     = Task::findOrFail($taskId);
            $board    = $task->board;
            $assignee = User::findOrFail($newAssigneeId);

            $userData = $this->getUserData($current_user_id);
            $page_url = fluent_boards_page_url();
            $boardUrl = $page_url.'boards/'.$board->id;

            if (null == $task->parent_id) {
                // this is a task
                $taskUrl = $this->taskUrl($page_url, $board->id, $task);

                $data = [
                    // translators: %s is the name of the person who removed the assignee
                    'heading'   => sprintf(__('%s removed you from a task', 'fluent-boards'), $userData['display_name']),
                    'task_card' => [
                        'title' => $task->title,
                        'rows'  => $this->taskRows($task, $board, $boardUrl, true, false),
                        'muted' => true,
                    ],
                    'note'      => __('You won’t get updates about this task unless you’re mentioned or assigned again.', 'fluent-boards'),
                    'cta'       => ['label' => __('View task', 'fluent-boards'), 'url' => $taskUrl, 'style' => 'secondary'],
                ];
                // translators: %s is the task title
                $mailSubject = sprintf(__('You were removed from “%s”', 'fluent-boards'), $task->title);
            } else {
                // this is a subtask; subtasks open inside their parent task
                $parentTask = $task->parentTask($task->parent_id);
                $taskUrl    = $this->taskUrl($page_url, $board->id, $parentTask);

                $data = [
                    // translators: %s is the name of the person who removed the assignee
                    'heading'   => sprintf(__('%s removed you from a subtask', 'fluent-boards'), $userData['display_name']),
                    'task_card' => [
                        'title'        => $task->title,
                        'parent_title' => $parentTask->title,
                        'parent_url'   => $taskUrl,
                        'rows'         => [[__('Board', 'fluent-boards'), $this->link($board->title, $boardUrl)]],
                        'muted'        => true,
                    ],
                    'note'      => __('You won’t get updates about this subtask unless you’re mentioned or assigned again.', 'fluent-boards'),
                    'cta'       => ['label' => __('View subtask', 'fluent-boards'), 'url' => $taskUrl, 'style' => 'secondary'],
                ];
                // translators: %s is the subtask title
                $mailSubject = sprintf(__('You were removed from subtask “%s”', 'fluent-boards'), $task->title);
            }

            $data = array_merge($this->getBrandingData(), $data, [
                'badge'        => ['label' => __('Unassigned', 'fluent-boards'), 'tone' => 'neutral'],
                'pre_header'   => $this->taskPreHeader($task, $board),
                'email_reason' => __('You’re getting this because you were assigned to this task.', 'fluent-boards'),
                'show_footer'  => true,
                'userData'     => $userData,
            ]);

            $message     = Helper::loadView('emails.assignee2', $data);

            (new Mailer($assignee->user_email, $mailSubject, $message))->sendNotification($context);

        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }


    public function sendEmailForChangeStage(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        $context = ['type' => 'stage_change', 'task_id' => $taskId];
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url = fluent_boards_page_url();
            $taskUrl  = $this->taskUrl($page_url, $board->id, $task);
            $stage    = $task->stage ? $task->stage->title : '';

            $data = array_merge($this->getBrandingData(), [
                'badge'        => ['label' => __('Stage changed', 'fluent-boards'), 'tone' => 'accent'],
                // translators: %s is the name of the person who moved the task
                'heading'      => sprintf(__('%s moved a task', 'fluent-boards'), $userData['display_name']),
                'context'      => $this->contextLine($task->title, $taskUrl, $board->title, $page_url.'boards/'.$board->id),
                'change'       => ['label' => __('Now in', 'fluent-boards'), 'to' => $stage],
                'cta'          => ['label' => __('Open task', 'fluent-boards'), 'url' => $taskUrl],
                'pre_header'   => $this->taskPreHeader($task, $board),
                'email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards'),
                'show_footer'  => true,
                'userData'     => $userData,
            ]);

            // translators: %1$s is the task title, %2$s is the stage title
            $mailSubject = sprintf(__('“%1$s” moved to %2$s', 'fluent-boards'), $task->title, $stage);
            $message     = Helper::loadView('emails.assignee2', $data);

            foreach ($newAssigneeEmails as $assignee_email) {
                (new Mailer($assignee_email, $mailSubject, $message))->sendNotification($context);
            }
        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    public function sendEmailForDueDateUpdate(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        $context = ['type' => 'due_date_update', 'task_id' => $taskId];
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url = fluent_boards_page_url();
            $taskUrl  = $this->taskUrl($page_url, $board->id, $task);
            $dueDate  = $task->due_at ? $this->formatDate($task->due_at) : __('No due date', 'fluent-boards');

            $data = array_merge($this->getBrandingData(), [
                'badge'        => ['label' => __('Due date changed', 'fluent-boards'), 'tone' => 'accent'],
                // translators: %s is the name of the person who changed the due date
                'heading'      => sprintf(__('%s changed a due date', 'fluent-boards'), $userData['display_name']),
                'context'      => $this->contextLine($task->title, $taskUrl, $board->title, $page_url.'boards/'.$board->id),
                'change'       => ['label' => __('Now due', 'fluent-boards'), 'to' => $dueDate],
                'cta'          => ['label' => __('Open task', 'fluent-boards'), 'url' => $taskUrl],
                'pre_header'   => $this->taskPreHeader($task, $board),
                'email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards'),
                'show_footer'  => true,
                'userData'     => $userData,
            ]);

            // translators: %s is the task title
            $mailSubject = sprintf(__('Due date changed for “%s”', 'fluent-boards'), $task->title);
            $message     = Helper::loadView('emails.assignee2', $data);

            foreach ($newAssigneeEmails as $assignee_email) {
                (new Mailer($assignee_email, $mailSubject, $message))->sendNotification($context);
            }
        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    public function sendEmailForArchivedTask(
        $taskId,
        $newAssigneeEmails,
        $current_user_id
    ) {
        $context = ['type' => 'task_archived', 'task_id' => $taskId];
        try {
            $task  = Task::findOrFail($taskId);
            $board = $task->board;

            $userData = $this->getUserData($current_user_id);

            $page_url = fluent_boards_page_url();
            $boardUrl = $page_url.'boards/'.$board->id;
            $taskUrl  = $this->taskUrl($page_url, $board->id, $task);

            $data = array_merge($this->getBrandingData(), [
                'badge'        => ['label' => __('Archived', 'fluent-boards'), 'tone' => 'neutral'],
                // translators: %s is the name of the person who archived the task
                'heading'      => sprintf(__('%s archived a task', 'fluent-boards'), $userData['display_name']),
                'task_card'    => [
                    'title' => $task->title,
                    'rows'  => $this->taskRows($task, $board, $boardUrl, true, false),
                    'muted' => true,
                ],
                'note'         => __('Archived tasks can be restored from the board’s archive.', 'fluent-boards'),
                'cta'          => ['label' => __('View task', 'fluent-boards'), 'url' => $taskUrl, 'style' => 'secondary'],
                'pre_header'   => $this->taskPreHeader($task, $board),
                'email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards'),
                'show_footer'  => true,
                'userData'     => $userData,
            ]);

            // translators: %s is the task title
            $mailSubject = sprintf(__('“%s” was archived', 'fluent-boards'), $task->title);
            $message     = Helper::loadView('emails.assignee2', $data);

            foreach ($newAssigneeEmails as $assignee_email) {
                (new Mailer($assignee_email, $mailSubject, $message))->sendNotification($context);
            }
        } catch (\Throwable $e) {
            Mailer::reportFailure($context, $e);
        }
    }

    /**
     * Resolve current recipient emails after revalidating board access and preferences.
     *
     * Email values are accepted only for queued actions created before recipient IDs
     * were introduced. They are mapped back to current WordPress users before checks.
     *
     * @param array $queuedRecipients
     * @param int $boardId
     * @return array
     */
    private function getEligibleCommentRecipientEmails($queuedRecipients, $boardId)
    {
        $legacyEmails = [];
        foreach ((array) $queuedRecipients as $queuedRecipient) {
            if (is_string($queuedRecipient) && !ctype_digit($queuedRecipient)) {
                $legacyEmail = sanitize_email($queuedRecipient);
                if ($legacyEmail && is_email($legacyEmail)) {
                    $legacyEmails[strtolower($legacyEmail)] = $legacyEmail;
                }
            }
        }

        $legacyUserIdsByEmail = [];
        if ($legacyEmails) {
            $legacyUsers = User::whereIn('user_email', array_values($legacyEmails))
                ->get(['ID', 'user_email']);
            foreach ($legacyUsers as $legacyUser) {
                $legacyUserIdsByEmail[strtolower($legacyUser->user_email)] = absint($legacyUser->ID);
            }
        }

        $recipientUserIds = [];
        $seenRecipientUserIds = [];
        foreach ((array) $queuedRecipients as $queuedRecipient) {
            $recipientUserId = 0;

            if (is_int($queuedRecipient) || (is_string($queuedRecipient) && ctype_digit($queuedRecipient))) {
                $recipientUserId = absint($queuedRecipient);
            } elseif (is_string($queuedRecipient)) {
                $legacyEmail = sanitize_email($queuedRecipient);
                if ($legacyEmail) {
                    $recipientUserId = $legacyUserIdsByEmail[strtolower($legacyEmail)] ?? 0;
                }
            }

            if (!$recipientUserId || isset($seenRecipientUserIds[$recipientUserId])) {
                continue;
            }
            $seenRecipientUserIds[$recipientUserId] = true;
            $recipientUserIds[] = $recipientUserId;
        }

        if (!$recipientUserIds) {
            return [];
        }

        $eligibleRecipientIds = [];
        $boardRelations = Relation::where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->where('object_id', absint($boardId))
            ->whereIn('foreign_id', $recipientUserIds)
            ->get(['foreign_id', 'preferences']);

        foreach ($boardRelations as $boardRelation) {
            $preferences = maybe_unserialize($boardRelation->preferences);
            if (!is_array($preferences)) {
                continue;
            }

            if (
                !array_key_exists(Constant::BOARD_EMAIL_COMMENT, $preferences) ||
                $preferences[Constant::BOARD_EMAIL_COMMENT]
            ) {
                $eligibleRecipientIds[absint($boardRelation->foreign_id)] = true;
            }
        }

        if (!$eligibleRecipientIds) {
            return [];
        }

        $usersById = [];
        $recipients = User::whereIn('ID', array_keys($eligibleRecipientIds))
            ->get(['ID', 'user_email']);
        foreach ($recipients as $recipient) {
            $usersById[absint($recipient->ID)] = $recipient;
        }

        $recipientEmails = [];
        foreach ($recipientUserIds as $recipientUserId) {
            if (!isset($eligibleRecipientIds[$recipientUserId], $usersById[$recipientUserId])) {
                continue;
            }

            $email = sanitize_email($usersById[$recipientUserId]->user_email);
            if ($email && is_email($email)) {
                $recipientEmails[] = $email;
            }
        }

        return $recipientEmails;
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

    /**
     * Site branding passed to every email view (header logo or initial, footer name).
     *
     * @return array
     */
    private function getBrandingData()
    {
        return [
            'site_url'   => site_url(),
            'site_title' => get_bloginfo('name'),
            'site_logo'  => fluent_boards_site_logo(),
        ];
    }

    private function taskUrl($pageUrl, $boardId, $task)
    {
        return $pageUrl.'boards/'.$boardId.'/tasks/'.$task->id.'-'.substr($task->title, 0, 10);
    }

    private function link($text, $url)
    {
        return '<a class="fbs_email_link" target="_blank" href="'.esc_url($url).'" style="color: #335CFF; font-weight: 600; text-decoration: none;">'
               .esc_html($text).'</a>';
    }

    /**
     * "Task · Board" line under an email headline, both linked.
     */
    private function contextLine($taskTitle, $taskUrl, $boardTitle, $boardUrl)
    {
        return $this->link($taskTitle, $taskUrl).' &middot; '.$this->link($boardTitle, $boardUrl);
    }

    /**
     * Label/value rows for the task card. Values are HTML and already escaped.
     *
     * @return array
     */
    private function taskRows($task, $board, $boardUrl, $withStage = true, $withDue = true)
    {
        $rows = [[__('Board', 'fluent-boards'), $this->link($board->title, $boardUrl)]];

        if ($withStage && $task->stage) {
            $rows[] = [__('Stage', 'fluent-boards'), esc_html($task->stage->title)];
        }

        if ($withDue && $task->due_at) {
            $rows[] = [__('Due', 'fluent-boards'), esc_html($this->formatDate($task->due_at))];
        }

        return $rows;
    }

    private function taskPreHeader($task, $board)
    {
        $parts = [$board->title];
        if ($task->due_at) {
            // translators: %s is the due date
            $parts[] = sprintf(__('Due %s', 'fluent-boards'), $this->formatDate($task->due_at));
        }

        return implode(' · ', $parts);
    }

    /**
     * Due dates are stored in site time; format them with the site's date and time settings.
     */
    private function formatDate($dateTime)
    {
        return date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($dateTime));
    }
}
