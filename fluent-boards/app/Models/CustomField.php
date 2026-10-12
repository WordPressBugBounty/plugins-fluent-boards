<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Models\Board;
use FluentBoards\App\Models\BoardTerm;
use FluentBoards\App\Models\Task;
use FluentBoards\Framework\Database\Orm\Builder;
use FluentBoards\App\Services\Constant;

/** Store custom-field data so native imports do not require the Pro addon. */
class CustomField extends BoardTerm
{
    /** Scope custom fields to their shared board-term type. */
    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->type = $model->type ?: Constant::BOARD_TERM_CUSTOM_FIELD;
        });

        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', '=', Constant::BOARD_TERM_CUSTOM_FIELD);
        });
    }

    /** Return tasks using this custom field. */
    public function tasks()
    {
        return $this->belongsToMany(
            Task::class,
            'fbs_relations',
            'foreign_id',
            'object_id'
        )->withTimestamps()
            ->wherePivot('object_type', Constant::TASK_CUSTOM_FIELD)
            ->withPivot('settings', 'preferences');
    }

    /** Return the board that owns this field. */
    public function board()
    {
        return $this->belongsTo(Board::class, 'board_id', 'id');
    }
}