<?php

namespace FluentBoards\App\Services;

use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;

/**
 * Allow-list serializers for logged-out views: task share links (core) and the public board (Pro).
 * New task, user or comment columns never reach a public response unless added here.
 *
 * $settings is the visibility array of the calling feature; keys used here:
 * show_assignees, show_labels, show_due_dates, show_priority, task_description,
 * task_subtasks, task_attachments, task_comments.
 */
class PublicTaskSerializer
{
    const COMMENTS_PER_PAGE = 10;

    /** Comment types a visitor may read once comments are exposed; never activity or other internal types. */
    const PUBLIC_COMMENT_TYPES = ['comment', 'reply'];

    /** Fields a logged-out visitor may see for a board member, assignee or comment author. */
    const PUBLIC_USER_FIELDS = ['ID', 'display_name', 'photo', 'role'];

    public function withCardRelations($query, array $settings)
    {
        $relations = $this->cardRelations($settings);

        return $relations ? $query->with($relations) : $query;
    }

    public function formatCards($tasks, array $settings)
    {
        $cards = [];
        foreach ($tasks as $task) {
            $cards[] = $this->formatCard($task, $settings);
        }

        return $cards;
    }

    public function formatCard(Task $task, array $settings)
    {
        $card = [
            'id'       => (int)$task->id,
            'title'    => $task->title,
            'stage_id' => (int)$task->stage_id,
            'position' => (float)$task->position,
            'status'   => $task->status,
        ];

        if (!empty($settings['show_priority'])) {
            $card['priority'] = $task->priority;
        }

        if (!empty($settings['show_due_dates'])) {
            $card['started_at'] = $task->started_at;
            $card['due_at'] = $task->due_at;
            $card['is_overdue'] = (bool)$task->isOverdue();
        }

        if (!empty($settings['show_labels'])) {
            $card['labels'] = $task->labels->map(function ($label) {
                return $this->formatLabel($label);
            })->values()->toArray();
        }

        if (!empty($settings['show_assignees'])) {
            $card['assignees'] = $this->sanitizeUsers($task->assignees);
        }

        if (!empty($settings['task_subtasks'])) {
            $taskSettings = is_array($task->settings) ? $task->settings : [];
            $card['subtask_count'] = (int)($taskSettings['subtask_count'] ?? 0);
            $card['subtask_completed_count'] = (int)($taskSettings['subtask_completed_count'] ?? 0);
        }

        if (!empty($settings['task_comments'])) {
            $card['comments_count'] = (int)$task->comments_count;
        }

        return $card;
    }

    /**
     * Card fields plus each task section the settings expose.
     */
    public function formatTaskDetail(Task $task, array $settings)
    {
        $relations = $this->cardRelations($settings);
        if ($relations) {
            $task->load($relations);
        }

        $data = $this->formatCard($task, $settings);

        if (!empty($settings['task_description'])) {
            $data['description'] = DescriptionMarkdownConverter::normalize($task->description);
        }

        if (!empty($settings['task_subtasks'])) {
            $data['subtasks'] = Task::where('parent_id', $task->id)
                ->where('board_id', $task->board_id)
                ->whereNull('archived_at')
                ->orderBy('position', 'ASC')
                ->get()
                ->map(function ($subtask) use ($settings) {
                    return [
                        'id'     => (int)$subtask->id,
                        'title'  => $subtask->title,
                        'status' => $subtask->status,
                        'due_at' => !empty($settings['show_due_dates']) ? $subtask->due_at : null,
                    ];
                })
                ->values()
                ->toArray();
        }

        if (!empty($settings['task_attachments'])) {
            $data['attachments'] = $task->attachments()->get()->map(function ($attachment) {
                return [
                    'id'              => (int)$attachment->id,
                    'title'           => $attachment->title ?: basename((string)$attachment->file_path),
                    'attachment_type' => $attachment->attachment_type,
                    'file_size'       => $attachment->file_size,
                    'url'             => $attachment->secure_url,
                ];
            })->values()->toArray();
        }

        return $data;
    }

