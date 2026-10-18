<?php

namespace App\Webhooks;

final class ResolvedUrl
{
    public function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}

    // curl's "host:port:address" entry, which makes it connect to the address that was checked instead of looking
    // the name up again, so DNS rebinding between the check and the request can't redirect it.
    public function curlResolveEntry(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return "{$this->host}:{$this->port}:{$ip}";
    }
}
