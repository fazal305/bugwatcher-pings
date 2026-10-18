<?php

namespace Tests\Unit;

use App\Webhooks\HostResolver;
use App\Webhooks\UnsafeUrl;
use App\Webhooks\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlGuardTest extends TestCase
{
    /** @var array<string, list<string>> */
    private const DNS = [
        'hooks.slack.com' => ['54.230.10.1'],
        'discord.com' => ['162.159.135.232'],
        'discordapp.com' => ['162.159.130.233'],
        'hooks.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        'internal.example.com' => ['10.0.0.5'],
        'metadata.example.com' => ['169.254.169.254'],
        'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
        'v6local.example.com' => ['::1'],
        'ula.example.com' => ['fd12:3456:789a::1'],
        'cgnat.example.com' => ['100.64.1.1'],
        'mapped.example.com' => ['::ffff:127.0.0.1'],
        'zero.example.com' => ['0.0.0.0'],
        'localhost' => ['127.0.0.1'],
    ];

    private function guard(bool $allowPrivate = false): UrlGuard
    {
        $resolver = new class(self::DNS) extends HostResolver
        {
            public function __construct(private array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        return new UrlGuard($resolver, $allowPrivate);
    }

    /** @return array<string, array{string, string}> */
    public static function allowed(): array
    {
        return [
            'slack' => ['slack', 'https://hooks.slack.com/services/T000/B000/XXXXXXXX'],
            'slack uppercase host' => ['slack', 'https://HOOKS.SLACK.COM/services/T000/B000/XXXX'],
            'slack explicit 443' => ['slack', 'https://hooks.slack.com:443/services/T000/B000/XXXX'],
            'discord' => ['discord', 'https://discord.com/api/webhooks/123/abc'],
            'discordapp' => ['discord', 'https://discordapp.com/api/webhooks/123/abc'],
            'generic name' => ['generic', 'https://hooks.example.com/bugwatcher?x=1'],
            'generic public ipv4 literal' => ['generic', 'https://93.184.216.34/hook'],
            'generic public ipv6 literal' => ['generic', 'https://[2606:2800:220:1:248:1893:25c8:1946]/hook'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function refused(): array
    {
        return [
            'plain http' => ['slack', 'http://hooks.slack.com/services/T/B/X'],
            'slack lookalike host' => ['slack', 'https://hooks.slack.com.evil.test/services/T/B/X'],
            'slack subdomain of other' => ['slack', 'https://evil.test/hooks.slack.com/services'],
            'slack userinfo trick' => ['slack', 'https://hooks.slack.com@evil.test/services/T/B/X'],
            'slack other port' => ['slack', 'https://hooks.slack.com:8443/services/T/B/X'],
            'discord wrong path' => ['discord', 'https://discord.com/channels/1/2'],
            'discord lookalike' => ['discord', 'https://discord.com.evil.test/api/webhooks/1/x'],
            'discord on slack kind' => ['slack', 'https://discord.com/api/webhooks/1/x'],
            'generic userinfo' => ['generic', 'https://user:pass@hooks.example.com/x'],
            'generic bare at sign' => ['generic', 'https://@hooks.example.com/x'],
            'generic port 8443' => ['generic', 'https://hooks.example.com:8443/x'],
            'generic port 80' => ['generic', 'https://hooks.example.com:80/x'],
            'generic loopback literal' => ['generic', 'https://127.0.0.1/x'],
            'generic metadata literal' => ['generic', 'https://169.254.169.254/latest/meta-data'],
            'generic zero address' => ['generic', 'https://0.0.0.0/x'],
            'generic private literal' => ['generic', 'https://192.168.1.10/x'],
            'generic cgnat literal' => ['generic', 'https://100.64.0.1/x'],
            'generic ipv6 loopback' => ['generic', 'https://[::1]/x'],
            'generic ipv6 ula' => ['generic', 'https://[fc00::1]/x'],
            'generic ipv6 link-local' => ['generic', 'https://[fe80::1]/x'],
            'generic ipv4-mapped ipv6' => ['generic', 'https://[::ffff:127.0.0.1]/x'],
            'generic ipv4-mapped hex' => ['generic', 'https://[::ffff:7f00:1]/x'],
            'decimal ip' => ['generic', 'https://2130706433/x'],
            'decimal ip over http' => ['generic', 'http://2130706433/x'],
            'octal ip' => ['generic', 'https://017700000001/x'],
            'dotted octal ip' => ['generic', 'https://0177.0.0.1/x'],
            'hex ip' => ['generic', 'https://0x7f000001/x'],
            'dotted hex ip' => ['generic', 'https://0x7f.0x0.0x0.0x1/x'],
            'short ip' => ['generic', 'https://127.1/x'],
            'localhost name' => ['generic', 'https://localhost/x'],
            'name resolving private' => ['generic', 'https://internal.example.com/x'],
            'name resolving metadata' => ['generic', 'https://metadata.example.com/x'],
            'name with one private record' => ['generic', 'https://mixed.example.com/x'],
            'name resolving ipv6 loopback' => ['generic', 'https://v6local.example.com/x'],
            'name resolving ula' => ['generic', 'https://ula.example.com/x'],
            'name resolving cgnat' => ['generic', 'https://cgnat.example.com/x'],
            'name resolving mapped loopback' => ['generic', 'https://mapped.example.com/x'],
            'name resolving zero' => ['generic', 'https://zero.example.com/x'],
            'unresolvable name' => ['generic', 'https://nowhere.example.com/x'],
            'backslash authority' => ['generic', 'https://hooks.example.com\\@127.0.0.1/x'],
            'whitespace' => ['generic', 'https://hooks.example.com/ x'],
            'no scheme' => ['generic', 'hooks.example.com/x'],
            'scheme-relative' => ['generic', '//hooks.example.com/x'],
            'file scheme' => ['generic', 'file:///etc/passwd'],
            'gopher scheme' => ['generic', 'gopher://hooks.example.com/x'],
            'unknown kind' => ['email', 'https://hooks.example.com/x'],
        ];
    }

    #[DataProvider('allowed')]
    public function test_allowed_urls_pass(string $kind, string $url): void
    {
        $this->assertNull($this->guard()->problem($kind, $url), "$url should be allowed");
    }

    #[DataProvider('refused')]
    public function test_refused_urls_fail(string $kind, string $url): void
    {
        $this->assertNotNull($this->guard()->problem($kind, $url), "$url should be refused");
    }

    public function test_the_checked_address_is_pinned_for_curl(): void
    {
        $resolved = $this->guard()->check('slack', 'https://hooks.slack.com/services/T/B/X');
        $this->assertSame('hooks.slack.com:443:54.230.10.1', $resolved->curlResolveEntry());

        $v6 = $this->guard()->check('generic', 'https://[2606:2800:220:1:248:1893:25c8:1946]/hook');
        $this->assertSame('[2606:2800:220:1:248:1893:25c8:1946]:443:[2606:2800:220:1:248:1893:25c8:1946]', $v6->curlResolveEntry());
    }

    public function test_the_private_override_relaxes_only_generic_endpoints(): void
    {
        $guard = $this->guard(allowPrivate: true);

        $this->assertNull($guard->problem('generic', 'http://localhost:9911/hook'));
        $this->assertNull($guard->problem('generic', 'http://127.0.0.1:9911/hook'));
        $this->assertNotNull($guard->problem('slack', 'http://hooks.slack.com/services/T/B/X'));
        $this->assertNotNull($guard->problem('generic', 'http://2130706433/x'));
        $this->assertNotNull($guard->problem('generic', 'http://user@localhost:9911/hook'));
    }

    public function test_check_throws_with_a_user_facing_reason(): void
    {
        $this->expectException(UnsafeUrl::class);
        $this->expectExceptionMessage("That host points to a private or reserved address, so it can't receive webhooks.");

        $this->guard()->check('generic', 'https://internal.example.com/x');
    }
}
