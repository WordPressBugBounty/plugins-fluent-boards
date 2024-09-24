<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;

class Notification extends Model
{
    protected $table = 'fbs_notifications';

    protected $guarded = ['id'];

    protected $fillable = [
        'object_id',
        'object_type',
        'activity_by',
        'task_id',
        'action',
        'description',
        'settings'
    ];
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->activity_by = $model->activity_by ?: get_current_user_id();
            $model->created_at = current_time('mysql');
            $model->updated_at = current_time('mysql');
        });
    }

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    /**
     * One2One: Notification belongs to an activitist
     *
     * @return \FluentBoards\Framework\Database\Orm\Relations\BelongsTo
     */
    public function activitist()
    {
        return $this->belongsTo(User::class, 'activity_by', 'ID');
    }

    public function board()
    {
        return $this->belongsTo(Board::class, 'object_id', 'id');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'fbs_notification_users',
            'notification_id',
            'user_id'
        )->withTimestamps()
            ->withPivot('marked_read_at');
    }

    public function task()
    {
        return $this->belongsTo(Task::class, 'task_id', 'id')->with('stage', 'board');
    }

    public function checkReadOrNot()
    {
        $user = wp_get_current_user();
        $userNotification = NotificationUser::where('user_id', $user->ID)
                                        ->where('notification_id', $this->id)
                                        ->first();
        $marked_read_at = $userNotification->marked_read_at;

        return $marked_read_at ? true : false;


    }

}
