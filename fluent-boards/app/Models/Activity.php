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

    /** Read full custom-field message attributes from their LONGTEXT payload; preserve legacy rows. */
    private function customFieldActivityAttribute($key, $fallback)
    {
        if (empty($this->settings['custom_field_activity_payload'])) {
            return $fallback;
        }

        $payload = json_decode($this->attributes['description'] ?? '', true);
        return is_array($payload) && array_key_exists($key, $payload) ? $payload[$key] : $fallback;
    }

    /** Resolve the stored label while preserving display translations applied by the activity service. */
    public function getColumnAttribute($value)
    {
        // Payload rows store this identifier until translateActivities assigns a display label.
        if ($value !== Constant::ACTIVITY_CUSTOM_FIELD) {
            return $value;
        }

        return $this->customFieldActivityAttribute('column', $value);
    }

    /** Restore the complete previous value for all activity feeds and serialized responses. */
    public function getOldValueAttribute($value)
    {
        return $this->customFieldActivityAttribute('old_value', $value);
    }

    /** Restore the complete new value, including null removals and zero values. */
    public function getNewValueAttribute($value)
    {
        return $this->customFieldActivityAttribute('new_value', $value);
    }

    /** Keep storage details out of the user-facing activity description. */
    public function getDescriptionAttribute($value)
    {
        return $this->customFieldActivityAttribute('description', $value);
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
