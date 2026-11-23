<?php

namespace FluentBoards\App\Models;

class AutomationAnalytics extends Model
{
    protected $table = 'fbs_automation_analytics';

    protected $guarded = ['id'];

    protected $nullableTimestampAttributes = [
        'resume_at',
        'completed_at',
    ];

    public function setTriggerPayloadAttribute($value)
    {
        $this->attributes['trigger_payload'] = \maybe_serialize($value ?: []);
    }

    public function getTriggerPayloadAttribute($value)
    {
        return \maybe_unserialize($value) ?: [];
    }

    public function setStepResultsAttribute($value)
    {
        $this->attributes['step_results'] = \maybe_serialize($value ?: []);
    }

    public function getStepResultsAttribute($value)
    {
        return \maybe_unserialize($value) ?: [];
    }

    public function automation()
    {
        return $this->belongsTo(Automation::class, 'automation_id', 'id');
    }
}
