<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Realtime\FeedItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,resolved'],
            'q' => ['nullable', 'string', 'max:200'],
        ]);

        $query = $project->issues()->orderByDesc('last_seen_at')->orderByDesc('id');
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        $search = trim($data['q'] ?? '');
        if ($search !== '') {
            // An explicit escape character, because SQLite has no default one and PostgreSQL's is a backslash.
            $pattern = '%'.strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern]);
        }

        $issues = $query->paginate(50);

        return response()->json([
            'data' => $issues->getCollection()->map(self::present(...))->values(),
            'meta' => ['current_page' => $issues->currentPage(), 'last_page' => $issues->lastPage(), 'total' => $issues->total()],
        ]);
    }

    public function update(Request $request, Project $project, Issue $issue): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:open,resolved']]);
        $issue->update(['status' => $data['status']]);

        return response()->json(['data' => self::present($issue)]);
    }

    /** @return array<string, mixed> */
    private static function present(Issue $issue): array
    {
        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'culprit' => $issue->culprit,
            'level' => $issue->level,
            'environment' => $issue->environment,
            'status' => $issue->status,
            'event_count' => $issue->event_count,
            'first_seen_at' => FeedItem::time($issue->first_seen_at),
            'last_seen_at' => FeedItem::time($issue->last_seen_at),
        ];
    }
}
