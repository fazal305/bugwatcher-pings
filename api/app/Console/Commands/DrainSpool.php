<?php

namespace App\Console\Commands;

use App\Jobs\ProcessPing;
use App\Pings\PingSpool;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('pings:drain-spool {--limit=10000 : Most payloads to move in one run}')]
#[Description('Move spooled pings back onto the ingest queue once the database is reachable')]
class DrainSpool extends Command
{
    public function handle(PingSpool $spool): int
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable) {
            $this->warn('Database unreachable; '.$spool->size().' ping(s) left in the spool.');

            return self::SUCCESS;
        }

        $maxCycles = (int) config('pings.max_spool_cycles');
        $moved = $dead = 0;

        for ($i = (int) $this->option('limit'); $i > 0 && ($raw = $spool->pop()) !== null; $i--) {
            $payload = json_decode($raw, true);

            // A payload that keeps failing while the database is up is set aside instead of cycling forever.
            if (! is_array($payload) || ($payload['spool_cycles'] ?? 0) >= $maxCycles) {
                $spool->deadLetter($raw);
                $dead++;

                continue;
            }

            try {
                ProcessPing::dispatch($payload)->onQueue(config('pings.queue'));
            } catch (Throwable $e) {
                $spool->pushBack($raw);

                throw $e;
            }
            $moved++;
        }

        $this->info("Requeued {$moved} spooled ping(s); {$dead} set aside; {$spool->size()} left.");

        return self::SUCCESS;
    }
}
