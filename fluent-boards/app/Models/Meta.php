<?php

namespace FluentBoards\App\Models;

class Meta extends Model
{
    protected $table = 'fbs_metas';

    protected $guarded = ['id'];

    public function setValueAttribute($value)
    {
        $this->attributes['value'] = \maybe_serialize($value);
    }

    public function getValueAttribute($value)
    {
        return \maybe_unserialize($value);
    }
}
