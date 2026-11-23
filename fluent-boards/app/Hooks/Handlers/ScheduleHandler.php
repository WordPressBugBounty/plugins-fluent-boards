<?php

namespace FluentBoards\App\Hooks\Handlers;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Models\User;
use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\TaskEmailDigest;
use FluentBoards\App\Services\NotificationService;
use FluentBoards\App\Services\Libs\Mailer;
use Throwable;

class ScheduleHandler
{
    private const EVENTS = [
        'comment'         => ['sendEmailForComment', Constant::BOARD_EMAIL_COMMENT],
        'mention'         => ['sendEmailForMention', Constant::BOARD_EMAIL_MENTION],
        'add_assignee'    => ['sendEmailForAddAssignee', Constant::BOARD_EMAIL_TASK_ASSIGN],
        'remove_assignee' => ['sendEmailForRemoveAssignee', Constant::BOARD_EMAIL_REMOVE_FROM_TASK],
        'stage_change'    => ['sendEmailForChangeStage', Constant::BOARD_EMAIL_STAGE_CHANGE],
        'due_date_update' => ['sendEmailForDueDateUpdate', Constant::BOARD_EMAIL_DUE_DATE_CHANGE],
        'priority_change' => ['sendEmailForPriorityChange', Constant::BOARD_EMAIL_PRIORITY_CHANGE],
        'description_update' => ['sendEmailForDescriptionUpdate', Constant::BOARD_EMAIL_DESCRIPTION_UPDATE],
        'task_archived'   => ['sendEmailForArchivedTask', Constant::BOARD_EMAIL_TASK_ARCHIVE],
    ];

    private $digestItems;
    private $currentEvent;
    private $preparationFailed = false;

    /** Expand source events in the worker, accepting previously scheduled three-argument actions. */
    public function queueTaskEmail($objectId, $recipients, $actorId, $occurredAt = null, $boardId = 0, $change = [], $sourceId = 0)
    {
        $type = substr(current_filter(), strlen('fluent_boards/one_time_schedule_send_email_for_'));
        if (!isset(self::EVENTS[$type])) {
            return;
        }
        $isComment = in_array($type, ['mention', 'comment'], true);
        $context = ['type' => $type, ($isComment ? 'comment_id' : 'task_id') => absint($objectId)];
        try {
            if (!$sourceId) {
                // Actions scheduled before source rows existed still carry their original arguments.
                $sourceId = TaskEmailDigest::recordSource($type, $objectId, $recipients, $actorId,
                    min(time(), absint($occurredAt ?: time())), $boardId, $change);
            }
            (new TaskEmailDigest())->expandSource($sourceId);
        } catch (Throwable $error) {
            Mailer::reportFailure($context, $error);
            throw $error;
        }
    }

    /** Revalidate the source and resolve eligible recipients before creating digest rows. */
    public function sourceRecipientEmails(array $event, $recipients)
    {
        if (in_array($event['type'], ['comment', 'mention'], true)) {
            $comment = Comment::find($event['id']);
            if (!$comment || (int) $comment->task_id !== $event['task'] || !in_array($comment->type, ['comment', 'reply'], true)) {
                return [];
            }
        }
        return $this->getEligibleRecipientEmails($recipients, $event['board'], self::EVENTS[$event['type']][1]);
    }

    public function sendTaskEmailDigest($task, $recipientId, array $events)
    {
        // Subjects and bodies are built per recipient, so translate them into that person's language.
        return Helper::withUserLocale($recipientId, function () use ($task, $recipientId, $events) {
            return $this->buildAndSendTaskEmailDigest($task, $recipientId, $events);
        });
    }

    private function buildAndSendTaskEmailDigest($task, $recipientId, array $events)
    {
        // Each renderer checks current preferences and access before collecting its item.
        $collector = new self();
        $collector->digestItems = [];
        foreach ($events as $event) {
            if (in_array($event['type'], ['mention', 'comment'], true)) {
                $comment = Comment::find($event['id']);
                if (!$comment || (int) $comment->task_id !== (int) $task->id) {
                    continue;
                }
            }
            $method = self::EVENTS[$event['type']][0];
            $collector->currentEvent = $event;
            $collector->$method($event['id'], $recipientId, $event['actor'], $event['change'] ?? []);
        }
        if ($collector->preparationFailed) {
            return false;
        }
        try {
            return $collector->deliverCollectedItems($task, $recipientId);
        } catch (Throwable $error) {
            foreach ($collector->digestItems as $item) {
                Mailer::reportFailure($item['context'] + ['recipient' => $item['email']], $error);
            }
            return false;
        }
    }

