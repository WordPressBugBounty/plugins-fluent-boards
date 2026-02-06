<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Models\Comment;
use FluentBoards\App\Models\Task;
use FluentBoards\App\Services\Constant;

class CommentImage extends Attachment
{
    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $uid = wp_generate_uuid4();
            $model->file_hash = md5($uid . wp_rand(0, 1000));
        });
    }

    public function scopeWithComment($query)
    {
        return $query->where('object_type', Constant::COMMENT_IMAGE)->with('comment', function ($query) {
            $query->select('id', 'board_id');
        });
    }

    public function comment()
    {
        return $this->belongsTo(Comment::class, 'object_id', 'id');
    }

    public function getSecureUrlAttribute()
    {
        if ($this->attachment_type === 'url') {
            return $this->full_url;
        }
        return add_query_arg([
            'fbs'               => 1,
            'fbs_comment_image'    => $this->file_hash,
            'secure_sign' => md5($this->id . gmdate('YmdH'))
        ], site_url('/index.php'));
    }


}

