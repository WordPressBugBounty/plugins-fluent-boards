<?php

namespace FluentBoards\App\Models;

class BoardTerm extends Model
{
    /*
     * Do not call this model directly,
     * use FluentBoards\App\Models\Stage for stages
     * or, FluentBoards\App\Models\Label for labels
     * or Extends this model for custom terms
     */
    protected $table = 'fbs_board_terms';

//    protected $guarded = ['id'];
    protected $fillable = ['board_id', 'title', 'slug', 'type', 'position', 'settings', 'color', 'bg_color', 'archived_at', 'updated_at'];

    public function setSettingsAttribute($settings)
    {
        $originalSettings = $this->getOriginal('settings');

        $originalSettings = \maybe_unserialize($originalSettings);

        foreach ($settings as $key => $value) {
            $originalSettings[$key] = $value;
        }

        $this->attributes['settings'] = \maybe_serialize($originalSettings);
    }

    public function getSettingsAttribute($settings)
    {
        return maybe_unserialize($settings);
    }

    public function board()
    {
        return $this->belongsTo(Board::class, 'board_id');
    }
}
