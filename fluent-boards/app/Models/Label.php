<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;
use FluentBoards\Framework\Database\Orm\Builder;

class Label extends BoardTerm
{
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->type = $model->type ?: 'label'; // default type is label
        });

        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', '=', 'label');
        });
    }

    public function tasks()
    {
        return $this->belongsToMany(
            Task::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )->withTimestamps()->wherePivot('object_type', Constant::OBJECT_TYPE_TASK_LABEL)->withPivot('settings', 'preferences');
    }
}
