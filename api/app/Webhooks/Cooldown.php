<?php

namespace App\Webhooks;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * At most one alert per endpoint and issue per cooldown window, decided by a Redis SET NX EX so concurrent workers
 * agree without a database round trip.
 */
class Cooldown
{
    /** @param Closure(): Connection $connection */
    public function __construct(private Closure $connection, private string $prefix) {}

    /** Returns true when the alert should be sent. $force sends it anyway and starts a new window. */
    public function acquire(int $endpointId, int $issueId, int $seconds, bool $force): bool
    {
        if ($seconds <= 0) {
            return true;
        }

        $key = "{$this->prefix}{$endpointId}:{$issueId}";
        try {
            $redis = ($this->connection)();
            if ($force) {
                $redis->set($key, '1', 'EX', $seconds);

                return true;
            }

            return $redis->set($key, '1', 'EX', $seconds, 'NX') !== null;
        } catch (Throwable $e) {
            // An alert sent twice is better than an alert lost while Redis is unreachable.
            Log::warning('Webhook cooldown check failed; sending anyway.', ['error' => $e->getMessage()]);

            return true;
        }
    }
}
