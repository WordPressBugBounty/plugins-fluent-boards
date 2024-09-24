<?php

namespace FluentBoards\App\Models;

class Attachment extends Model
{
    protected $table = 'fbs_attachments';

    protected $guarded = ['id'];
    protected $fillable = ['file_hash', 'object_type', 'object_id', 'settings', 'file_path', 'full_url', 'file_size', 'attachment_type'];
//    protected $hidden = ['full_url', 'file_path'];

    protected $appends = ['secure_url'];

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

}