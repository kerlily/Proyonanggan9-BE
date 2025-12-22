<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Siswa;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Nilai;
use Carbon\Carbon;

class CleanupTrash extends Command
{
    /**
     * Signature command
     *
     * Usage:
     * php artisan trash:cleanup --days=90
     * php artisan trash:cleanup --days=90 --model=siswa
     * php artisan trash:cleanup --days=90 --dry-run
     */
    protected $signature = 'trash:cleanup
                            {--days=90 : Hapus data yang sudah di-trash lebih dari X hari}
                            {--model= : Hanya cleanup model tertentu (users,siswa,guru,kelas,nilai)}
                            {--dry-run : Simulasi tanpa menghapus data}';

    protected $description = 'Permanent delete trashed data older than specified days';

    public function handle()
    {
        $days = $this->option('days');
        $model = $this->option('model');
        $dryRun = $this->option('dry-run');

        $cutoffDate = Carbon::now()->subDays($days);

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be deleted');
        }

        $this->info("Cleaning up trash older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})");
        $this->newLine();

        $models = $model ? [$model] : ['users', 'siswa', 'guru', 'kelas', 'nilai'];

        $totalDeleted = 0;

        foreach ($models as $modelName) {
            $deleted = $this->cleanupModel($modelName, $cutoffDate, $dryRun);
            $totalDeleted += $deleted;
        }

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("Total permanently deleted: {$totalDeleted}");

        if ($dryRun) {
            $this->info("\n💡 Run without --dry-run to actually delete the records");
        } else {
            $this->info("\n🎉 Cleanup completed!");
        }

        return 0;
    }

    protected function cleanupModel($modelName, $cutoffDate, $dryRun)
    {
        $modelClass = match($modelName) {
            'users' => User::class,
            'siswa' => Siswa::class,
            'guru' => Guru::class,
            'kelas' => Kelas::class,
            'nilai' => Nilai::class,
            default => null
        };

        if (!$modelClass) {
            $this->warn("Unknown model: {$modelName}");
            return 0;
        }

        $query = $modelClass::onlyTrashed()
            ->where('deleted_at', '<', $cutoffDate);

        $count = $query->count();

        if ($count === 0) {
            $this->line("✓ {$modelName}: No old trash found");
            return 0;
        }

        if ($dryRun) {
            $this->line("Would delete {$count} {$modelName} records");

            // Show sample data
            $samples = $query->limit(5)->get();
            foreach ($samples as $sample) {
                $name = $sample->name ?? $sample->nama ?? 'ID: ' . $sample->id;
                $deletedAt = $sample->deleted_at->format('Y-m-d H:i:s');
                $this->line("  - {$name} (deleted: {$deletedAt})");
            }

            if ($count > 5) {
                $this->line("  ... and " . ($count - 5) . " more");
            }

            return $count;
        }

        // Confirm before permanent delete
        if (!$this->confirm("Permanently delete {$count} {$modelName} records? This cannot be undone!", false)) {
            $this->warn("Skipped {$modelName}");
            return 0;
        }

        // Actual deletion
        try {
            $deleted = $query->forceDelete();
            $this->info("✓ Deleted {$deleted} {$modelName} records permanently");

            // Log to activity
            activity()
                ->withProperties([
                    'model' => $modelName,
                    'deleted_count' => $deleted,
                    'cutoff_date' => $cutoffDate->format('Y-m-d H:i:s'),
                ])
                ->log('Trash cleanup');

            return $deleted;
        } catch (\Exception $e) {
            $this->error("Error deleting {$modelName}: " . $e->getMessage());
            return 0;
        }
    }
}
