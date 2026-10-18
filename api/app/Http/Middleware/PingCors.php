<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error reports come from customers' sites on any origin. They never carry cookies, so a wildcard origin without
 * credentials is safe here, while the rest of the API stays limited to the dashboard's origin.
 */
class PingCors
{
    private const HEADERS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Expose-Headers' => 'Retry-After, Server-Timing',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return response()->noContent()->withHeaders(self::HEADERS + [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-BugWatcher-Key',
                'Access-Control-Max-Age' => '86400',
            ]);
        }

        return $next($request)->withHeaders(self::HEADERS);
    }
}
