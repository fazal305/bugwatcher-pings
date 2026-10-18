<?php

namespace App\Webhooks;

/**
 * Builds the request body for each kind of endpoint from an alert (see Alert::fromPing). Everything in an alert that
 * came from a ping is attacker-controlled text, so it is escaped or neutralised for each platform and cut to the
 * platform's field limits.
 */
class Payloads
{
    private const LABELS = ['fatal' => 'FATAL', 'error' => 'ERROR', 'warning' => 'WARNING', 'info' => 'INFO'];

    private const DISCORD_COLORS = ['fatal' => 0x8C1C3A, 'error' => 0xD2452A, 'warning' => 0xE0A030, 'info' => 0x3A7BC8];

    /** @return array{body: string, headers: array<string, string>} */
    public function build(string $kind, array $alert, ?string $secret = null, ?int $timestamp = null): array
    {
        $payload = match ($kind) {
            'slack' => $this->slack($alert),
            'discord' => $this->discord($alert),
            default => $this->generic($alert),
        };
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $headers = [];
        if ($kind === 'generic' && $secret !== null && $secret !== '') {
            $headers['X-BugWatcher-Signature'] = self::signature($body, $secret, $timestamp ?? time());
        }

        return ['body' => $body, 'headers' => $headers];
    }

    public static function signature(string $body, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array<string, mixed> */
    public function slack(array $alert): array
    {
        $label = $this->label($alert);
        $header = self::cut($label.' '.$alert['title'], 150);
        $fields = [
            $this->slackField('Environment', $alert['environment']),
            $this->slackField('Occurred', $alert['occurred_at']),
            $this->slackField('Events', number_format($alert['event_count'])),
            $this->slackField('Project', $alert['project_name']),
        ];
        if ($alert['culprit'] !== null && $alert['culprit'] !== '') {
            array_unshift($fields, $this->slackField('Culprit', $alert['culprit']));
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $header, 'emoji' => false]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::cut(self::mrkdwn($alert['message']), 3000)]],
            ['type' => 'section', 'fields' => $fields],
        ];
        if ($context = $this->flags($alert)) {
            $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $context]]];
        }
        $blocks[] = ['type' => 'actions', 'elements' => [[
            'type' => 'button',
            'text' => ['type' => 'plain_text', 'text' => 'View in BugWatcher', 'emoji' => false],
            'url' => $alert['url'],
            'style' => $alert['test'] ? 'primary' : 'danger',
        ]]];

        return [
            'text' => self::cut(self::mrkdwn($label.' '.$alert['title']), 300),
            'blocks' => $blocks,
        ];
    }

    /** @return array<string, mixed> */
    public function discord(array $alert): array
    {
        $fields = [];
        if ($alert['culprit'] !== null && $alert['culprit'] !== '') {
            $fields[] = ['name' => 'Culprit', 'value' => self::cut($alert['culprit'], 1024), 'inline' => false];
        }
        $fields[] = ['name' => 'Environment', 'value' => self::cut($alert['environment'], 1024), 'inline' => true];
        $fields[] = ['name' => 'Events', 'value' => number_format($alert['event_count']), 'inline' => true];
        $fields[] = ['name' => 'Occurred', 'value' => $alert['occurred_at'], 'inline' => true];
        if ($context = $this->flags($alert)) {
            $fields[] = ['name' => 'Status', 'value' => $context, 'inline' => true];
        }

        // Discord caps an embed at 6,000 characters in total; the description is what gives way.
        $title = self::cut($this->label($alert).' '.$alert['title'], 256);
        $footer = self::cut('BugWatcher Pings · '.$alert['project_name'], 2048);
        $used = mb_strlen($title) + mb_strlen($footer) + array_sum(array_map(fn ($f) => mb_strlen($f['name']) + mb_strlen($f['value']), $fields));

        return [
            'username' => 'BugWatcher Pings',
            // Error text must never be able to ping @everyone, @here, a role or a user.
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => $title,
                'description' => self::cut($alert['message'], min(4096, 6000 - $used)),
                'url' => $alert['url'],
                'color' => self::DISCORD_COLORS[$alert['level']] ?? self::DISCORD_COLORS['error'],
                'fields' => $fields,
                'timestamp' => $alert['occurred_at'],
                'footer' => ['text' => $footer],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public function generic(array $alert): array
    {
        return [
            'type' => $alert['test'] ? 'test' : 'alert',
            'test' => $alert['test'],
            'project' => ['slug' => $alert['project_slug'], 'name' => $alert['project_name']],
            'event' => [
                'id' => $alert['event_id'],
                'level' => $alert['level'],
                'environment' => $alert['environment'],
                'message' => self::cut($alert['message'], 2000),
                'exception_type' => $alert['exception_type'],
                'culprit' => $alert['culprit'],
                'occurred_at' => $alert['occurred_at'],
                'url' => $alert['url'],
            ],
            'issue' => [
                'id' => $alert['issue_id'],
                'title' => self::cut($alert['title'], 255),
                'event_count' => $alert['event_count'],
                'is_new' => $alert['is_new_issue'],
                'is_regression' => $alert['is_regression'],
            ],
        ];
    }

    private function label(array $alert): string
    {
        return $alert['test'] ? '[TEST]' : '['.(self::LABELS[$alert['level']] ?? strtoupper($alert['level'])).']';
    }

    private function flags(array $alert): string
    {
        return implode(' · ', array_filter([
            $alert['test'] ? 'Test message sent from the project settings' : null,
            $alert['is_new_issue'] ? 'New issue' : null,
            $alert['is_regression'] ? 'Regression: this issue was resolved' : null,
        ]));
    }

    /** @return array{type: string, text: string} */
    private function slackField(string $name, string $value): array
    {
        return ['type' => 'mrkdwn', 'text' => self::cut('*'.$name."*\n".self::mrkdwn($value), 2000)];
    }

    // Slack reads &, < and > as control characters (<!everyone>, <url|text>); escaping them shows the text as typed.
    public static function mrkdwn(string $text): string
    {
        return strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    public static function cut(string $text, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit - 1);

        // Never leave half an HTML entity at the end of escaped Slack text.
        return preg_replace('/&[a-z]{0,3}\z/', '', $cut).'…';
    }
}
