<?php

namespace FluentBoards\App\Models;

class NotificationUser extends Model
{
    protected $table = 'fbs_notification_users';

    protected $guarded = ['id'];

    protected $fillable = [
        'notification_id',
        'user_id',
        'marked_read_at'
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_at = current_time('mysql');
            $model->updated_at = current_time('mysql');
        });
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class,'notification_id','id');
    }

}
