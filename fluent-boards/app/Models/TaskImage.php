<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;

class TaskImage extends Attachment
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
        return $query->where('object_type', 'COMMENT')->with('comment', function ($query) {
            $query->select('id', 'board_id');
        });
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

    /**
     * Scope a query to include tasks related to attachments and descriptions.
     *
     * This scope filters the query to include records where the `object_type`
     * is either `TASK_ATTACHMENT` or `TASK_DESCRIPTION`. Additionally, it
     * includes related task data by using the `with` method to eager load
     * the `task` relationship, but only selects specific columns (`id`,
     * `title`, `board_id`) for efficiency.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithTask($query)
    {
        return $query->where(function ($query) {
            $query->where('object_type', Constant::TASK_DESCRIPTION);
        })
            ->with(['task' => function ($query) {
                $query->select('id', 'title', 'board_id');
            }]);
    }


}

