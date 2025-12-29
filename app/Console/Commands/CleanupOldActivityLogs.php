<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Activitylog\Models\Activity;
use Carbon\Carbon;

class CleanupOldActivityLogs extends Command
{
    protected $signature = 'activitylog:cleanup {--days=365 : Number of days to keep}';
    protected $description = 'Clean up activity logs older than specified days';

    public function handle()
    {
        $days = $this->option('days');
        $date = Carbon::now()->subDays($days);

        $count = Activity::where('created_at', '<', $date)->count();

        if ($count === 0) {
            $this->info('No old logs to delete.');
            return 0;
        }

        if ($this->confirm("Delete {$count} activity logs older than {$days} days?", true)) {
            Activity::where('created_at', '<', $date)->delete();
            $this->info("Deleted {$count} old activity logs.");
        }

        return 0;
    }
}
