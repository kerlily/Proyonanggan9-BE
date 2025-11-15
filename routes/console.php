<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ========================================
//ACTIVITY LOG SCHEDULES
// ========================================


// Cleanup old activity logs yearly at 4:00 AM
Schedule::command('activitylog:cleanup --days=365')
    ->yearly()
    ->at('04:00');
