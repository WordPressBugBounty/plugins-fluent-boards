<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;

class Activity extends Model
{
    protected $table = 'fbs_activities';

    protected $guarded = ['id'];

    protected $fillable = [
        'object_id',
        'object_type',
        'action',
        'column',
        'old_value',
        'new_value',
        'description',
        'settings',
        'created_by'
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_by = $model->created_by ?: get_current_user_id();
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
     * One2One: Activity belongs to one Task
     * @return \FluentBoards\Framework\Database\Orm\Relations\BelongsTo
     */
    public function board()
    {
        return $this->belongsTo(Board::class, 'object_id', 'id');
    }

    /**
     * One2One: Activity belongs to one Task
     * @return \FluentBoards\Framework\Database\Orm\Relations\BelongsTo
     */
    public function task()
    {
        return $this->belongsTo(Task::class, 'object_id', 'id');
    }

    /**
     * One2One: Activity belongs to one User
     * @return \FluentBoards\Framework\Database\Orm\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by', 'ID');
    }

    public function scopeType($query, $type)
    {
        return $query->where('object_type', $type);
    }
}