    /**
     * One page of a task's top-level comments with their replies, newest first.
     */
    public function commentsPage(Task $task, $page)
    {
        $page = max(1, (int)$page);

        $query = Comment::where('task_id', $task->id)
            ->where('board_id', $task->board_id)
            ->where('type', 'comment')
            ->whereNull('parent_id');

        $total = (clone $query)->count();

        $comments = $query->with(['user', 'replies.user'])
            ->orderBy('created_at', 'DESC')
            ->offset(($page - 1) * self::COMMENTS_PER_PAGE)
            ->limit(self::COMMENTS_PER_PAGE)
            ->get();

        return [
            'comments' => $comments->map(function ($comment) {
                $data = $this->formatComment($comment);
                $data['replies'] = $comment->replies
                    ->filter(function ($reply) {
                        return in_array($reply->type, self::PUBLIC_COMMENT_TYPES, true);
                    })
                    ->map(function ($reply) {
                        return $this->formatComment($reply);
                    })
                    ->values()
                    ->toArray();
                return $data;
            })->values()->toArray(),
            'total'    => (int)$total,
            'has_more' => $total > $page * self::COMMENTS_PER_PAGE,
        ];
    }

    public function formatLabel($label)
    {
        return [
            'id'       => (int)$label->id,
            'title'    => $label->title,
            'color'    => $label->color,
            'bg_color' => $label->bg_color,
        ];
    }

    /**
     * Reduce users (ORM collection, array or iterable) to PUBLIC_USER_FIELDS plain arrays.
     * user_login, user_email and every other WordPress user column are dropped.
     */
    public function sanitizeUsers($users)
    {
        $sanitizedUsers = [];

        foreach ($this->toIterable($users) as $user) {
            if (is_array($user)) {
                $user = (object)$user;
            }

            if (!is_object($user) || empty($user->ID)) {
                continue;
            }

            $role = 'Member';
            $pivot = isset($user->pivot) ? $user->pivot : null;
            if ($pivot && isset($pivot->settings)) {
                $settings = maybe_unserialize($pivot->settings);
                if (is_array($settings)) {
                    if (!empty($settings['is_admin'])) {
                        $role = 'Admin';
                    } elseif (!empty($settings['is_viewer_only'])) {
                        $role = 'Viewer';
                    }
                }
            }

            $displayName = isset($user->display_name) ? (string)$user->display_name : '';
            $email = isset($user->user_email) ? (string)$user->user_email : '';

            $record = [
                'ID'           => (int)$user->ID,
                'display_name' => $displayName,
                'photo'        => fluent_boards_user_avatar($email, $displayName),
                'role'         => $role
            ];

            $sanitizedUsers[] = array_intersect_key($record, array_flip(self::PUBLIC_USER_FIELDS));
        }

        return $sanitizedUsers;
    }

    /**
     * Comment author is reduced to the public user shape; author_email/ip never leave the server.
     */
    private function formatComment(Comment $comment)
    {
        $author = $comment->user ? $this->sanitizeUsers([$comment->user]) : [];
        if (!$author) {
            $name = (string)$comment->author_name;
            $author = [[
                'ID'           => 0,
                'display_name' => $name,
                'photo'        => fluent_boards_user_avatar('', $name),
            ]];
        }

        return [
            'id'          => (int)$comment->id,
            'description' => wp_kses_post((string)$comment->description),
            'created_at'  => $comment->created_at ? (string)$comment->created_at : null,
            'author'      => [
                'display_name' => $author[0]['display_name'],
                'photo'        => $author[0]['photo'],
            ],
            'images'      => $comment->images->map(function ($image) {
                return [
                    'id'    => (int)$image->id,
                    'title' => $image->title,
                    'url'   => $image->secure_url,
                ];
            })->values()->toArray(),
        ];
    }

    private function cardRelations(array $settings)
    {
        $relations = [];
        if (!empty($settings['show_assignees'])) {
            $relations[] = 'assignees';
        }
        if (!empty($settings['show_labels'])) {
            $relations[] = 'labels';
        }

        return $relations;
    }

    private function toIterable($value)
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'all')) {
            return (array)$value->all();
        }

        if ($value instanceof \Traversable) {
            return iterator_to_array($value, false);
        }

        return [];
    }
}
