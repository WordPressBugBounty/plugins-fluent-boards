<?php

namespace FluentBoards\App\Models;

use FluentBoards\Framework\Database\Orm\Relations\BelongsTo;

class Team extends Model
{
    protected $table = 'fbs_teams';

    protected $guarded = ['id'];

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    /**
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(Team::class, 'parent_id');
    }
}