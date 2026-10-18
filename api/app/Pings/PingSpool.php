<?php

namespace App\Pings;

use Illuminate\Redis\Connections\Connection;

/**
 * Holds masked ping payloads in a Redis list while they can't be written to the database.
 */
class PingSpool
{
    public function __construct(private Connection $redis, private string $key, private string $deadLetterKey) {}

    public function push(array $payload): void
    {
        $this->redis->rpush($this->key, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function pushBack(string $raw): void
    {
        $this->redis->lpush($this->key, $raw);
    }

    public function pop(): ?string
    {
        $raw = $this->redis->lpop($this->key);

        return is_string($raw) ? $raw : null;
    }

    public function deadLetter(string $raw): void
    {
        $this->redis->rpush($this->deadLetterKey, $raw);
    }

    public function size(): int
    {
        return (int) $this->redis->llen($this->key);
    }

    public function deadLetterSize(): int
    {
        return (int) $this->redis->llen($this->deadLetterKey);
    }

    public function clear(): void
    {
        $this->redis->del($this->key, $this->deadLetterKey);
    }
}