    private function deliverCollectedItems($task, $recipientId = 0)
    {
        if (!$this->digestItems) {
            return true;
        }
        $first = reset($this->digestItems);
        $body = $this->activityBody($this->digestItems, $recipientId, $task);
        if (count($this->digestItems) === 1) {
            return (new Mailer($first['email'], $first['subject'], $body))->sendGroupedNotifications([$first['context']]);
        }
        $count = count($this->digestItems);
        /* translators: %1$d is the number of updates, %2$s is the task title. */
        $subject = sprintf(_n('%1$d update on “%2$s”', '%1$d updates on “%2$s”', $count, 'fluent-boards'), $count, $task->title);
        return (new Mailer($first['email'], $subject, $body))->sendGroupedNotifications(array_column($this->digestItems, 'context'));
    }

    /**
     * Render one or more collected activity items as the timeline email.
     * One item keeps its sender's headline and button; several become "N updates on “Task”".
     */
    private function activityBody(array $collected, $recipientId = 0, $task = null)
    {
        $first = reset($collected);
        $data = $first['data'];
        $count = count($collected);

        $actors = [];
        $times = [];
        foreach ($collected as $item) {
            $name = $item['data']['userData']['display_name'] ?? '';
            $actors[(int) ($item['event']['actor'] ?? 0) . '|' . $name] = $item['data']['userData'];
            if (!empty($item['event']['time'])) {
                $times[] = (int) $item['event']['time'];
            }
        }
        $singleActor = count($actors) === 1;
        $sameDay = !$times || wp_date('Y-m-d', min($times)) === wp_date('Y-m-d', max($times));
        $itemTimeFormat = $sameDay ? get_option('time_format') : 'M j, ' . get_option('time_format');

        $items = [];
        foreach ($collected as $item) {
            $activity = $item['data']['activity'];
            if ($count > 1) {
                if ($activity['style'] === 'comment') {
                    // Grouped emails quote comments instead of showing them in full.
                    $activity['style'] = 'quote';
                    $activity['to'] = wp_trim_words(wp_strip_all_tags($activity['html']), 30);
                }
                $activity['time'] = !empty($item['event']['time']) ? wp_date($itemTimeFormat, (int) $item['event']['time']) : '';
                $activity['by'] = $singleActor ? '' : ($item['data']['userData']['display_name'] ?? '');
            }
            $items[] = $activity;
        }

        $view = [
            'headline'     => $data['headline'],
            'actor'        => $singleActor ? $data['userData'] : null,
            'actor_names'  => implode(', ', array_unique(array_column($actors, 'display_name'))),
            'time_label'   => $this->timeRange($times),
            'items'        => $items,
            'settings_url' => $recipientId ? fluent_boards_page_url() . 'member/' . absint($recipientId) . '/preferences' : '',
        ];
        if ($count > 1 && $task) {
            $taskUrl = site_url('/') . '?redirect=to_task&taskId=' . ($task->parent_id ?: $task->id);
            $view['headline'] = sprintf(
                /* translators: %1$d is the number of updates, %2$s is the linked task title. */
                esc_html(_n('%1$d update on “%2$s”', '%1$d updates on “%2$s”', $count, 'fluent-boards')),
                $count,
                $this->titleLink($task->title, $data['task_url'] ?? $taskUrl)
            );
            $view['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $taskUrl];
            $view['pre_header'] = implode(', ', array_unique(array_column($items, 'title')));
            $view['email_reason'] = __('You’re getting this because of your email settings for this board.', 'fluent-boards');
        }

        return Helper::loadView('emails.task-activity', array_merge($data, $view));
    }

    /**
     * "10:42 AM – 10:55 AM" for changes on one day, full dates otherwise. Times are unix timestamps.
     */
    private function timeRange(array $times)
    {
        if (!$times) {
            return '';
        }
        $start = min($times);
        $end = max($times);
        $timeFormat = get_option('time_format');
        $sameDay = wp_date('Y-m-d', $start) === wp_date('Y-m-d', $end);
        $format = $sameDay ? $timeFormat : get_option('date_format') . ' ' . $timeFormat;
        $from = wp_date($format, $start);
        $to = wp_date($format, $end);
        if ($from === $to) {
            return $from;
        }
        /* translators: 1: time of the first change, 2: time of the last change */
        return sprintf(__('%1$s – %2$s', 'fluent-boards'), $from, $to);
    }

    /** An eligible item failed before collection. Retry the entire batch, including prepared items. */
    private function reportPreparationFailure(array $context, Throwable $error)
    {
        $this->preparationFailed = true;
        Mailer::reportFailure($context, $error);
    }

    private function deliverItem($email, $subject, $data, $context, $recipientId = 0)
    {
        $event = $this->currentEvent;
        if (is_array($this->digestItems)) {
            $this->digestItems[] = compact('email', 'subject', 'data', 'context', 'event');
            return;
        }
        $body = $this->activityBody([compact('email', 'subject', 'data', 'context', 'event')], $recipientId);
        (new Mailer($email, $subject, $body))->sendNotification($context);
    }

    /**
     * Shared data for the activity email: branding, logo bar breadcrumb, headline, the activity item
     * and buttons. $headline is HTML and already escaped.
     */
    private function activityData($task, $board, $actorId, $headline, array $activity, array $cta, array $extra = [])
    {
        $pageUrl = fluent_boards_page_url();
        $boardUrl = $pageUrl . 'boards/' . $board->id;
        // Subtasks open inside their parent task.
        $openTask = $task->parent_id ? ($task->parentTask($task->parent_id) ?: $task) : $task;
        return array_merge($this->getBrandingData(), [
            'headline'     => $headline,
            'task_url'     => $this->taskUrl($pageUrl, $board->id, $openTask),
            'activity'     => $activity + ['style' => 'text', 'from' => null, 'to' => '', 'html' => '', 'parent_html' => '', 'note' => ''],
            'card_head'    => $this->crumb($task, $board, $boardUrl),
            'board_url'    => $boardUrl,
            'cta'          => $cta,
            'userData'     => $this->getUserData($actorId),
            'pre_header'   => $this->taskPreHeader($task, $board),
            'email_reason' => __('You’re getting this because of your email settings for this board.', 'fluent-boards'),
        ], $extra);
    }

    /** Board › Stage line in the logo bar. HTML, already escaped. */
    private function crumb($task, $board, $boardUrl)
    {
        $crumb = '<a class="fbs_email_text2" href="' . esc_url($boardUrl) . '" target="_blank" style="color: #5F6573; text-decoration: none;">' . esc_html($board->title) . '</a>';
        if ($task->stage) {
            $crumb .= ' &rsaquo; ' . esc_html($task->stage->title);
        }
        return $crumb;
    }

    /** Task title linked to the task, for use inside a headline. */
    private function titleLink($title, $url)
    {
        return '<a class="fbs_email_text" href="' . esc_url($url) . '" target="_blank" style="color: #0E121B; text-decoration: none;">' . esc_html($title) . '</a>';
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForComment($commentId, $recipientUserIds, $current_user_id)
    {
        $context = ['type' => 'comment', 'comment_id' => $commentId];
        try {
            $comment = Comment::find($commentId);
            if (!$comment || !in_array($comment->type, ['comment', 'reply'])) {
                return;
            }
            $task = Task::findOrFail($comment->task_id);
            $usersToSendEmail = $this->getEligibleRecipientEmails($recipientUserIds, $task->board_id);
            if (!$usersToSendEmail) {
                return;
            }
            $board = $task->board;
            $userData = $this->getUserData($current_user_id);
            $redirectUrl = site_url('/') . '?redirect=to_task&taskId=' . $task->id;
            $titleLink = $this->titleLink($task->title, $this->taskUrl(fluent_boards_page_url(), $board->id, $task));
            $isReply = $comment->type == 'reply';
            $parentComment = $isReply && $comment->parent_id ? Comment::find($comment->parent_id) : null;

            $data = $this->activityData($task, $board, $current_user_id,
                $isReply
                    // translators: %s is the linked task title
                    ? sprintf(esc_html__('New reply to your comment on “%s”', 'fluent-boards'), $titleLink)
                    // translators: %s is the linked task title
                    : sprintf(esc_html__('New comment on “%s”', 'fluent-boards'), $titleLink),
                [
                    'type'        => 'comment',
                    'title'       => $isReply ? __('Replied to your comment', 'fluent-boards') : __('Commented', 'fluent-boards'),
                    'style'       => 'comment',
                    'html'        => $comment->description,
                    'parent_html' => $parentComment ? $parentComment->description : '',
                ],
                ['label' => $isReply ? __('Reply', 'fluent-boards') : __('Reply to comment', 'fluent-boards'), 'url' => $redirectUrl],
                [
                    'pre_header'   => wp_trim_words(wp_strip_all_tags($comment->description), 20),
                    'email_reason' => $isReply
                        ? __('You’re getting this because someone replied to your comment.', 'fluent-boards')
                        : __('You’re getting this because of your email settings for this board.', 'fluent-boards'),
                ]
            );
            $mailSubject = $isReply
                // translators: %s is the name of the person who replied
                ? sprintf(__('%s replied to your comment', 'fluent-boards'), $userData['display_name'])
                // translators: %1$s is the commenter name, %2$s is the task title
                : sprintf(__('%1$s commented on “%2$s”', 'fluent-boards'), $userData['display_name'], $task->title);

            foreach ($usersToSendEmail as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForMention($commentId, $recipientUserIds, $current_user_id)
    {
        $context = ['type' => 'mention', 'comment_id' => $commentId];
        try {
            $comment = Comment::find($commentId);
            if (!$comment || !in_array($comment->type, ['comment', 'reply'])) {
                return;
            }
            $task = Task::findOrFail($comment->task_id);
            $usersToSendEmail = $this->getEligibleRecipientEmails($recipientUserIds, $task->board_id, Constant::BOARD_EMAIL_MENTION);
            if (!$usersToSendEmail) {
                return;
            }
            $board = $task->board;
            $userData = $this->getUserData($current_user_id);
            $titleLink = $this->titleLink($task->title, $this->taskUrl(fluent_boards_page_url(), $board->id, $task));

            $data = $this->activityData($task, $board, $current_user_id,
                // translators: %s is the linked task title
                sprintf(esc_html__('You were mentioned on “%s”', 'fluent-boards'), $titleLink),
                ['type' => 'mention', 'title' => __('Mentioned you', 'fluent-boards'), 'style' => 'comment', 'html' => $comment->description],
                ['label' => __('Reply to comment', 'fluent-boards'), 'url' => site_url('/') . '?redirect=to_task&taskId=' . $task->id],
                [
                    'pre_header'   => wp_trim_words(wp_strip_all_tags($comment->description), 20),
                    'email_reason' => __('You’re getting this because someone mentioned you.', 'fluent-boards'),
                ]
            );
            // translators: %1$s is the name of the person who mentioned the recipient, %2$s is the task title
            $mailSubject = sprintf(__('%1$s mentioned you in “%2$s”', 'fluent-boards'), $userData['display_name'], $task->title);

            foreach ($usersToSendEmail as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForAddAssignee($taskId, $newAssigneeId, $current_user_id)
    {
        $context = ['type' => 'add_assignee', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            if (!(new NotificationService())->checkIfEmailEnabled($task->board_id, $newAssigneeId, Constant::BOARD_EMAIL_TASK_ASSIGN)) {
                return;
            }
            $board = $task->board;
            $assignee = User::findOrFail($newAssigneeId);
            $data = $this->activityData($task, $board, $current_user_id, '', [
                'type'  => 'assign',
                'title' => __('Assigned you', 'fluent-boards'),
                // translators: %s is the due date
                'to'    => $task->due_at ? sprintf(__('Due %s', 'fluent-boards'), $this->formatDate($task->due_at)) : '',
            ], [], ['email_reason' => __('You’re getting this because you were assigned to this task.', 'fluent-boards')]);
            $titleLink = $this->titleLink($task->title, $data['task_url']);

            if (null == $task->parent_id) {
                // translators: %s is the linked task title
                $data['headline'] = sprintf(esc_html__('You’re assigned to “%s”', 'fluent-boards'), $titleLink);
                $data['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $data['task_url']];
                // translators: %s is the task title
                $mailSubject = sprintf(__('You’re assigned to “%s”', 'fluent-boards'), $task->title);
            } else {
                // translators: %s is the linked subtask title
                $data['headline'] = sprintf(esc_html__('You’re assigned to subtask “%s”', 'fluent-boards'), $titleLink);
                $data['activity']['note'] = $this->subtaskNote($task);
                $data['cta'] = ['label' => __('Open subtask', 'fluent-boards'), 'url' => $data['task_url']];
                // translators: %s is the subtask title
                $mailSubject = sprintf(__('You’re assigned to subtask “%s”', 'fluent-boards'), $task->title);
            }

            $this->deliverItem($assignee->user_email, $mailSubject, $data, $context, $newAssigneeId);
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForRemoveAssignee($taskId, $newAssigneeId, $current_user_id)
    {
        $context = ['type' => 'remove_assignee', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            if (!(new NotificationService())->checkIfEmailEnabled($task->board_id, $newAssigneeId, Constant::BOARD_EMAIL_REMOVE_FROM_TASK)) {
                return;
            }
            $board = $task->board;
            $assignee = User::findOrFail($newAssigneeId);
            $isSubtask = null != $task->parent_id;
            $data = $this->activityData($task, $board, $current_user_id, '', [
                'type'  => 'unassign',
                'title' => __('Removed you', 'fluent-boards'),
                'to'    => $isSubtask ? $this->subtaskNote($task) : '',
                'note'  => $isSubtask
                    ? __('You won’t get updates about this subtask unless you’re mentioned or assigned again.', 'fluent-boards')
                    : __('You won’t get updates about this task unless you’re mentioned or assigned again.', 'fluent-boards'),
            ], [], ['email_reason' => __('You’re getting this because you were assigned to this task.', 'fluent-boards')]);
            $titleLink = $this->titleLink($task->title, $data['task_url']);

            if (!$isSubtask) {
                // translators: %s is the linked task title
                $data['headline'] = sprintf(esc_html__('You were removed from “%s”', 'fluent-boards'), $titleLink);
                $data['cta'] = ['label' => __('View task', 'fluent-boards'), 'url' => $data['task_url']];
                // translators: %s is the task title
                $mailSubject = sprintf(__('You were removed from “%s”', 'fluent-boards'), $task->title);
            } else {
                // translators: %s is the linked subtask title
                $data['headline'] = sprintf(esc_html__('You were removed from subtask “%s”', 'fluent-boards'), $titleLink);
                $data['cta'] = ['label' => __('View subtask', 'fluent-boards'), 'url' => $data['task_url']];
                // translators: %s is the subtask title
                $mailSubject = sprintf(__('You were removed from subtask “%s”', 'fluent-boards'), $task->title);
            }

            $this->deliverItem($assignee->user_email, $mailSubject, $data, $context, $newAssigneeId);
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForChangeStage($taskId, $newAssigneeEmails, $current_user_id, $change = [])
    {
        $context = ['type' => 'stage_change', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            $newAssigneeEmails = $this->getEligibleRecipientEmails($newAssigneeEmails, $task->board_id, Constant::BOARD_EMAIL_STAGE_CHANGE);
            if (!$newAssigneeEmails) {
                return;
            }
            $board = $task->board;
            $stage = $change['to'] ?? ($task->stage ? $task->stage->title : '');
            $data = $this->activityData($task, $board, $current_user_id, '', [
                'type'  => 'stage',
                'title' => __('Moved to a new stage', 'fluent-boards'),
                'style' => 'pills',
                'from'  => $change['from'] ?? null,
                'to'    => $stage,
            ], [], ['email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards')]);
            // translators: %1$s is the linked task title, %2$s is the stage title
            $data['headline'] = sprintf(esc_html__('“%1$s” moved to %2$s', 'fluent-boards'), $this->titleLink($task->title, $data['task_url']), esc_html($stage));
            $data['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $data['task_url']];

            // translators: %1$s is the task title, %2$s is the stage title
            $mailSubject = sprintf(__('“%1$s” moved to %2$s', 'fluent-boards'), $task->title, $stage);

            foreach ($newAssigneeEmails as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForDueDateUpdate($taskId, $newAssigneeEmails, $current_user_id, $change = [])
    {
        $context = ['type' => 'due_date_update', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            $newAssigneeEmails = $this->getEligibleRecipientEmails($newAssigneeEmails, $task->board_id, Constant::BOARD_EMAIL_DUE_DATE_CHANGE);
            if (!$newAssigneeEmails) {
                return;
            }
            $board = $task->board;
            // Older queued date changes only carried due-date values.
            $isStart = ($change['field'] ?? 'due_at') === 'started_at';
            $destination = array_key_exists('to', $change) ? $change['to'] : ($isStart ? $task->started_at : $task->due_at);
            $previous = !empty($change['from']) ? $this->formatDate($change['from']) : null;
            if (!$destination) {
                $title = $isStart ? __('Start date removed', 'fluent-boards') : __('Due date removed', 'fluent-boards');
            } elseif (!$previous) {
                $title = $isStart ? __('Start date set', 'fluent-boards') : __('Due date set', 'fluent-boards');
            } else {
                $title = $isStart ? __('Start date changed', 'fluent-boards') : __('Due date changed', 'fluent-boards');
            }
            $data = $this->activityData($task, $board, $current_user_id, '', [
                'type'  => 'date',
                'title' => $title,
                'style' => 'pills',
                'from'  => $previous,
                'to'    => $destination ? $this->formatDate($destination) : ($isStart ? __('No start date', 'fluent-boards') : __('No due date', 'fluent-boards')),
            ], [], ['email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards')]);
            $titleLink = $this->titleLink($task->title, $data['task_url']);
            $data['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $data['task_url']];
            if ($isStart) {
                // translators: %s is the linked task title
                $data['headline'] = sprintf(esc_html__('Start date changed on “%s”', 'fluent-boards'), $titleLink);
                // translators: %s is the task title
                $mailSubject = sprintf(__('Start date changed for “%s”', 'fluent-boards'), $task->title);
            } else {
                // translators: %s is the linked task title
                $data['headline'] = sprintf(esc_html__('Due date changed on “%s”', 'fluent-boards'), $titleLink);
                // translators: %s is the task title
                $mailSubject = sprintf(__('Due date changed for “%s”', 'fluent-boards'), $task->title);
            }

            foreach ($newAssigneeEmails as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    private function sendEmailForPriorityChange($taskId, $recipients, $actorId, $change = [])
    {
        $context = ['type' => 'priority_change', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            $emails = $this->getEligibleRecipientEmails($recipients, $task->board_id, Constant::BOARD_EMAIL_PRIORITY_CHANGE);
            if (!$emails) {
                return;
            }
            $labels = apply_filters('fluent_boards/task_priorities', [
                ''       => __('No priority', 'fluent-boards'),
                'urgent' => __('Urgent', 'fluent-boards'),
                'high'   => __('High', 'fluent-boards'),
                'medium' => __('Medium', 'fluent-boards'),
                'low'    => __('Low', 'fluent-boards'),
            ]);
            $label = function ($value) use ($labels) {
                return $labels[(string) $value] ?? ucfirst((string) $value);
            };
            // Older queued priority changes carry no values; the task's current priority fills in.
            $hasChange = array_key_exists('to', $change);
            $data = $this->activityData($task, $task->board, $actorId, '', [
                'type'  => 'priority',
                'title' => __('Priority changed', 'fluent-boards'),
                'style' => 'pills',
                'from'  => $hasChange && array_key_exists('from', $change) ? $label($change['from']) : null,
                'to'    => $label($hasChange ? $change['to'] : $task->priority),
            ], []);
            // translators: %s is the linked task title
            $data['headline'] = sprintf(esc_html__('Priority changed on “%s”', 'fluent-boards'), $this->titleLink($task->title, $data['task_url']));
            $data['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $data['task_url']];
            // translators: %s is the task title
            $mailSubject = sprintf(__('Priority changed on “%s”', 'fluent-boards'), $task->title);
            foreach ($emails as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (Throwable $error) {
            $this->reportPreparationFailure($context, $error);
        }
    }

    private function sendEmailForDescriptionUpdate($taskId, $recipients, $actorId, $change = [])
    {
        $context = ['type' => 'description_update', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            $emails = $this->getEligibleRecipientEmails($recipients, $task->board_id, Constant::BOARD_EMAIL_DESCRIPTION_UPDATE);
            if (!$emails) {
                return;
            }
            // Older events have no snapshot. Describe the edit without attributing later content to it.
            $data = $this->activityData($task, $task->board, $actorId, '', [
                'type'  => 'description',
                'title' => !empty($change['removed']) ? __('Description removed', 'fluent-boards') : __('Description updated', 'fluent-boards'),
                'style' => 'quote',
                'to'    => $change['excerpt'] ?? '',
            ], []);
            // translators: %s is the linked task title
            $data['headline'] = sprintf(esc_html__('Description updated on “%s”', 'fluent-boards'), $this->titleLink($task->title, $data['task_url']));
            $data['cta'] = ['label' => __('Open task', 'fluent-boards'), 'url' => $data['task_url']];
            // translators: %s is the task title
            $mailSubject = sprintf(__('Description updated on “%s”', 'fluent-boards'), $task->title);
            foreach ($emails as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (Throwable $error) {
            $this->reportPreparationFailure($context, $error);
        }
    }

    /** Deliver queued mail only after rechecking recipient preferences and personal pauses. */
    public function sendEmailForArchivedTask($taskId, $newAssigneeEmails, $current_user_id)
    {
        $context = ['type' => 'task_archived', 'task_id' => $taskId];
        try {
            $task = Task::findOrFail($taskId);
            $newAssigneeEmails = $this->getEligibleRecipientEmails($newAssigneeEmails, $task->board_id, Constant::BOARD_EMAIL_TASK_ARCHIVE);
            if (!$newAssigneeEmails) {
                return;
            }
            $data = $this->activityData($task, $task->board, $current_user_id, '', [
                'type'  => 'archive',
                'title' => __('Archived the task', 'fluent-boards'),
                'note'  => __('Archived tasks can be restored from the board’s archive.', 'fluent-boards'),
            ], [], ['email_reason' => __('You’re getting this because you’re assigned to this task.', 'fluent-boards')]);
            // translators: %s is the linked task title
            $data['headline'] = sprintf(esc_html__('“%s” was archived', 'fluent-boards'), $this->titleLink($task->title, $data['task_url']));
            $data['cta'] = ['label' => __('View task', 'fluent-boards'), 'url' => $data['task_url']];

            // translators: %s is the task title
            $mailSubject = sprintf(__('“%s” was archived', 'fluent-boards'), $task->title);

            foreach ($newAssigneeEmails as $userId => $email) {
                $this->deliverItem($email, $mailSubject, $data, $context, $userId);
            }
        } catch (\Throwable $e) {
            $this->reportPreparationFailure($context, $e);
        }
    }

    /** "Subtask of “Parent”" line for subtask emails. */
    private function subtaskNote($task)
    {
        $parent = $task->parentTask($task->parent_id);
        // translators: %s is the parent task title
        return $parent ? sprintf(__('Subtask of “%s”', 'fluent-boards'), $parent->title) : '';
    }

    /**
     * Resolve queued IDs or legacy emails after revalidating board access, preferences and pauses.
     *
     * Email values are accepted only for queued actions created before recipient IDs
     * were introduced. They are mapped back to current WordPress users before checks.
     *
     * @param array $queuedRecipients
     * @param int $boardId
     * @param string $purpose
     * @return array Current addresses keyed by WordPress user ID.
     */
    private function getEligibleRecipientEmails($queuedRecipients, $boardId, $purpose = Constant::BOARD_EMAIL_COMMENT)
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

        $eligibleRecipientIds = array_fill_keys(
            (new NotificationService())->getEmailEnabledUserIds($boardId, $recipientUserIds, $purpose),
            true
        );

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
                $recipientEmails[$recipientUserId] = $email;
            }
        }

        return $recipientEmails;
    }

    private function getUserData($userId)
    {
        $currentUser = User::find($userId);
        if (!$currentUser) {
            return ['display_name' => __('Unknown user', 'fluent-boards'), 'photo' => ''];
        }
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
