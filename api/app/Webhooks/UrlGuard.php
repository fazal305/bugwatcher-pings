<?php

namespace App\Webhooks;

/**
 * Decides whether a webhook URL may be called. It runs when an endpoint is saved and again before every request,
 * because the DNS answer for a generic host can change after it was saved.
 */
class UrlGuard
{
    private const MAX_LENGTH = 2048;

    private const DISCORD_HOSTS = ['discord.com', 'discordapp.com'];

    /** Not reachable on the public internet, or not meant to be: RFC 6890 special-purpose and reserved ranges. */
    private const BLOCKED_RANGES = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
        '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/23',
        '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public function __construct(private HostResolver $resolver, private bool $allowPrivate = false) {}

    /** Returns a user-facing reason the URL is refused, or null when it is allowed. */
    public function problem(string $kind, string $url): ?string
    {
        try {
            $this->check($kind, $url);

            return null;
        } catch (UnsafeUrl $e) {
            return $e->getMessage();
        }
    }

    /** @throws UnsafeUrl */
    public function check(string $kind, string $url): ResolvedUrl
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new UnsafeUrl('Enter a valid URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($parts === false || $host === '' || ! preg_match('#\A[a-z][a-z0-9+.-]*://#i', $url)) {
            throw new UnsafeUrl('Enter a valid URL.');
        }

        $relaxed = $kind === 'generic' && $this->allowPrivate;
        if ($scheme !== 'https' && ! ($relaxed && $scheme === 'http')) {
            throw new UnsafeUrl('The URL must start with https://.');
        }

        // Checked on the raw authority as well, since parsers disagree about where userinfo ends.
        $authority = (string) preg_replace('#\A[a-z][a-z0-9+.-]*://([^/?\#]*).*\z#is', '$1', $url);
        if (isset($parts['user']) || isset($parts['pass']) || str_contains($authority, '@')) {
            throw new UnsafeUrl("The URL can't contain a username or password.");
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'http' ? 80 : 443);
        if ($port !== 443 && ! $relaxed) {
            throw new UnsafeUrl('Only the standard HTTPS port (443) is allowed.');
        }

        $path = (string) ($parts['path'] ?? '');

        return match ($kind) {
            'slack' => $this->named($url, $host, $port, $host === 'hooks.slack.com', 'Slack webhook URLs start with https://hooks.slack.com/.'),
            'discord' => $this->named(
                $url, $host, $port,
                in_array($host, self::DISCORD_HOSTS, true) && str_starts_with($path, '/api/webhooks/'),
                'Discord webhook URLs start with https://discord.com/api/webhooks/.',
            ),
            'generic' => $this->generic($url, $host, $port, $relaxed),
            default => throw new UnsafeUrl('Choose Slack, Discord or generic.'),
        };
    }

    private function named(string $url, string $host, int $port, bool $matches, string $message): ResolvedUrl
    {
        if (! $matches) {
            throw new UnsafeUrl($message);
        }

        $ip = $this->publicAddresses($host, false)[0];

        return new ResolvedUrl($url, $host, $port, $ip);
    }

    private function generic(string $url, string $host, int $port, bool $relaxed): ResolvedUrl
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            if (! $relaxed && self::isBlocked($literal)) {
                throw new UnsafeUrl("That address is private or reserved, so it can't receive webhooks.");
            }

            return new ResolvedUrl($url, $host, $port, $literal);
        }

        // A name whose last label isn't alphabetic is a number in disguise (2130706433, 0x7f.1, 017700000001, 127.1),
        // which some resolvers turn into an IPv4 address.
        $labels = explode('.', rtrim($host, '.'));
        $tld = end($labels);
        $validName = (count($labels) >= 2 || $relaxed) && preg_match('/\A[a-z][a-z0-9-]*\z/', $tld)
            && ! array_filter($labels, fn ($label) => ! preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $label));
        if (! $validName) {
            throw new UnsafeUrl('Use a public host name, such as https://hooks.example.com/bugwatcher.');
        }

        return new ResolvedUrl($url, $host, $port, $this->publicAddresses($host, $relaxed)[0]);
    }

    /** @return non-empty-list<string> */
    private function publicAddresses(string $host, bool $relaxed): array
    {
        $ips = $this->resolver->resolve($host);
        if ($ips === []) {
            throw new UnsafeUrl("We couldn't find that host. Check the URL.");
        }

        // Every address must be public, or a name with one public and one private record could be used to reach the
        // private one.
        if (! $relaxed) {
            foreach ($ips as $ip) {
                if (self::isBlocked($ip)) {
                    throw new UnsafeUrl("That host points to a private or reserved address, so it can't receive webhooks.");
                }
            }
        }

        return array_values($ips);
    }

    public static function isBlocked(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_RANGES as $range) {
            [$network, $bits] = explode('/', $range);
            $net = inet_pton($network);
            if (strlen($net) === strlen($packed) && self::inRange($packed, $net, (int) $bits)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $ip, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
