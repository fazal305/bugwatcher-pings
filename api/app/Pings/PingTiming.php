<?php

namespace App\Pings;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Logs one "ping.timing" line per processed ping: how long it waited in the queue, then every database statement,
 * Redis command and phase of the job in order, each with the milliseconds since the previous step.
 */
final class PingTiming
{
    private static ?self $current = null;

    private static bool $listening = false;

    private int $started;

    private int $last;

    /** @var list<string> */
    private array $steps = [];

    private function __construct(private ?int $queueWait)
    {
        $this->started = $this->last = hrtime(true);
    }

    public static function begin(?int $receivedAt): ?self
    {
        if (! config('pings.timing_log')) {
            return null;
        }
        if (! self::$listening) {
            self::$listening = true;
            self::listen();
        }

        return self::$current = new self($receivedAt === null ? null : (int) floor(microtime(true) * 1000) - $receivedAt);
    }

    public static function current(): ?self
    {
        return self::$current;
    }

    public function mark(string $step): void
    {
        $now = hrtime(true);
        $this->steps[] = $step.'='.round(($now - $this->last) / 1e6, 1);
        $this->last = $now;
    }

    public function finish(?string $id, string $outcome): void
    {
        self::$current = null;
        Log::warning('ping.timing', [
            'id' => $id,
            'outcome' => $outcome,
            'queue_wait_ms' => $this->queueWait,
            'job_ms' => round((hrtime(true) - $this->started) / 1e6, 1),
            'steps' => implode(' ', $this->steps),
        ]);
    }

    private static function listen(): void
    {
        DB::listen(function (QueryExecuted $query) {
            $words = array_slice(preg_split('/\s+/', trim(str_replace('"', '', $query->sql))), 0, 3);
            self::$current?->mark('sql:'.strtolower(implode('_', $words)).'('.round($query->time, 1).')');
        });
        Event::listen(TransactionBeginning::class, fn () => self::$current?->mark('begin'));
        Event::listen(TransactionCommitted::class, fn () => self::$current?->mark('commit'));

        Redis::enableEvents();
        foreach (Redis::connections() ?? [] as $connection) {
            if ($connection instanceof Connection) {
                $connection->setEventDispatcher(app('events'));
            }
        }
        Event::listen(CommandExecuted::class, fn (CommandExecuted $e) => self::$current?->mark('redis:'.strtolower($e->command)));
    }
}
