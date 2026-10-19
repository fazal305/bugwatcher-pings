<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectMember;
use App\Realtime\InvalidToken;
use App\Realtime\Jwt;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Signs Pusher-protocol channel subscriptions. Only the realtime JWT is accepted here, never a Sanctum token, and
 * membership is checked against the database on every subscription: the token's project list only narrows it.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request, Jwt $jwt): JsonResponse
    {
        try {
            $claims = $jwt->decode((string) $request->bearerToken());
        } catch (InvalidToken) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }
        if (($claims['aud'] ?? null) !== 'realtime' || ! is_string($claims['sub'] ?? null) || ! ctype_digit($claims['sub'])) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $socketId = $request->input('socket_id');
        $channel = $request->input('channel_name');
        if (! is_string($socketId) || ! is_string($channel)
            || preg_match('/\A\d+\.\d+\z/', $socketId) !== 1 || preg_match('/\Aprivate-project\.([a-z0-9-]{1,64})\z/', $channel, $match) !== 1) {
            return response()->json(['message' => 'Invalid subscription request.'], 422);
        }
        $slug = $match[1];

        $allowed = in_array($slug, (array) ($claims['projects'] ?? []), true)
            && ProjectMember::query()
                ->where('user_id', (int) $claims['sub'])
                ->whereHas('project', fn ($q) => $q->where('slug', $slug))
                ->exists();
        if (! $allowed) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $broadcaster = Broadcast::driver();
        if (! $broadcaster instanceof PusherBroadcaster) {
            return response()->json(['message' => "Live updates aren't available right now."], 503);
        }

        return response()->json(json_decode($broadcaster->getPusher()->authorizeChannel($channel, $socketId), true));
    }
}
