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
Schedule::command('activitylog:cleanup --days=180')
    ->monthly()
    ->at('04:00');

// Auto cleanup trash setiap 3 bulan (data yang sudah 90 hari di trash)
Schedule::command('trash:cleanup --days=90')
    ->quarterly()
    ->at('03:00')
    ->onSuccess(function () {
        Log::info('Trash cleanup completed successfully');
    })
    ->onFailure(function () {
        Log::error('Trash cleanup failed');
    });
