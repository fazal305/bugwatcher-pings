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

    // PostgreSQL: the duplicate check, the issue upsert and count, the regression check and the event insert in one
    // autocommitted statement, so a ping costs one round trip to the database. The issue row is locked before the
    // event's foreign key check takes a key-share lock on it, and NO KEY UPDATE doesn't conflict with that lock, so
    // two workers can't deadlock. The lock also returns the latest committed status, so a regression is flagged once.
    private const STORE = <<<'SQL'
        WITH input AS (
            SELECT ?::bigint AS project_id, ?::varchar AS fingerprint, ?::varchar AS title, ?::varchar AS culprit,
                ?::varchar AS level, ?::varchar AS environment, ?::timestamp AS seen_at, ?::timestamp AS now_at,
                ?::varchar AS event_id, NOT EXISTS (SELECT 1 FROM events WHERE id = ?) AS fresh
        ),
        created AS (
            INSERT INTO issues (project_id, fingerprint, title, culprit, level, environment, status, first_seen_at, last_seen_at, event_count, created_at, updated_at)
            SELECT project_id, fingerprint, title, culprit, level, environment, 'open', seen_at, seen_at, 1, now_at, now_at FROM input WHERE fresh
            ON CONFLICT (project_id, fingerprint) DO NOTHING
            RETURNING id, project_id, fingerprint, title, culprit, level, environment, status, first_seen_at, last_seen_at,
                event_count, created_at, updated_at, NULL::varchar AS previous_status
        ),
        locked AS (
            SELECT issues.id, issues.status FROM issues, input
            WHERE issues.project_id = input.project_id AND issues.fingerprint = input.fingerprint AND input.fresh
                AND NOT EXISTS (SELECT 1 FROM created)
            FOR NO KEY UPDATE OF issues
        ),
        counted AS (
            UPDATE issues SET
                event_count = issues.event_count + 1,
                last_seen_at = CASE WHEN input.seen_at > issues.last_seen_at THEN input.seen_at ELSE issues.last_seen_at END,
                level = input.level,
                environment = input.environment,
                status = 'open',
                updated_at = input.now_at
            FROM locked, input
            WHERE issues.id = locked.id
            RETURNING issues.id, issues.project_id, issues.fingerprint, issues.title, issues.culprit, issues.level,
                issues.environment, issues.status, issues.first_seen_at, issues.last_seen_at, issues.event_count,
                issues.created_at, issues.updated_at, locked.status AS previous_status
        ),
        issue AS (
            SELECT * FROM created UNION ALL SELECT * FROM counted
        ),
        stored AS (
            INSERT INTO events (id, project_id, issue_id, level, environment, message, exception_type, file, line, stacktrace,
                request, user_context, release, sdk, is_regression, occurred_at, received_at, processed_at)
            SELECT input.event_id, issue.project_id, issue.id, input.level, input.environment, ?, ?, ?, ?::integer, ?::jsonb,
                ?::jsonb, ?::jsonb, ?, ?, issue.previous_status IS NOT DISTINCT FROM 'resolved', ?::timestamp, input.seen_at, input.now_at
            FROM issue, input
        )
        SELECT input.fresh, issue.* FROM input LEFT JOIN issue ON true
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

        $fingerprint = $this->fingerprinter->fingerprint($projectId, $type, $top, $message);
        $title = mb_substr($title, 0, 255);
        $culprit = $culprit === null ? null : mb_substr($culprit, 0, 255);

        $stored = DB::getDriverName() === 'pgsql'
            ? $this->storeInOneStatement($event, $fingerprint, $title, $culprit)
            : $this->storeInTransaction($event, $fingerprint, $title, $culprit);
        if ($stored === null) {
            return null;
        }
        [$issue, $event] = $stored;

        return new PingProcessed(
            (new ErrorEvent)->newFromBuilder($event),
            (new Issue)->newFromBuilder((array) $issue),
            (int) $issue->event_count === 1,
            $event['is_regression'],
        );
    }

    /** @return array{object, array}|null null when the event was already stored */
    private function storeInOneStatement(array $event, string $fingerprint, string $title, ?string $culprit): ?array
    {
        $bindings = [
            $event['project_id'], $fingerprint, $title, $culprit, $event['level'], $event['environment'],
            $event['received_at'], $event['processed_at'], $event['id'], $event['id'],
            $event['message'], $event['exception_type'], $event['file'], $event['line'], $event['stacktrace'],
            $event['request'], $event['user_context'], $event['release'], $event['sdk'], $event['occurred_at'],
        ];

        // Comes back without an issue only when another worker created the issue after this statement's snapshot
        // was taken. Nothing was written then, and running it again sees that issue.
        do {
            $row = DB::selectOne(self::STORE, $bindings);
        } while ($row->fresh && $row->id === null);

        if (! $row->fresh) {
            return null;
        }

        $event['issue_id'] = (int) $row->id;
        $event['is_regression'] = $row->previous_status === 'resolved';
        unset($row->fresh, $row->previous_status);

        return [$row, $event];
    }

    /** @return array{object, array}|null null when the event was already stored */
    private function storeInTransaction(array $event, string $fingerprint, string $title, ?string $culprit): ?array
    {
        if (DB::table('events')->where('id', $event['id'])->exists()) {
            return null;
        }

        $projectId = $event['project_id'];
        $seenAt = $event['received_at'];
        $now = $event['processed_at'];

        // The issue row is locked only by the final counter update, so pings for one busy issue wait on each
        // other for as short a time as possible.
        return DB::transaction(function () use ($event, $projectId, $fingerprint, $title, $culprit, $seenAt, $now) {
            $issueId = $this->issueId($projectId, $fingerprint);
            if ($issueId === null) {
                DB::insert(self::CREATE_ISSUE, [
                    $projectId, $fingerprint, $title, $culprit, $event['level'], $event['environment'], $seenAt, $seenAt, $now, $now,
                ]);
                $issueId = $this->issueId($projectId, $fingerprint);
            }

            $event['issue_id'] = $issueId;
            $event['is_regression'] = false;
            DB::table('events')->insert($event);

            // Locked so the previous status is the latest committed one and a regression is flagged exactly once.
            $previousStatus = DB::table('issues')->where('id', $issueId)->lockForUpdate()->value('status');
            $issue = DB::selectOne(self::COUNT_EVENT, [$seenAt, $seenAt, $event['level'], $event['environment'], $now, $issueId]);

            if ($previousStatus === 'resolved') {
                $event['is_regression'] = true;
                DB::table('events')->where('id', $event['id'])->update(['is_regression' => true]);
            }

            return [$issue, $event];
        });
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
