<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;
use FluentBoards\App\Services\Helper;
use FluentBoards\App\Services\TaskService;
use FluentBoards\Framework\Database\Orm\Builder;
use FluentBoardsPro\App\Models\TaskAttachment;
use FluentBoardsPro\App\Models\CustomField;
use FluentBoardsPro\App\Services\Constant as ProConstant;
use FluentCrm\App\Models\Subscriber;

class Task extends Model
{
    protected $table = 'fbs_tasks';

    protected $guarded = ['id'];

    protected $fillable = [
            'title',
            'slug',
            'board_id',
            'parent_id',
            'crm_contact_id',
            'type',
            'lead_value',
            'stage_id',
            'status',
            'reminder_type',
            'priority',
            'archived_at',
            'remind_at',
            'source',
            'source_id',
            'description',
            'lead_value',
            'events',
            'settings',
            'due_at',
            'started_at',
            'last_completed_at',
            'position',
            'comments_count',
            'created_by',
        ];

    protected $appends = ['meta', 'repeat_task_meta'];

    protected static $skipTaskCreatedEvent = false;

    public static function withoutTaskCreatedEvent($callback)
    {
        static::$skipTaskCreatedEvent = true;
        $result = $callback();
        static::$skipTaskCreatedEvent = false;
        return $result;
    }

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $board = Board::find($model->board_id);
            $model->created_by = $model->created_by ?: get_current_user_id();
            $model->type       = $board->type === 'roadmap' ? 'roadmap' : 'task'; // default task type is task

            if (empty($model->slug)) {
                $model->slug = sanitize_title($model->title, 'idea-'.time());
            }

