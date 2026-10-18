<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Lets an external scheduler (a GitHub Actions workflow) drain the spool and prune old events on hosts without cron
 * or that sleep when idle. Disabled unless CRON_SECRET is set.
 */
class CronController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        set_time_limit(150);
        Artisan::call('pings:drain-spool');
        $spool = trim(Artisan::output());
        Artisan::call('pings:prune');
        $prune = trim(Artisan::output());

        return response()->json(['message' => 'OK', 'spool' => $spool, 'prune' => $prune]);
    }
}
