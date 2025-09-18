<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;

class User extends Model
{
    protected $table = 'users';

    protected $primaryKey = 'ID';

    protected $hidden = ['user_pass', 'user_activation_key'];

    protected $appends = ['photo'];

    /**
     * Accessor to get dynamic photo attribute
     * @return string
     */
    public function getPhotoAttribute()
    {
        return fluent_boards_user_avatar($this->attributes['user_email'], $this->attributes['display_name']);
    }

    public function tasks()
    {
        return $this->belongsToMany(
            Task::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_USER_TASK_WATCH)
            ->withTimestamps();
    }

    public function watchingTasks()
    {
        return $this->belongsToMany(
            Task::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_USER_TASK_WATCH)
            ->withTimestamps();
    }

    public function highPriorityTasks()
    {
        return $this->tasks()
            ->where('is_archived', false)
            ->where('parent_id', null)
            ->where('priority', 'high')
            ->take(3);
    }

    public function overDueTasks()
    {
        return $this->tasks()
            ->where('is_archived', false)
            ->where('parent_id', null)
            ->overdue()
            ->orderBy('due_date', 'ASC')
            ->take(5);
    }

    public function upcomingTasks()
    {
        return $this->tasks()
            ->where('is_archived', false)
            ->where('parent_id', null)
            ->upcoming()
            ->orderBy('due_date', 'ASC')
            ->take(3);
    }

    public function upcomingWithoutDuedate()
    {
        return $this->tasks()
            ->where('is_archived', false)
            ->where('parent_id', null)
            ->whereNull('due_date')
            ->orderBy('due_date', 'ASC')
            ->take(3);
    }

    public function boards()
    {
        return $this->belongsToMany(
            Board::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )
            ->wherePivot('object_type', 'board_user')
            ->withTimestamps()
            ->withPivot('settings', 'preferences');
    }

    public function whichBoards()
    {
        return $this->belongsToMany(
            Board::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )
            ->wherePivot('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->withPivot('settings', 'preferences')
            ->withTimestamps();
    }


    public function boardUser()
    {
//        return $this->hasMany(BoardUser::class);
        return null;

    }

    public function notifications()
    {
        return $this->belongsToMany(
            Notification::class,
            'fbs_notification_users',
            'user_id',
            'notification_id'
        )->withTimestamps()
            ->withPivot('marked_read_at');
    }
    public function assignedTasks()
    {
        return $this->belongsToMany(
            Task::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_TASK_ASSIGNEE)
            ->withTimestamps();
    }

    public function mentionedTasks()
    {
        return Task::whereHas('notifications', function ($query) {
            $query->where('action', 'task_comment_mentioned')
                ->whereHas('users', function ($q) {
                    $q->where('user_id', $this->ID);
                });
        });
    }
}
