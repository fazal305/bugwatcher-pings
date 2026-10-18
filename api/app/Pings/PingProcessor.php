<?php

namespace App\Pings;

use App\Events\PingProcessed;
use App\Models\ErrorEvent;
use App\Models\Issue;
use Illuminate\Support\Facades\DB;

class PingProcessor
{
    private const CREATE_ISSUE = <<<'SQL'
        INSERT INTO issues (project_id, fingerprint, title, culprit, level, environment, status, first_seen_at, last_seen_at, event_count, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, 0, ?, ?)
        ON CONFLICT (project_id, fingerprint) DO NOTHING
        SQL;

    private const COUNT_EVENT = <<<'SQL'
        UPDATE issues SET
            event_count = event_count + 1,
            last_seen_at = CASE WHEN ? > last_seen_at THEN ? ELSE last_seen_at END,
            level = ?,
            environment = ?,
            status = 'open',
            updated_at = ?
        WHERE id = ?
        RETURNING id, project_id, fingerprint, title, culprit, level, environment, status, first_seen_at, last_seen_at,
            event_count, created_at, updated_at
        SQL;

    public function __construct(private Fingerprinter $fingerprinter) {}

    /**
     * Stores one ping as an event and folds it into its issue. Returns null when the event was already stored, so a
     * payload delivered twice (a retry, or a spool replay) is not counted twice.
     */
    public function process(array $payload): ?PingProcessed
    {
        $projectId = (int) $payload['project_id'];
        $exception = $payload['exception'] ?? [];
        $frames = $exception['frames'] ?? [];
        $type = $exception['type'] ?? null;
        $message = (string) $payload['message'];

        $top = $this->fingerprinter->topFrame($frames);
        $file = isset($top['file']) ? $this->fingerprinter->displayFile($top['file']) : null;
        $line = $top['line'] ?? null;
        $culprit = $file !== null && $file !== '' ? $file.($line !== null ? ':'.$line : '') : ($top['function'] ?? null);
        $title = ($type ? $type.': ' : '').strtok($message, "\n");

        $now = self::time((int) floor(microtime(true) * 1000));
        $occurredAt = self::time((int) $payload['occurred_at']);
        $sdk = isset($payload['sdk']['name']) ? trim($payload['sdk']['name'].'/'.($payload['sdk']['version'] ?? ''), '/') : null;

        $event = [
            'id' => $payload['id'],
            'project_id' => $projectId,
            'level' => $payload['level'],
            'environment' => $payload['environment'],
            'message' => $message,
            'exception_type' => $type,
            'file' => $file === null ? null : mb_substr($file, 0, 1024),
            'line' => $line,
            'stacktrace' => $frames === [] ? null : self::json($frames),
            'request' => isset($payload['request']) ? self::json($payload['request']) : null,
            'user_context' => isset($payload['user']) ? self::json($payload['user']) : null,
            'release' => $payload['release'] ?? null,
            'sdk' => $sdk === null ? null : mb_substr($sdk, 0, 100),
            'occurred_at' => $occurredAt,
            'received_at' => self::time((int) $payload['received_at']),
            'processed_at' => $now,
        ];

        if (DB::table('events')->where('id', $event['id'])->exists()) {
            return null;
        }

        $fingerprint = $this->fingerprinter->fingerprint($projectId, $type, $top, $message);
        $seenAt = $event['received_at'];

        // The issue row is locked only by the final counter update, so pings for one busy issue wait on each
        // other for as short a time as possible.
        [$issue, $event] = DB::transaction(function () use ($event, $projectId, $fingerprint, $title, $culprit, $seenAt, $now) {
            $issueId = $this->issueId($projectId, $fingerprint);
            if ($issueId === null) {
                DB::insert(self::CREATE_ISSUE, [
                    $projectId, $fingerprint, mb_substr($title, 0, 255), $culprit === null ? null : mb_substr($culprit, 0, 255),
                    $event['level'], $event['environment'], $seenAt, $seenAt, $now, $now,
                ]);
                $issueId = $this->issueId($projectId, $fingerprint);
            }

            $event['issue_id'] = $issueId;
            $event['is_regression'] = false;
            DB::table('events')->insert($event);

            // Locked so the previous status is the latest committed one and a regression is flagged exactly once.
            // NO KEY UPDATE, because the event insert's foreign key already holds a key-share lock on this row, and
            // a plain FOR UPDATE from two workers would deadlock against each other's key-share locks.
            $lock = DB::getDriverName() === 'pgsql' ? 'for no key update' : true;
            $previousStatus = DB::table('issues')->where('id', $issueId)->lock($lock)->value('status');
            $issue = DB::selectOne(self::COUNT_EVENT, [$seenAt, $seenAt, $event['level'], $event['environment'], $now, $issueId]);

            if ($previousStatus === 'resolved') {
                $event['is_regression'] = true;
                DB::table('events')->where('id', $event['id'])->update(['is_regression' => true]);
            }

            return [$issue, $event];
        });

        return new PingProcessed(
            (new ErrorEvent)->newFromBuilder($event),
            (new Issue)->newFromBuilder((array) $issue),
            (int) $issue->event_count === 1,
            $event['is_regression'],
        );
    }

    private function issueId(int $projectId, string $fingerprint): ?int
    {
        $id = DB::table('issues')->where('project_id', $projectId)->where('fingerprint', $fingerprint)->value('id');

        return $id === null ? null : (int) $id;
    }

    private static function time(int $ms): string
    {
        return gmdate('Y-m-d H:i:s', intdiv($ms, 1000)).sprintf('.%03d', $ms % 1000);
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
