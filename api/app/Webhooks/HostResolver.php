<?php

namespace App\Webhooks;

class HostResolver
{
    /** @return list<string> Every A and AAAA address for the host. */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        // dns_get_record skips the hosts file and some resolvers; gethostbynamel covers those (IPv4 only).
        if ($ips === []) {
            $ips = @gethostbynamel($host) ?: [];
        }

        return array_values(array_unique($ips));
    }
}
