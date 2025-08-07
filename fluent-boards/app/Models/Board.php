<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\PermissionManager;
use FluentBoards\App\Services\UserService;
use FluentBoards\Framework\Database\Orm\Builder;
use FluentBoardsPro\App\Models\CustomField;

class Board extends Model
{
    protected $table = 'fbs_boards';

    protected $guarded = ['id'];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'parent_id',
        'title',
        'description',
        'type',
        'currency',
        'background',
        'settings',
        'created_by',
        'archived_at',
    ];

    protected $appends = ['meta', 'isUserOnlyViewer'];

    public static function boot()
    {
        static::creating(function ($model) {
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->type = $model->type ?: 'to-do'; // default board type is to-do
            $model->background = $model->background ?: $model->randomBackground();
        });
        /* global scope for board type which means only type = to-do will be fetched from everywhere   */
        parent::boot();
        static::addGlobalScope('type', function (Builder $builder) {
            $builder = $builder->where('type', '=', 'to-do')
                ->orWhere('type', '=', 'roadmap');
        });
    }

    public static function getColor()
    {
        $colors = [
            '#673AB7', // deep purple
            '#3F51B5',  // indigo
            '#14508C',  // blue
            '#009688',  // teal
            '#519839',  // green
            '#795548',  // brown
            '#607D8B',  // blue grey
            '#03A9F4',  // light blue
            '#00BCD4',  // cyan
            '#CDDC39',  // lime
            '#838c91',  // grey
        ];

        return $colors[array_rand($colors)];
    }

    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }

    public function getMetaAttribute()
    {
        return $this->getMeta();
    }

    public function setBackgroundAttribute($background)
    {
        $this->attributes['background'] = \maybe_serialize($background);
    }

    public function getBackgroundAttribute($background)
    {
        return \maybe_unserialize($background);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'board_id');
    }

    public function completedTasks()
    {
        return $this->hasMany(Task::class, 'board_id')
                    ->whereNull('archived_at')
                    ->where('parent_id', null)
                    ->where('status', 'closed');
    }

    public function stages()
    {
        return $this->hasMany(Stage::class, 'board_id')
            ->whereNull('archived_at')
            ->orderBy('position', 'asc');
    }

    public function labels()
    {
        return $this->hasMany(Label::class, 'board_id')
            ->whereNull('archived_at')
            ->orderBy('position', 'asc');
    }

    public function users()
    {
        return $this->belongsToMany(
            User::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withPivot('settings','preferences')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->withTimestamps();
    }


    public function boardUserEmailNotificationSettings()
    {
        return $this->belongsToMany(
            User::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withTimestamps()
            ->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_BOARD_USER_EMAIL_NOTIFICATION);
    }

    public function boardUserNotificationSettings() //will delete later
    {
        return $this->belongsToMany(
            User::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withTimestamps()
            ->withPivot('settings')
            ->wherePivot('object_type', Constant::OBJECT_TYPE_BOARD_USER_NOTIFICATION);
    }

    public function syncUsers($userIds)
    {
        $exists = $this->users;
        $existIds = [];
        foreach ($exists as $exist) {
            $existIds[] = $exist->ID;
        }

        $newIds = array_diff($userIds, $existIds);

        if ($newIds) {
            $this->users()->attach(
                $newIds,
                [
                    'object_type' => Constant::OBJECT_TYPE_BOARD_USER,
                    'settings'    => maybe_serialize(Constant::BOARD_USER_SETTINGS),
                    'preferences' => maybe_serialize(Constant::BOARD_NOTIFICATION_TYPES)
                ]
            );
        }

        return $newIds;
    }

    public static function isBoardExists($boardId)
    {
        return self::where('id', $boardId)->exists();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'object_id', 'id')
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_NOTIFICATION);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'board_id', 'id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByAccessUser($query, $userId)
    {
        if (PermissionManager::isAdmin($userId)) {
            return $query;
        }

        $user = User::find($userId);

        $boardIds = $user->whichBoards()->pluck('object_id')->toArray();

        if (!$boardIds) {
            return $query->where('id', 0);
        }

        return $query->whereIn('id', $boardIds);
    }
    private function randomBackground()
    {
        $solids = Constant::BOARD_BACKGROUND_DEFAULT_SOLID_COLORS;
        $solid = $solids[wp_rand(0, count($solids) - 1)];

        return [
            'id' => $solid['id'],
            'is_image' => false,
            'image_url' => null,
            'color' => $solid['value']
        ];
    }

    public function getUsers()
    {
        return (new UserService())->allFluentBoardsUsers($this->id);
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class, 'board_id')
            ->whereNull('archived_at')
            ->orderBy('position', 'asc');
    }

    public function getMeta() // get Board Meta only
    {
        $meta = Meta::where('object_id', $this->id)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->get();

        $formattedMeta = [];

        foreach ($meta as $m) {
            $formattedMeta[$m->key] = $m->value;
        }

        return $formattedMeta;
    }

   
    
    public function getMetaByKey($key) // get Board Meta only
    {
        $meta = Meta::where('object_id', $this->id)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', $key)
            ->first();

        return $meta->value ?? null;
    }

    public function updateMeta($key, $value)
    {
        $meta = Meta::where('object_id', $this->id)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD)
            ->where('key', $key)
            ->first();

        if ($meta) {
            $meta->value = $value;
            $meta->save();
        } else {
            $meta = Meta::create([
                'object_id'   => $this->id,
                'object_type' => Constant::OBJECT_TYPE_BOARD,
                'key'         => $key,
                'value'       => $value,
            ]);
        }

        return $meta;

    }


    public function activities()
    {
        return $this->hasMany(Activity::class, 'object_id')
                    ->where('object_type', Constant::ACTIVITY_BOARD);
    }

    public function getisUserOnlyViewerAttribute()
    {
        $userId = get_current_user_id();
        if(PermissionManager::isAdmin()) {
            return false;
        }
        $boardPermissions = Relation::where('object_id', $this->id)
            ->where('foreign_id', $userId)
            ->where('object_type', Constant::OBJECT_TYPE_BOARD_USER)
            ->first();

        return $boardPermissions->settings['is_viewer_only'] ?? false;
    }

    public function removeBoardFromFolder()
    {
        $relation = Relation::where('object_type', 'FluentBoardsPro\App\Models\Folder')
            ->where('foreign_id', $this->id)
            ->first();

        if (!$relation) {
            return;
        }
        $relation->delete();

    }

}
