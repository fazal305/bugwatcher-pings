<?php

namespace App\Pings;

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;

/**
 * Fixed one-second windows per project key and per client IP. On Redis both counters are bumped in one round trip.
 */
class PingRateLimiter
{
    private const SCRIPT = <<<'LUA'
        local counts = {}
        for i, key in ipairs(KEYS) do
            counts[i] = redis.call('INCR', key)
            if counts[i] == 1 then redis.call('EXPIRE', key, 2) end
        end
        return counts
        LUA;

    public function __construct(private Repository $cache, private int $perKey, private int $perIp) {}

    /**
     * Returns false when either limit is exceeded.
     */
    public function attempt(string $projectKey, string $ip): bool
    {
        $second = Date::now()->getTimestamp();
        $keys = ['pings:rl:k:'.hash('xxh128', $projectKey).':'.$second, 'pings:rl:ip:'.$ip.':'.$second];

        $store = $this->cache->getStore();
        if ($store instanceof RedisStore) {
            $prefix = $store->getPrefix();
            [$forKey, $forIp] = $store->connection()->eval(self::SCRIPT, 2, $prefix.$keys[0], $prefix.$keys[1]);
        } else {
            $forKey = $this->increment($keys[0]);
            $forIp = $this->increment($keys[1]);
        }

        return $forKey <= $this->perKey && $forIp <= $this->perIp;
    }

    private function increment(string $key): int
    {
        $this->cache->add($key, 0, 2);

        return (int) $this->cache->increment($key);
    }
}
