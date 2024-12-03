<?php

namespace FluentBoards\App\Models;

use FluentBoards\App\Services\Constant;
use FluentBoards\Framework\Database\Orm\Builder;
use FluentBoards\Framework\Support\Arr;

class Stage extends BoardTerm
{
//    static $type = 'stage'; // it was working but I need a documentation to understand it better

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->type = $model->type ?: 'stage'; // default type is stage
            $model->settings = $model->settings ?: [
                'default_task_status' => 'open',
                'is_template' => false,
            ];
        });

        static::addGlobalScope('type', function (Builder $builder) {
            $builder->where('type', '=', 'stage');
        });
    }

    public function defaultTaskStatus()
    {
        if(!$this->settings) {
            return 'open';
        }

        return Arr::get($this->settings, 'default_task_status') == 'open' ? 'open' : 'closed';
    }

    public function moveToNewPosition($newIndex)
    {
        $newIndex = (int)$newIndex;
        if ($newIndex < 1) {
            $newIndex = 1;
        }

        $stageQuery = self::where('board_id', $this->board_id)->whereNull('archived_at');


        if ($newIndex == 1) {
            $firstItem = $stageQuery->where('id', '!=', $this->id)
                ->orderBy('position', 'asc')
                ->first();

            if ($firstItem) {
                if ($firstItem->position < 0.02) {
                    self::reIndexStagesPositions($this->toArray());
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

        $prevTask = $stageQuery
            ->offset($newIndex - 2)
            ->where('id', '!=', $this->id)
            ->orderBy('position', 'asc')
            ->first();

        if (!$prevTask) {
            return $this->moveToNewPosition(1);
        }

        $nextItem = $stageQuery
            ->offset($newIndex - 1)
            ->where('id', '!=', $this->id)
            ->orderBy('position', 'asc')
            ->first();

        if (!$nextItem) {
            $this->position = $prevTask->position + 1;
            $this->save();
            return $this;
        }

        $newPosition = ($prevTask->position + $nextItem->position) / 2;

        // check if new position is already taken
        $exist = $stageQuery
            ->where('position', $newPosition)
            ->where('id', '!=', $this->id)
            ->first();

        if ($exist) {
            self::reIndexStagesPositions($this->toArray());
            return $this->moveToNewPosition($newIndex);
        }

        $this->position = $newPosition;
        $this->save();
        return $this;
    }

    public static function reIndexStagesPositions($stage)
    {
        $allStages =  self::where('board_id', $stage['board_id'])->where('type', 'stage')->orderBy('position', 'asc')->whereNull('archived_at')->get();

        foreach ($allStages as $index => $stage) {
            $stage->position = $index + 1;
            $stage->save();
        }
    }

    public function tasks()
    {
        return $this->hasMany(Task::class, 'stage_id');
    }

}
