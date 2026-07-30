<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;
use FluentBoards\Framework\Database\Orm\Builder;
use FluentBoards\Framework\Support\Arr;

class Folder extends Model
{
    protected $table = 'fbs_boards';

    protected $guarded = ['id'];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $appends = ['meta'];

    public static function boot()
    {
        static::creating(function ($model) {
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->type = $model->type ?: Constant::OBJECT_TYPE_FOLDER;
        });

        parent::boot();
        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', Constant::OBJECT_TYPE_FOLDER);
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

    public function setBackgroundAttribute($background)
    {
        $this->attributes['background'] = \maybe_serialize($background);
    }

    public function getBackgroundAttribute($background)
    {
        return \maybe_unserialize($background);
    }

    public function getMetaAttribute()
    {
        $meta = Meta::where('object_id', $this->id)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->get();

        $formattedMeta = [];
        foreach ($meta as $item) {
            $formattedMeta[$item->key] = $item->value;
        }

        return $formattedMeta;
    }

    public function parentFolder()
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function subFolders()
    {
        return $this->hasMany(Folder::class, 'parent_id')
            ->whereNull('archived_at');
    }

    public function boards()
    {
        return $this->belongsToMany(
            Board::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withTimestamps()
            ->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_FOLDER_BOARD);
    }

    public function toArray()
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'created_by' => $this->created_by,
            'boards_ids' => Arr::pluck($this->boards, 'id'),
        ];
    }
}
