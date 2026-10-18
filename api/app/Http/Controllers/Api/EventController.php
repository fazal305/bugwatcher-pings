<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use App\Models\Project;
use App\Realtime\FeedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    private const LEVELS = ['fatal', 'error', 'warning', 'info'];

    private const ENVIRONMENTS = ['production', 'staging', 'development'];

    // Paged by a (received_at, id) cursor, so events that arrive while someone is paging can't shift the pages.
    public function index(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'before' => ['nullable', 'string', 'regex:/\A\d{1,15}-[0-9A-HJKMNP-TV-Z]{26}\z/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'level' => ['nullable', 'array'],
            'level.*' => [Rule::in(self::LEVELS)],
            'environment' => ['nullable', 'array'],
            'environment.*' => [Rule::in(self::ENVIRONMENTS)],
        ]);
        $limit = (int) ($data['limit'] ?? 50);

        $query = $project->events()
            ->select(['id', 'project_id', 'issue_id', 'level', 'environment', 'message', 'is_regression', 'occurred_at', 'received_at'])
            ->with('issue:id,title,culprit,event_count,first_seen_at')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        if (! empty($data['level'])) {
            $query->whereIn('level', $data['level']);
        }
        if (! empty($data['environment'])) {
            $query->whereIn('environment', $data['environment']);
        }
        if (isset($data['before'])) {
            [$ms, $id] = explode('-', $data['before'], 2);
            $time = gmdate('Y-m-d H:i:s', intdiv((int) $ms, 1000)).sprintf('.%03d', (int) $ms % 1000);
            $query->where(fn ($q) => $q->where('received_at', '<', $time)
                ->orWhere(fn ($q) => $q->where('received_at', $time)->where('id', '<', $id)));
        }

        $events = $query->get();
        $hasMore = $events->count() > $limit;
        $events = $events->take($limit);
        $last = $events->last();

        return response()->json([
            // An issue's first event is the one received when the issue was created.
            'data' => $events->map(fn (ErrorEvent $event) => FeedItem::make(
                $event,
                $event->issue,
                $event->received_at->equalTo($event->issue->first_seen_at),
            ))->values(),
            'next_cursor' => $hasMore && $last ? $last->received_at->getTimestampMs().'-'.$last->id : null,
        ]);
    }

    public function show(Project $project, ErrorEvent $event): JsonResponse
    {
        $issue = $event->issue;

        return response()->json(['data' => [
            'id' => $event->id,
            'level' => $event->level,
            'environment' => $event->environment,
            'critical' => FeedItem::isCritical($event->level, $event->environment),
            'message' => $event->message,
            'exception_type' => $event->exception_type,
            'file' => $event->file,
            'line' => $event->line,
            'release' => $event->release,
            'sdk' => $event->sdk,
            'is_regression' => $event->is_regression,
            'occurred_at' => FeedItem::time($event->occurred_at),
            'received_at' => FeedItem::time($event->received_at),
            'processed_at' => FeedItem::time($event->processed_at),
            'stacktrace' => array_map(self::frame(...), array_values(array_filter((array) $event->stacktrace, 'is_array'))),
            'request' => $event->request,
            'user_context' => $event->user_context,
            'issue' => [
                'id' => $issue->id,
                'title' => $issue->title,
                'culprit' => $issue->culprit,
                'status' => $issue->status,
                'event_count' => $issue->event_count,
                'first_seen_at' => FeedItem::time($issue->first_seen_at),
                'last_seen_at' => FeedItem::time($issue->last_seen_at),
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    private static function frame(array $frame): array
    {
        $lines = fn ($value) => is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];

        return [
            'file' => isset($frame['file']) && is_scalar($frame['file']) ? (string) $frame['file'] : null,
            'line' => isset($frame['line']) && is_numeric($frame['line']) ? (int) $frame['line'] : null,
            'function' => isset($frame['function']) && is_scalar($frame['function']) ? (string) $frame['function'] : null,
            'in_app' => isset($frame['in_app']) ? (bool) $frame['in_app'] : null,
            'context_line' => isset($frame['context_line']) && is_scalar($frame['context_line']) ? (string) $frame['context_line'] : null,
            'pre_context' => $lines($frame['pre_context'] ?? null),
            'post_context' => $lines($frame['post_context'] ?? null),
        ];
    }
}
