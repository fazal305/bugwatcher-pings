<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPing;
use App\Pings\DatabaseUnavailable;
use App\Pings\Masker;
use App\Pings\PingRateLimiter;
use App\Pings\PingValidator;
use App\Pings\ProjectKeyResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The ingest hot path: no session, no auth guard and, once the project key is cached, no database query. Everything
 * secret-looking is masked here, before the payload reaches Redis.
 */
class PingController extends Controller
{
    public function __invoke(
        Request $request,
        ProjectKeyResolver $keys,
        PingRateLimiter $limiter,
        PingValidator $validator,
        Masker $masker,
    ): JsonResponse {
        $started = hrtime(true);
        $maxBytes = (int) config('pings.max_body_bytes');

        if ((int) $request->server('CONTENT_LENGTH') > $maxBytes || strlen($body = $request->getContent()) > $maxBytes) {
            return $this->respond(['message' => 'The ping is too large. The limit is 64 KB.'], 413, $started);
        }

        // Parsed by hand because sendBeacon posts JSON as text/plain, which Laravel would not decode.
        $input = json_decode($body, true, 64);
        if (! is_array($input) || array_is_list($input)) {
            return $this->respond(['message' => 'The ping must be a JSON object.', 'errors' => ['body' => ['The ping must be a JSON object.']]], 422, $started);
        }

        $key = $request->header('X-BugWatcher-Key') ?? $input['key'] ?? null;
        $key = is_string($key) ? $key : '';

        if (! $limiter->attempt($key, (string) $request->ip())) {
            return $this->respond(['message' => 'Too many pings. Please slow down and try again in a moment.'], 429, $started, ['Retry-After' => '1']);
        }

        try {
            $projectId = $keys->resolve($key);
        } catch (DatabaseUnavailable $e) {
            Log::warning('Ping rejected: project key not cached and the database is unreachable.', ['error' => $e->getPrevious()?->getMessage()]);

            return $this->respond(['message' => 'The service is temporarily unavailable. Please retry shortly.'], 503, $started, ['Retry-After' => '30']);
        }
        if ($projectId === null) {
            return $this->respond(['message' => 'Unauthorized.'], 401, $started);
        }

        [$ping, $errors] = $validator->validate($input);
        if ($ping === null) {
            return $this->respond(['message' => 'The ping is invalid.', 'errors' => $errors], 422, $started);
        }

        $ping = $masker->mask($ping);
        if (isset($ping['request']['url'])) {
            $ping['request']['url'] = $masker->maskUrl($ping['request']['url']);
        }
        if (isset($ping['request']['query']) && is_string($ping['request']['query'])) {
            $ping['request']['query'] = $masker->maskQueryString(ltrim($ping['request']['query'], '?'));
        }

        $id = (string) Str::ulid();
        ProcessPing::dispatch($ping + [
            'id' => $id,
            'project_id' => $projectId,
            'received_at' => (int) floor(microtime(true) * 1000),
        ])->onQueue(config('pings.queue'));

        return $this->respond(['id' => $id], 202, $started);
    }

    private function respond(array $data, int $status, int $started, array $headers = []): JsonResponse
    {
        $ingest = (hrtime(true) - $started) / 1e6;
        $timing = sprintf('ingest;dur=%.2f', $ingest);
        if ($arrived = request()->server('REQUEST_TIME_FLOAT')) {
            $timing .= sprintf(', total;dur=%.2f', (microtime(true) - $arrived) * 1000);
        }

        return response()->json($data, $status, $headers + ['Server-Timing' => $timing]);
    }
}
