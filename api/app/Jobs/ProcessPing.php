<?php

namespace App\Jobs;

use App\Pings\PingProcessor;
use App\Pings\PingSpool;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * The payload was masked by the ingest route before it was queued; nothing here ever sees the raw request.
 */
class ProcessPing implements ShouldQueue
{
    use DetectsLostConnections, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [2, 10];

    public function __construct(public array $payload) {}

    public function handle(PingProcessor $processor, PingSpool $spool): void
    {
        try {
            $processed = $processor->process($this->payload);
        } catch (Throwable $e) {
            if (! $this->isDatabaseUnavailable($e)) {
                throw $e;
            }

            // Spooled rather than released, so an outage longer than the retry budget can't exhaust it and drop the ping.
            Log::warning('Database unavailable; ping spooled.', ['id' => $this->payload['id'] ?? null]);
            $spool->push($this->payload);

            return;
        }

        if ($processed !== null) {
            event($processed);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::error('Ping processing failed; spooled for a later retry.', [
            'id' => $this->payload['id'] ?? null,
            'error' => $e?->getMessage(),
        ]);

        $payload = $this->payload;
        $payload['spool_cycles'] = ($payload['spool_cycles'] ?? 0) + 1;
        app(PingSpool::class)->push($payload);
    }

    private function isDatabaseUnavailable(Throwable $e): bool
    {
        $isPdoError = false;
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (! $current instanceof PDOException) {
                continue;
            }
            $isPdoError = true;
            // SQLSTATE class 08 is "connection exception"; 57P01-57P03 are PostgreSQL shutting down or starting up.
            $state = (string) ($current->errorInfo[0] ?? $current->getCode());
            if (str_starts_with($state, '08') || in_array($state, ['57P01', '57P02', '57P03'], true)
                || str_contains($current->getMessage(), 'SQLSTATE[08') || $this->causedByLostConnection($current)) {
                return true;
            }
        }

        // Drivers word a dropped connection differently per platform (on Windows it can surface as a generic
        // HY000), so any other database error is followed by a fresh connection attempt.
        return $isPdoError && ! $this->databaseReachable();
    }

    private function databaseReachable(): bool
    {
        try {
            DB::reconnect();
            DB::select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
