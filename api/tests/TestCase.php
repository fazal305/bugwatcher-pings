<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    private static ?string $redisUnavailable = null;

    private static bool $redisChecked = false;

    // Checked once per run: a refused connection takes seconds on Windows, and every later test would pay for it again.
    protected function requireRedis(): void
    {
        if (! self::$redisChecked) {
            self::$redisChecked = true;
            try {
                Redis::connection(config('pings.redis_connection'))->ping();
            } catch (Throwable $e) {
                self::$redisUnavailable = $e->getMessage();
            }
        }

        if (self::$redisUnavailable !== null) {
            $this->markTestSkipped('Needs Redis on '.config('database.redis.default.host').':'.config('database.redis.default.port')
                .' (run `docker compose up -d`): '.self::$redisUnavailable);
        }
    }
}
