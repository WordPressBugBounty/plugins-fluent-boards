<?php

namespace FluentBoards\App\Models;

class Relation extends Model
{
    protected $table = 'fbs_relations';

    protected $guarded = ['id'];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    public function setPreferencesAttribute($preferences)
    {
        $this->attributes['preferences'] = \maybe_serialize($preferences);
    }

    public function getPreferencesAttribute($preferences)
    {
        return \maybe_unserialize($preferences);
    }
}
