<?php

namespace App\Realtime;

use App\Models\ErrorEvent;
use App\Models\Issue;
use DateTimeInterface;

/**
 * The fields a live feed card needs, shared by the broadcast and the events list. Request data, user context and the
 * stack trace are left out on purpose: the detail endpoint serves those to members only.
 */
final class FeedItem
{
    public const MESSAGE_LIMIT = 300;

    /** @return array<string, mixed> */
    public static function make(ErrorEvent $event, Issue $issue, bool $isNewIssue): array
    {
        $message = (string) $event->message;
        if (mb_strlen($message) > self::MESSAGE_LIMIT) {
            $message = mb_substr($message, 0, self::MESSAGE_LIMIT - 1).'…';
        }

        return [
            'id' => (string) $event->id,
            'issue_id' => (int) $issue->id,
            'issue_title' => (string) $issue->title,
            'level' => (string) $event->level,
            'environment' => (string) $event->environment,
            'critical' => self::isCritical((string) $event->level, (string) $event->environment),
            'message' => $message,
            'culprit' => $issue->culprit,
            'occurred_at' => self::time($event->occurred_at),
            'received_at' => self::time($event->received_at),
            'is_new_issue' => $isNewIssue,
            'is_regression' => (bool) $event->is_regression,
            'is_sample' => (bool) $event->is_sample,
            'event_count' => (int) $issue->event_count,
        ];
    }

    public static function isCritical(string $level, string $environment): bool
    {
        return in_array($level, ['fatal', 'error'], true) && $environment === 'production';
    }

    public static function time(?DateTimeInterface $time): ?string
    {
        return $time?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