            $model->settings = $model->settings
                ?: [
                    'cover'            => [
                        'backgroundColor' => '',
                    ],
                    'subtask_count'    => 0,
                    'attachment_count' => 0,
                    'subtask_completed_count' => 0,
                ];
            $model->position = $model->position
                ?: (new TaskService())->getLastPositionOfTasks($model->stage_id);
        });
        static::created(function ($model) {
            if (!$model->parent_id && !static::$skipTaskCreatedEvent) {
                do_action('fluent_boards/task_created', $model);
                if ($model->crm_contact_id) {
                    do_action('fluent_boards/contact_added_to_task', $model);
                }
            } else {
                self::adjustSubtaskCount($model->parent_id);
            }
        });

        /* global scope for task type which means only type = task will be fetched from everywhere in  */
        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', '=', 'task')
                    ->orWhere('type', '=', 'roadmap');
        });
    }


    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * scope of getting past due not completed tasks
     *
     * @param  $query \FluentBoards\Framework\Database\Query\Builder
     *
     * @return \FluentBoards\Framework\Database\Query\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->whereNull('last_completed_at')
                     ->where('status', 'open')
                     ->where('due_at', '<=', current_time('mysql'));
    }

    /**
     * scope of getting upcoming tasks
     *
     * @param  $query \FluentBoards\Framework\Database\Query\Builder
     *
     * @return \FluentBoards\Framework\Database\Query\Builder
     */
    public function scopeUpcoming($query)
    {
        return $query->where('status', 'open')
                     ->where('due_at', '>=', current_time('mysql'));
    }


    public function setSettingsAttribute($settings)
    {
        $this->attributes['settings'] = \maybe_serialize($settings);
    }

    public function getSettingsAttribute($settings)
    {
        return \maybe_unserialize($settings);
    }


    /**
     * One2Many: Task has many activities
     *
     * @return \FluentBoards\Framework\Database\Orm\Relations\hasMany
     */
    public function activities()
    {
        return $this->hasMany(Activity::class, 'object_id', 'id')
                    ->where('object_type', Constant::ACTIVITY_TASK)
                    ->orderBy('id', 'DESC');
    }

    //->orderBy('id', 'DESC')

    /**
     * One2Many: Task has many activities
     *
     * @return \FluentBoards\Framework\Database\Orm\Relations\hasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'task_id', 'id')
                    ->where('type', 'comment')
                    ->where('parent_id', null);
    }

    /**
     * One2Many: Task has many notifications
     *
     * @return \FluentBoards\Framework\Database\Orm\Relations\hasMany
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class, 'task_id', 'id');
    }

    /**
     * One2Many: Task has many activities
     *
     * @return \FluentBoards\Framework\Database\Orm\Relations\hasMany
     */
    public function public_comments() // for primarily - roadmap plugin
    {
        return $this->hasMany(Comment::class, 'task_id', 'id')
                    ->where('privacy', 'public')
                    ->where('status', 'published');
    }

    public function board()
    {
        return $this->belongsTo(Board::class, 'board_id', 'id');
    }

    public function assignees()
    {
        return $this->belongsToMany(
            User::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withPivot('settings', 'preferences')
                    ->wherePivot('object_type',
                        Constant::OBJECT_TYPE_TASK_ASSIGNEE)
                    ->withTimestamps();
    }

    public function labels()
    {
        return $this->belongsToMany(
            BoardTerm::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withPivot('settings')
                    ->wherePivot('object_type',
                        Constant::OBJECT_TYPE_TASK_LABEL)
                    ->withTimestamps();
    }

    public function attachments() //may not need in future
    {
        return $this->hasMany(TaskAttachment::class,
            'object_id', 'id')
                    ->where('object_type', Constant::OBJECT_TYPE_TASK);
    }

    public function watchers()
    {
        return $this->belongsToMany(
            User::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withPivot('settings')
                    ->wherePivot('object_type',
                        Constant::OBJECT_TYPE_USER_TASK_WATCH)
                    ->withTimestamps();
    }

    public function parentTask($id)
    {
        return self::find($id);
    }

    public function contact()
    {
        return $this->belongsTo(Subscriber::class, 'crm_contact_id', 'id');
    }

    public function taskMeta()
    {
        return $this->hasMany(TaskMeta::class, 'task_id', 'id');
    }

    public function getPopularCount()
    {
        $interactions = $this->taskMeta()->get()->pluck('value', 'key');
        if($interactions) {
            return ($interactions['upvote'] ?? 0) + ($interactions['comments_count'] ?? 0);
        } else {
            return 0;
        }
    }

    public function getMetaAttribute()
    {
        return $this->taskMeta()->get()->pluck('value', 'key');
    }

    public function stage()
    {
        return $this->belongsTo(Stage::class, 'stage_id', 'id');
    }

    public function isOverdue()
    {
        return $this->last_completed_at == null && $this->due_at
               && strtotime($this->due_at, current_time('timestamp'))
                  <= current_time('timestamp');
    }

    public function upcoming()
    {
        return $this->last_completed_at == null && $this->due_at
               && strtotime($this->due_at, current_time('timestamp'))
                  >= current_time('timestamp');
    }

    public function isWatching()
    {
        $userId      = get_current_user_id();
        $is_watching = false;
        foreach ($this->watchers as $watcher) {
            if ($watcher->ID == $userId) {
                $is_watching = true;
            }
        }

        return $is_watching;
    }

    public function createTask($data)
    {
        $data = apply_filters('fluent_boards/before_task_create', $data);
        $createdTask = Task::create($data);
    
        if ( ! empty($data['assignees'])) {
            $assignees = array_filter(array_map('intval', $data['assignees']));
            if ($assignees) {
                $assigneeData = array_fill_keys($assignees,
                    ['object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE]);
                $createdTask->assignees()->syncWithoutDetaching($assigneeData);
                
                // Add assignees as watchers
                foreach ($assignees as $assigneeId) {
                    $createdTask->watchers()->syncWithoutDetaching([
                        $assigneeId => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]
                    ]);
                }
            }
        }

        if ( ! empty($data['labels'])) {
            $labels = array_filter(array_map('intval', $data['labels']));
            if ($labels) {
                foreach ($labels as $label) {
                    $createdTask->labels()->syncWithoutDetaching([$label => ['object_type' => Constant::OBJECT_TYPE_TASK_LABEL]]);
                }
            }
        }

        return $createdTask;
    }

    public function addOrRemoveAssignee($idToAddOrRemove)
    {
        $oldAssigneeIds    = $this->assignees->pluck('ID')->toArray();
        $IfAlreadyAssignee = in_array($idToAddOrRemove, $oldAssigneeIds);
        $operation         = 'added';

        if ($IfAlreadyAssignee) { // if already an assignee then it is a remove operation
            $this->assignees()->detach($idToAddOrRemove);
            $this->watchers()->detach($idToAddOrRemove);
            $operation = 'removed';
        } else { //else an add operation
            $this->assignees()
                 ->syncWithoutDetaching([$idToAddOrRemove => ['object_type' => Constant::OBJECT_TYPE_TASK_ASSIGNEE]]);
            $this->watchers()
                 ->syncWithoutDetaching([$idToAddOrRemove => ['object_type' => Constant::OBJECT_TYPE_USER_TASK_WATCH]]);
        }

        return $operation;
    }

    public function getArchivedAttribute()
    {
        return (bool) $this->attributes['is_archived'];
    }

    public function setArchivedAttribute($value)
    {
        if (true === $value) {
            $this->attributes['is_archived'] = 1;
        } elseif (false === $value) {
            $this->attributes['is_archived'] = 0;
        } else {
            $this->attributes['is_archived'] = in_array($value, [0, 1]) ? $value
                : 0;
        }
    }

    public function user($id)
    {
        return User::findOrFail($id);
    }

    /**
     * from now(01/03/24) we will use Helper::crm_contact() method
     */
    public static function lead_contact($id)
    {
        if ( ! defined('FLUENTCRM')) {
            return '';
        }

        $contact = \FluentCrm\App\Models\Subscriber::with(['tags', 'lists'])
                                                   ->find($id);

        if ( ! $contact) {
            return null;
        }

        return [
            'id'              => $contact->id,
            'email'           => $contact->email,
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'full_name'       => $contact->full_name,
            'avatar'          => $contact->avatar,
            'photo'           => $contact->photo,
            'status'          => $contact->status,
            'contact_type'    => $contact->contact_type,
            'last_activity'   => $contact->last_activity,
            'life_time_value' => $contact->life_time_value,
            'total_points'    => $contact->total_points,
            'user_id'         => $contact->user_id,
            'created_at'      => $contact->created_at,
            'tags'            => Helper::getIdTitleArray($contact->tags),
            'lists'           => Helper::getIdTitleArray($contact->lists),

        ];

    }

    public function getMeta($key, $default = null)
    {
        $exist = TaskMeta::where('task_id', $this->id)->where('key', $key)
                         ->first();
        if ($exist) {
            return $exist->value;
        }

        return $default;
    }

    public function updateMeta($key, $value)
    {
        $exist = TaskMeta::where('task_id', $this->id)->where('key', $key)
                         ->first();

        if ($exist) {
            $exist->value = $value;
            $exist->save();
        } else {
            $exist = TaskMeta::create([
                'task_id' => $this->id,
                'key'     => $key,
                'value'   => $value,
            ]);
        }

        return $exist;
    }

    public function moveToNewPosition($newIndex)
    {
        $newIndex = (int) $newIndex;
        if ($newIndex < 1) {
            $newIndex = 1;
        }

        // Declaring query for subtask or task
        if (isset($this->parent_id)) {
            $taskQuery = self::where('parent_id', $this->parent_id)
                             ->whereNull('archived_at');
        } else {
            $taskQuery = self::where('stage_id', $this->stage_id)
                             ->whereNull('archived_at');
        }


        if ($newIndex == 1) {
            $firstItem = $taskQuery->where('id', '!=', $this->id)
                                   ->orderBy('position', 'asc')
                                   ->first();

            if ($firstItem) {
                if ($firstItem->position < 0.02) {
                    self::reIndexTasksPositions($this->toArray());

                    return $this->moveToNewPosition($newIndex);
                }
                $index = round($firstItem->position / 2, 2);
            } else {
                $index = 1;
            }

            $this->position = $index;
            $this->save();

            return $this;
        }

        $prevTask = $taskQuery
            ->offset($newIndex - 2)
            ->where('id', '!=', $this->id)
            ->orderBy('position', 'asc')
            ->first();

        if ( ! $prevTask) {
            return $this->moveToNewPosition(1);
        }

        $nextItem = $taskQuery
            ->offset($newIndex - 1)
            ->where('id', '!=', $this->id)
            ->orderBy('position', 'asc')
            ->first();

        if ( ! $nextItem) {
            $this->position = $prevTask->position + 1;
            $this->save();

            return $this;
        }

        $newPosition = ($prevTask->position + $nextItem->position) / 2;

        // check if new position is already taken
        $exist = $taskQuery
            ->where('position', $newPosition)
            ->where('id', '!=', $this->id)
            ->first();

        if ($exist) {
            self::reIndexTasksPositions($this->toArray());

            return $this->moveToNewPosition($newIndex);
        }

        $this->position = $newPosition;
        $this->save();

        return $this;
    }

    public static function reIndexTasksPositions($task)
    {
        if (isset($task['parent_id'])) {
            $tasksQuery = self::where('parent_id', $task['parent_id']);
        } else {
            $tasksQuery = self::where('stage_id', $task['stage_id']);
        }
        $allTasks = $tasksQuery->orderBy('position', 'asc')
                               ->whereNull('archived_at')->get();

        foreach ($allTasks as $index => $task) {
            $task->position = $index + 1;
            $task->save();
        }
    }


    public static function adjustSubtaskCount($subTaskParentId)
    {
        if ( ! $subTaskParentId) {
            return;
        }
        $parentTask = Task::find($subTaskParentId);
        if( !$parentTask) {
            return;
        }
        $subtasks = $parentTask->subtasks;
        $parentTaskSettings   = $parentTask->settings;
        $parentTaskSettings['subtask_count'] = $subtasks->count();
        $parentTaskSettings['subtask_completed_count'] = $subtasks->filter(function ($subtask) {
            return $subtask->status === 'closed';
        })->count();
        $parentTask->settings = $parentTaskSettings;
        $parentTask->save();
    }

    public function close()
    {
        if ($this->status == 'closed') {
            return $this;
        }

        $this->status            = 'closed';
        $this->last_completed_at = current_time('mysql');
        $this->save();
        if ($this->parent_id) {
            self::adjustSubtaskCount($this->parent_id);
        }
        return $this;
    }

    public function reopen()
    {
        if ($this->status == 'open') {
            return $this;
        }

        $this->status            = 'open';
        $this->last_completed_at = null;
        $this->save();
        if ($this->parent_id) {
            self::adjustSubtaskCount($this->parent_id);
        }
        return $this;
    }

    /*
        * Get all the fields that can be mapped
        * to Task Creation Webhook or
        * REST API Task Creation
        * PHP API Task Creation
        * @return array
    */
    public static function mappables()
    {
        return [
            'task_title'     => __('Task Title', 'fluent-boards'),
            'slug'           => __('Slug', 'fluent-boards'),
            'board_title'    => __('Board Title', 'fluent-boards'),
            'status'         => __('Status', 'fluent-boards'),
            'type'           => __('Type', 'fluent-boards'),
            'description'    => __('Description', 'fluent-boards'),
            'priority'       => __('Priority', 'fluent-boards'),
            'due_at'         => __('Due Date', 'fluent-boards'),
            'started_at'     => __('Start Date', 'fluent-boards'),
            'archived_at'    => __('Archive Date', 'fluent-boards'),
            'stage'          => __('Stage', 'fluent-boards'),
            'board'          => __('Board', 'fluent-boards'),
            'source'         => __('Source', 'fluent-boards'),
            'position'       => __('Position', 'fluent-boards'),
            'subtasks'       => __('Subtasks', 'fluent-boards'),
            'completion'     => __('Completion', 'fluent-boards'),
        ];
    }
    public static function mappableFields()
    {
        $fields = [
            'title' => [
                'field' => __('Title', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'required',
                'description' => __('Title of the task.', 'fluent-boards'),
            ],
            'stage' => [
                'field' => __('Stage', 'fluent-boards'),
                'type'  => 'int|text',
                'rules' => 'optional',
                'description' => __('The stage of the task, which can be an ID, title, or slug. Example: 1 | "open" | "Open"', 'fluent-boards'),
            ],
            'parent_id' => [
                'field' => __('Parent Task', 'fluent-boards'),
                'type'  => 'int',
                'rules' => 'optional',
                'description' => __('Parent Task ID of the subtask. Example: 1', 'fluent-boards'),
            ],
            'status' => [
                'field' => __('Status', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('The status of the task (open | closed). Example: "closed"', 'fluent-boards'),
            ],
            'description' => [
                'field' => __('Description', 'fluent-boards'),
                'type'  => 'textarea',
                'rules' => 'optional',
                'description' => __('Description of the task', 'fluent-boards'),
            ],
            'priority' => [
                'field' => __('Priority', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('Priority of the task (low | medium | high). Example: "medium" ', 'fluent-boards'),
            ],
            'due_at' => [
                'field' => __('Due Date', 'fluent-boards'),
                'type'  => 'date',
                'rules' => 'optional',
                'description' => __('The due date of the task in the format YYYY-MM-DD hh:mm. Example: 2099-12-31 23:59:59', 'fluent-boards'),
            ],
            'started_at' => [
                'field' => __('Start Date', 'fluent-boards'),
                'type'  => 'date',
                'rules' => 'optional',
                'description' => __('The start date of the task in the format YYYY-MM-DD. Example: 2099-12-31', 'fluent-boards'),
            ],
            'source' => [
                'field' => __('Source', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('The source of the task. Example: "jira"', 'fluent-boards'),
            ],
            'source_id' => [
                'field' => __('Source Id', 'fluent-boards'),
                'type'  => 'text|int',
                'rules' => 'optional',
                'description' => __('The source Id of the task (if any). Example: "bcy664fh177"', 'fluent-boards'),
            ],
            'crm_contact_id' => [
                'field' => __('CRM Contact Id', 'fluent-boards'),
                'type'  => 'int',
                'rules' => 'optional',
                'description' => __('The ID of the associated FluentCRM contact. Example: 6465', 'fluent-boards'),
            ],
            'contact_email' => [
                'field' => __('CRM Contact Email', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('The email of the associated CRM contact. Example: "john.doe@example.com"', 'fluent-boards'),
            ],
            'contact_first_name' => [
                'field' => __('Contact First Name', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('Associated CRM Contact First Name. Example: "John"', 'fluent-boards'),
            ],
            'contact_last_name' => [
                'field' => __('Contact Last Name', 'fluent-boards'),
                'type'  => 'text',
                'rules' => 'optional',
                'description' => __('Associated CRM Contact Last Name', 'fluent-boards'),
            ],
            'labels' => [
                'field' => __('Labels', 'fluent-boards'),
                'type'  => 'text|int',
                'rules' => 'optional',
                'description' => __('An array of label IDs or titles. Example: [1, "feature", 44]', 'fluent-boards'),
            ],
            'assignees' => [
                'field' => __('Assignees', 'fluent-boards'),
                'type'  => 'text|int',
                'rules' => 'optional',
                'description' => __('An array of WP User IDs. Example: [1,2,44]', 'fluent-boards'),
            ]
        ];

        return $fields;
    }

    public function customFields()
    {
        return $this->belongsToMany(
            CustomField::class,
            'fbs_relations',
            'object_id',
            'foreign_id'
        )->withPivot('settings')
            ->wherePivot('object_type', ProConstant::TASK_CUSTOM_FIELD)
            ->withTimestamps();
    }


    public function repeatTaskMeta()
    {
        return $this->hasOne(Meta::class, 'object_id', 'id')->where('object_type', Constant::REPEAT_TASK_META);
    }
    public function getRepeatTaskMetaAttribute()
    {
        return $this->repeatTaskMeta()->first();
    }
    public function taskCustomFields()
    {
        return $this->hasMany(Relation::class, 'object_id', 'id')
            ->where('object_type', Constant::TASK_CUSTOM_FIELD);
    }

    public function subtasks()
    {
        return $this->hasMany(Task::class, 'parent_id', 'id');
    }

    public function subtaskGroup()
    {
        return $this->hasMany(TaskMeta::class, 'task_id')->where('key', Constant::SUBTASK_GROUP_NAME);
    }

}
