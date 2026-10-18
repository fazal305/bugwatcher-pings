<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only the host's private-network proxy is trusted, so clients can't spoof their IP past the rate limits.
        $middleware->trustProxies(at: ['10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', 'fd00::/8']);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A missing record and one the user can't see answer identically, so ids can't be probed and model names don't leak.
        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $e->getStatusCode() === 404
            && $request->is('api/*')
            && ($e->getPrevious() instanceof ModelNotFoundException || $e->getPrevious() instanceof AuthorizationException)
                ? response()->json(['message' => 'Not Found.'], 404)
                : null);
    })->create();
