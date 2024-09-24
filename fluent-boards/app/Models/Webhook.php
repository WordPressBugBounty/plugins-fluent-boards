<?php

namespace FluentBoards\App\Models;

class Webhook extends Meta
{
    protected $fillable = [
        'object_id',
        'object_type',
        'key',
        'value',
    ];

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope('type', function ($builder) {
            $builder->where('object_type', '=', 'webhook');
        });
    }

    public function getFields()
    {
        $taskFields = [
            'fields' => [],
        ];

        foreach (Task::mappableFields() as $key => $column) {
            $taskFields['fields'][] = ['key' => $key, 'field' => $column];
        }

        return $taskFields;
    }

    public function getSchema()
    {
        $schema = [
            'name'      => '',
            'url'       => '',
        ];

        return $schema;
    }

    public function store($data)
    {
        return static::create([
            'object_type' => 'webhook',
            'key' => $key = wp_generate_uuid4(),
            'value' => array_merge($data, [
                'url' => site_url("?fbs=1&route=task&hash={$key}")
            ]),
        ]);
    }

    public function saveChanges($data)
    {
        $this->value = array_merge(
            $this->value,
            array_diff_key($data, [
                'id' => '', 'url' => ''
            ])
        );

        $this->save();

        return $this;
    }
}
