<?php

namespace App\Pings;

use App\Models\Project;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Maps a public key to a project id through the cache, so the ingest route doesn't touch the database on a hit.
 * Unknown keys are cached too (as 0), so a flood of pings with a bad key can't reach the database either.
 */
class ProjectKeyResolver
{
    // A known key is kept this long past its TTL, and used only when the database can't be reached to recheck it.
    private const STALE_SECONDS = 86400;

    public function __construct(private Repository $cache, private int $ttl, private int $negativeTtl) {}

    /**
     * @throws DatabaseUnavailable when the key isn't cached and the database is down
     */
    public function resolve(string $key): ?int
    {
        if (! str_starts_with($key, 'bwp_pk_') || strlen($key) > 64) {
            return null;
        }

        $cacheKey = self::cacheKey($key);
        $cached = $this->cache->get($cacheKey);
        $now = Date::now()->getTimestamp();

        if (is_array($cached) && $cached['fresh_until'] >= $now) {
            return $cached['id'] > 0 ? $cached['id'] : null;
        }

        try {
            $id = (int) Project::where('public_key', $key)->value('id');
        } catch (Throwable $e) {
            if (is_array($cached) && $cached['id'] > 0) {
                return $cached['id'];
            }

            throw new DatabaseUnavailable(previous: $e);
        }

        $ttl = $id > 0 ? $this->ttl : $this->negativeTtl;
        $this->cache->put($cacheKey, ['id' => $id, 'fresh_until' => $now + $ttl], $id > 0 ? $ttl + self::STALE_SECONDS : $ttl);

        return $id > 0 ? $id : null;
    }

    public function forget(string $key): void
    {
        $this->cache->forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return 'pings:key:'.hash('sha256', $key);
    }
}
