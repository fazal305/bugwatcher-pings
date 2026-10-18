<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Lets an external scheduler (GitHub Actions workflows) run maintenance on hosts without cron or that sleep when
 * idle. Disabled unless CRON_SECRET is set.
 */
class CronController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        set_time_limit(150);
        Artisan::call('pings:drain-spool');
        $spool = trim(Artisan::output());
        Artisan::call('pings:prune');
        $prune = trim(Artisan::output());

        return response()->json(['message' => 'OK', 'spool' => $spool, 'prune' => $prune]);
    }

    public function demoReset(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        Artisan::call('demo:reset');

        return response()->json(['message' => 'OK', 'demo' => trim(Artisan::output())]);
    }

    private function authorized(Request $request): bool
    {
        $secret = (string) config('services.cron.secret');

        return $secret !== '' && hash_equals($secret, (string) $request->bearerToken());
    }
}
