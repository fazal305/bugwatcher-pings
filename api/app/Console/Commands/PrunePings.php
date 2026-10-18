<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

#[Signature('pings:prune {--batch=5000}')]
#[Description('Delete events older than the retention period (PINGS_RETENTION_DAYS)')]
class PrunePings extends Command
{
    public function handle(): int
    {
        $days = (int) config('pings.retention_days');
        $cutoff = Date::now()->subDays($days)->format('Y-m-d H:i:s.v');
        $batch = max(1, (int) $this->option('batch'));
        $deleted = 0;

        // Small batches keep each delete's locks and WAL short on a large table.
        do {
            $count = DB::table('events')
                ->whereIn('id', DB::table('events')->select('id')->where('received_at', '<', $cutoff)->limit($batch))
                ->delete();
            $deleted += $count;
        } while ($count === $batch);

        $this->info("Deleted {$deleted} event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
