<?php

namespace App\Traits;

use Spatie\Activitylog\Traits\LogsActivity as SpatieLogsActivity;
use Spatie\Activitylog\LogOptions;

trait LogsActivity
{
    use SpatieLogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName($this->getTable())
            ->setDescriptionForEvent(fn(string $eventName) => "{$eventName} {$this->getTable()}");
    }

    public function tapActivity($activity, string $eventName)
    {
        $activity->properties = $activity->properties->put('ip_address', request()->ip());
        $activity->properties = $activity->properties->put('user_agent', request()->userAgent());
    }
}
