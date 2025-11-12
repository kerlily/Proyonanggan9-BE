<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use App\Models\BackupStatus;

class BackupController extends Controller
{
    public function index()
    {
        $backups = [];

        foreach (config('backup.backup.destination.disks') as $diskName) {
            try {
                $destination = BackupDestinationFactory::createFromArray([
                    'name' => config('backup.backup.name'),
                    'disk_name' => $diskName,
                ]);

                $backupCollection = $destination->backups();

                foreach ($backupCollection as $backup) {
                    $backups[] = [
                        'disk' => $diskName,
                        'path' => $backup->path(),
                        'date' => $backup->date()->format('Y-m-d H:i:s'),
                        'size' => $backup->size(),
                        'size_human' => $this->formatBytes($backup->size()),
                        'age' => $backup->date()->diffForHumans(),
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return response()->json([
            'backups' => $backups,
            'total_backups' => count($backups),
            'total_size' => array_sum(array_column($backups, 'size')),
            'total_size_human' => $this->formatBytes(array_sum(array_column($backups, 'size'))),
        ]);
    }

    public function create()
    {
        try {
            Artisan::call('backup:run', [
                '--only-db' => true,
            ]);

            return response()->json([
                'message' => 'Backup started successfully',
                'output' => Artisan::output(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Backup failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function clean()
    {
        try {
            Artisan::call('backup:clean');

            return response()->json([
                'message' => 'Old backups cleaned successfully',
                'output' => Artisan::output(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Cleanup failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function status()
    {
        $statuses = BackupStatus::orderByDesc('backup_date')
            ->limit(20)
            ->get();

        $stats = [
            'total_backups' => BackupStatus::count(),
            'successful_backups' => BackupStatus::where('status', 'success')->count(),
            'failed_backups' => BackupStatus::where('status', 'failed')->count(),
            'last_backup' => BackupStatus::where('status', 'success')->latest('backup_date')->first(),
            'last_failed' => BackupStatus::where('status', 'failed')->latest('backup_date')->first(),
            'total_size' => BackupStatus::where('status', 'success')->sum('size'),
        ];

        return response()->json([
            'stats' => $stats,
            'recent_backups' => $statuses,
        ]);
    }

