<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// ========================================
// BACKUP & ACTIVITY LOG SCHEDULES
// ========================================

// Monthly backup on 1st day at 2:00 AM
Schedule::command('backup:run --only-db')
    ->monthlyOn(1, '02:00')
    ->emailOutputOnFailure(config('backup.notifications.mail.to'));

// Clean old backups weekly on Sunday at 3:00 AM
Schedule::command('backup:clean')
    ->weekly()
    ->sundays()
    ->at('03:00');

// Cleanup old activity logs yearly at 4:00 AM
Schedule::command('activitylog:cleanup --days=365')
    ->yearly()
    ->at('04:00');

// Monitor backup health daily at 5:00 AM
Schedule::command('backup:monitor')
    ->daily()
    ->at('05:00');
