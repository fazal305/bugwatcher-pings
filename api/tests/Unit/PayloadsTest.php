<?php

namespace Tests\Unit;

use App\Webhooks\Payloads;
use PHPUnit\Framework\TestCase;

class PayloadsTest extends TestCase
{
    private const URL = 'https://app.bugwatcher.test/app/projects/shop-abc123/events/01JABCDEFGHJKMNPQRSTVWXYZ0';

    private function alert(array $overrides = []): array
    {
        return array_replace([
            'test' => false,
            'project_slug' => 'shop-abc123',
            'project_name' => 'Shop',
            'event_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
            'issue_id' => 7,
            'title' => 'TypeError: Cannot read properties of undefined',
            'level' => 'error',
            'environment' => 'production',
            'message' => 'Cannot read properties of undefined',
            'exception_type' => 'TypeError',
            'culprit' => '/assets/index.js:42',
            'occurred_at' => '2026-10-19T10:00:00.123Z',
            'event_count' => 1234,
            'is_new_issue' => true,
            'is_regression' => false,
            'url' => self::URL,
        ], $overrides);
    }

    public function test_slack_has_a_header_fields_and_a_deep_link_button(): void
    {
        $payload = (new Payloads)->slack($this->alert());
        $blocks = collect($payload['blocks']);

        $header = $blocks->firstWhere('type', 'header');
        $this->assertSame('[ERROR] TypeError: Cannot read properties of undefined', $header['text']['text']);
        $this->assertSame('plain_text', $header['text']['type']);

        $button = $blocks->firstWhere('type', 'actions')['elements'][0];
        $this->assertSame('View in BugWatcher', $button['text']['text']);
        $this->assertSame(self::URL, $button['url']);

        $fields = collect($blocks->firstWhere('fields')['fields'])->pluck('text')->implode("\n");
        $this->assertStringContainsString('2026-10-19T10:00:00.123Z', $fields);
        $this->assertStringContainsString('production', $fields);
        $this->assertStringContainsString('1,234', $fields);
        $this->assertStringContainsString('/assets/index.js:42', $fields);
    }

    public function test_slack_escapes_mrkdwn_control_characters_in_error_text(): void
    {
        $payload = (new Payloads)->slack($this->alert([
            'message' => '<!everyone> see <https://evil.test|your bank> & pay',
            'culprit' => '<!channel>',
            'title' => 'Error <!here>',
        ]));
        $json = json_encode($payload);

        $this->assertStringNotContainsString('<!everyone>', $json);
        $this->assertStringNotContainsString('<!channel>', $json);
        $this->assertStringNotContainsString('<https://evil.test', $json);
        $section = collect($payload['blocks'])->first(fn ($b) => $b['type'] === 'section' && isset($b['text']));
        $this->assertSame('&lt;!everyone&gt; see &lt;https://evil.test|your bank&gt; &amp; pay', $section['text']['text']);
        $this->assertStringStartsWith('[ERROR] Error &lt;!here&gt;', $payload['text']);
    }

    public function test_slack_text_is_cut_to_block_limits(): void
    {
        $payload = (new Payloads)->slack($this->alert(['message' => str_repeat('<', 5000), 'title' => str_repeat('T', 400)]));
        $blocks = collect($payload['blocks']);

        $this->assertLessThanOrEqual(150, mb_strlen($blocks->firstWhere('type', 'header')['text']['text']));
        $section = $blocks->first(fn ($b) => $b['type'] === 'section' && isset($b['text']))['text']['text'];
        $this->assertLessThanOrEqual(3000, mb_strlen($section));
        $this->assertStringEndsWith('&lt;…', $section);
        $this->assertLessThanOrEqual(300, mb_strlen($payload['text']));
    }

    public function test_discord_embed_links_to_the_event_and_cannot_mention_anyone(): void
    {
        $payload = (new Payloads)->discord($this->alert(['message' => '@everyone @here <@&123> broke']));
        $embed = $payload['embeds'][0];

        $this->assertSame(['parse' => []], $payload['allowed_mentions']);
        $this->assertSame([], $payload['allowed_mentions']['parse']);
        $this->assertSame(self::URL, $embed['url']);
        $this->assertSame('[ERROR] TypeError: Cannot read properties of undefined', $embed['title']);
        $this->assertSame(0xD2452A, $embed['color']);
        $this->assertSame('2026-10-19T10:00:00.123Z', $embed['timestamp']);
        $this->assertArrayNotHasKey('content', $payload);
        $this->assertSame(0x8C1C3A, (new Payloads)->discord($this->alert(['level' => 'fatal']))['embeds'][0]['color']);
    }

    public function test_discord_text_is_cut_to_embed_limits(): void
    {
        $payload = (new Payloads)->discord($this->alert([
            'message' => str_repeat('m', 10000),
            'title' => str_repeat('t', 1000),
            'culprit' => str_repeat('c', 3000),
        ]));
        $embed = $payload['embeds'][0];

        $this->assertLessThanOrEqual(256, mb_strlen($embed['title']));
        $this->assertLessThanOrEqual(4096, mb_strlen($embed['description']));
        foreach ($embed['fields'] as $field) {
            $this->assertLessThanOrEqual(1024, mb_strlen($field['value']));
        }
        $total = mb_strlen($embed['title']) + mb_strlen($embed['description']) + mb_strlen($embed['footer']['text'])
            + array_sum(array_map(fn ($f) => mb_strlen($f['name']) + mb_strlen($f['value']), $embed['fields']));
        $this->assertLessThanOrEqual(6000, $total);
    }

    public function test_generic_payload_is_signed_and_the_signature_verifies(): void
    {
        $secret = 'whsec_test_secret';
        $built = (new Payloads)->build('generic', $this->alert(), $secret, 1760868000);

        $header = $built['headers']['X-BugWatcher-Signature'];
        $this->assertMatchesRegularExpression('/\At=1760868000,v1=[0-9a-f]{64}\z/', $header);

        // What a receiver does: split the header, recompute over "<t>.<raw body>", compare in constant time.
        parse_str(str_replace(',', '&', $header), $parts);
        $expected = hash_hmac('sha256', $parts['t'].'.'.$built['body'], $secret);
        $this->assertTrue(hash_equals($expected, $parts['v1']));
        $this->assertFalse(hash_equals(hash_hmac('sha256', $parts['t'].'.'.$built['body'].' ', $secret), $parts['v1']));

        $body = json_decode($built['body'], true);
        $this->assertSame(self::URL, $body['event']['url']);
        $this->assertSame('alert', $body['type']);
        $this->assertTrue($body['issue']['is_new']);
    }

    public function test_slack_and_discord_requests_are_not_signed(): void
    {
        $this->assertSame([], (new Payloads)->build('slack', $this->alert(), 'secret')['headers']);
        $this->assertSame([], (new Payloads)->build('discord', $this->alert(), 'secret')['headers']);
    }

    public function test_test_messages_are_labelled(): void
    {
        $alert = $this->alert(['test' => true, 'title' => 'Test alert from BugWatcher Pings', 'is_new_issue' => false]);

        $this->assertStringStartsWith('[TEST]', (new Payloads)->slack($alert)['blocks'][0]['text']['text']);
        $this->assertStringStartsWith('[TEST]', (new Payloads)->discord($alert)['embeds'][0]['title']);
        $this->assertSame('test', (new Payloads)->generic($alert)['type']);
    }
}
