<?php

namespace App\Webhooks;

use App\Events\PingProcessed;
use App\Models\Project;
use App\Realtime\FeedItem;

/** The fields every payload is built from, kept small so it can travel on the queue. */
final class Alert
{
    /** @return array<string, mixed> */
    public static function fromPing(PingProcessed $ping, string $slug, string $projectName): array
    {
        $event = $ping->event;
        $issue = $ping->issue;
        $message = trim((string) $event->message);

        return [
            'test' => false,
            'project_slug' => $slug,
            'project_name' => $projectName,
            'event_id' => (string) $event->id,
            'issue_id' => (int) $issue->id,
            'title' => (string) $issue->title,
            'level' => (string) $event->level,
            'environment' => (string) $event->environment,
            'message' => $message === '' ? '(no message)' : $message,
            'exception_type' => $event->exception_type,
            'culprit' => $issue->culprit,
            'occurred_at' => FeedItem::time($event->occurred_at),
            'event_count' => (int) $issue->event_count,
            'is_new_issue' => $ping->isNewIssue,
            'is_regression' => $ping->isRegression,
            'url' => self::eventUrl($slug, (string) $event->id),
        ];
    }

    /** @return array<string, mixed> */
    public static function test(Project $project): array
    {
        return [
            'test' => true,
            'project_slug' => $project->slug,
            'project_name' => $project->name,
            'event_id' => null,
            'issue_id' => null,
            'title' => 'Test alert from BugWatcher Pings',
            'level' => 'info',
            'environment' => 'production',
            'message' => 'This is a test message. Real alerts will look like this and link to the error in your dashboard.',
            'exception_type' => null,
            'culprit' => null,
            'occurred_at' => FeedItem::time(now()),
            'event_count' => 0,
            'is_new_issue' => false,
            'is_regression' => false,
            'url' => config('webhooks.frontend_url').'/app/projects/'.rawurlencode($project->slug),
        ];
    }

    public static function eventUrl(string $slug, string $eventId): string
    {
        return config('webhooks.frontend_url').'/app/projects/'.rawurlencode($slug).'/events/'.rawurlencode($eventId);
    }
}
