<?php

namespace App\Listeners;

use Illuminate\Events\Dispatcher;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupHasFailed;
use App\Models\BackupStatus;

class BackupEventSubscriber
{
    public function handleBackupSuccessful(BackupWasSuccessful $event)
    {
        BackupStatus::create([
            'filename' => basename($event->backupDestination->newestBackup()->path()),
            'disk' => $event->backupDestination->diskName(),
            'size' => $event->backupDestination->newestBackup()->size(),
            'backup_date' => $event->backupDestination->newestBackup()->date(),
            'status' => 'success',
        ]);

        activity()
            ->log('Database backup completed successfully');
    }

    public function handleBackupFailed(BackupHasFailed $event)
    {
        BackupStatus::create([
            'filename' => 'failed_backup_' . now()->format('Y-m-d_H-i-s'),
            'disk' => 'unknown',
            'size' => 0,
            'backup_date' => now(),
            'status' => 'failed',
            'error_message' => $event->exception->getMessage(),
        ]);

        activity()
            ->withProperties([
                'error' => $event->exception->getMessage(),
            ])
            ->log('Database backup failed');
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            BackupWasSuccessful::class => 'handleBackupSuccessful',
            BackupHasFailed::class => 'handleBackupFailed',
        ];
    }
}
