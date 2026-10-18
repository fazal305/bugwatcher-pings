<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Realtime\Jwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issues the short-lived token the dashboard presents when it subscribes to a project's WebSocket channel.
 */
class RealtimeTokenController extends Controller
{
    public const TTL_SECONDS = 300;

    public function __invoke(Request $request, Jwt $jwt): JsonResponse
    {
        $user = $request->user();
        $now = time();
        $projects = Project::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        $token = $jwt->encode([
            'sub' => (string) $user->id,
            'aud' => 'realtime',
            'iat' => $now,
            'exp' => $now + self::TTL_SECONDS,
            'projects' => $projects,
        ]);

        return response()->json([
            'token' => $token,
            'expires_in' => self::TTL_SECONDS,
            'projects' => $projects,
        ]);
    }
}
