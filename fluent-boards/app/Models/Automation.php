<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Hooks\Handlers\AutomationHandler;
use FluentBoards\App\Services\Constant;

class Automation extends Model
{
    protected $table = 'fbs_automations';

    protected $guarded = ['id'];

    public static function boot()
    {
        parent::boot();

        // Keep the active-trigger cache in step. Runs save last_ran_at on every run; skip those.
        $refresh = function () {
            AutomationHandler::refreshActiveTriggerNames();
        };
        static::created($refresh);
        static::deleted($refresh);
        static::updated(function ($model) use ($refresh) {
            if ($model->wasChanged(['status', 'trigger_name', 'trigger_names'])) {
                $refresh();
            }
        });
    }

    /** The board this automation watches; none when it runs on any board (board_id 0). */
    public function board()
    {
        return $this->belongsTo(Board::class, 'board_id', 'id');
    }

    public function setTriggerSettingsAttribute($value)
    {
        $this->attributes['trigger_settings'] = \maybe_serialize($value ?: []);
    }

    public function getTriggerSettingsAttribute($value)
    {
        return \maybe_unserialize($value) ?: [];
    }

    public function setStepsAttribute($value)
    {
        $this->attributes['steps'] = \maybe_serialize($value ?: []);
    }

    public function getStepsAttribute($value)
    {
        return \maybe_unserialize($value) ?: [];
    }

    public function setFlowAttribute($value)
    {
        $this->attributes['flow'] = \maybe_serialize($value ?: ['nodes' => [], 'edges' => []]);
    }

    public function getFlowAttribute($value)
    {
        return \maybe_unserialize($value) ?: ['nodes' => [], 'edges' => []];
    }

    public function setSettingsAttribute($value)
    {
        $this->attributes['settings'] = \maybe_serialize($value ?: []);
    }

    public function getSettingsAttribute($value)
    {
        return \maybe_unserialize($value) ?: [];
    }

    public function analytics()
    {
        return $this->hasMany(AutomationAnalytics::class, 'automation_id', 'id');
    }

    public function isActive()
    {
        return $this->status === Constant::AUTOMATION_STATUS_ACTIVE;
    }

    /**
     * Every trigger that starts this automation: the first one from the trigger columns,
     * then `settings.extra_triggers`. Each entry is ['id', 'name', 'settings'].
     *
     * @return array
     */
    public function getTriggerEntries()
    {
        $entries = [];

        if ($this->trigger_name) {
            $entries[] = [
                'id' => 'trigger',
                'name' => $this->trigger_name,
                'settings' => (array) $this->trigger_settings,
            ];
        }

        $settings = (array) $this->settings;
        foreach ((array) ($settings['extra_triggers'] ?? []) as $extra) {
            if (is_array($extra) && !empty($extra['name'])) {
                $entries[] = [
                    'id' => (string) ($extra['id'] ?? ''),
                    'name' => (string) $extra['name'],
                    'settings' => (array) ($extra['settings'] ?? []),
                ];
            }
        }

        return $entries;
    }
}
