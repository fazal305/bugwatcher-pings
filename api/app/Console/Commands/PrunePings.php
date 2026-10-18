<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

#[Signature('pings:prune {--batch=5000}')]
#[Description('Delete events and webhook deliveries older than the retention period (PINGS_RETENTION_DAYS)')]
class PrunePings extends Command
{
    public function handle(): int
    {
        $days = (int) config('pings.retention_days');
        $cutoff = Date::now()->subDays($days)->format('Y-m-d H:i:s.v');
        $batch = max(1, (int) $this->option('batch'));

        $deleted = $this->prune('events', 'received_at', $cutoff, $batch);
        $this->info("Deleted {$deleted} event(s) older than {$days} day(s).");

        $deleted = $this->prune('webhook_deliveries', 'created_at', $cutoff, $batch);
        $this->info("Deleted {$deleted} webhook delivery record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }

    // Small batches keep each delete's locks and WAL short on a large table.
    private function prune(string $table, string $column, string $cutoff, int $batch): int
    {
        $deleted = 0;
        do {
            $count = DB::table($table)
                ->whereIn('id', DB::table($table)->select('id')->where($column, '<', $cutoff)->limit($batch))
                ->delete();
            $deleted += $count;
        } while ($count === $batch);

        return $deleted;
    }
}
