<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Illuminate\Support\Facades\Artisan;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:database {--list : List all backups} {--clean : Clean old backups}';
    protected $description = 'Create database backup or manage existing backups';

    public function handle()
    {
        if ($this->option('list')) {
            return $this->listBackups();
        }

        if ($this->option('clean')) {
            return $this->cleanBackups();
        }

        $this->info('Starting database backup...');

        try {
            Artisan::call('backup:run', [
                '--only-db' => true,
                '--disable-notifications' => false,
            ]);

            $this->info('Backup completed successfully!');
            $this->line(Artisan::output());

            return 0;
        } catch (\Exception $e) {
            $this->error('Backup failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function listBackups()
    {
        $this->info('Listing all backups...');

        foreach (config('backup.backup.destination.disks') as $diskName) {
            try {
                $destination = BackupDestinationFactory::createFromArray([
                    'name' => config('backup.backup.name'),
                    'disk_name' => $diskName,
                ]);

                $backups = $destination->backups();

                $this->info("\nDisk: {$diskName}");
                $this->table(
                    ['Date', 'Size', 'Age'],
                    $backups->map(function($backup) {
                        return [
                            $backup->date()->format('Y-m-d H:i:s'),
                            $this->formatBytes($backup->size()),
                            $backup->date()->diffForHumans(),
                        ];
                    })
                );
            } catch (\Exception $e) {
                $this->error("Error listing backups on {$diskName}: " . $e->getMessage());
            }
        }

        return 0;
    }

    protected function cleanBackups()
    {
        $this->info('Cleaning old backups...');

        try {
            Artisan::call('backup:clean');
            $this->info('Cleanup completed successfully!');
            $this->line(Artisan::output());

            return 0;
        } catch (\Exception $e) {
            $this->error('Cleanup failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
