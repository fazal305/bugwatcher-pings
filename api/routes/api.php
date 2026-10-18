<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BroadcastAuthController;
use App\Http\Controllers\Api\CronController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\RealtimeTokenController;
use App\Http\Controllers\Api\TestErrorController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\PingCors;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

// Error ingestion: unauthenticated apart from the project key, rate limited inside the controller.
Route::match(['POST', 'OPTIONS'], '/v1/ping', PingController::class)
    ->middleware(PingCors::class)
    ->withoutMiddleware(SubstituteBindings::class);

Route::post('/cron/run', [CronController::class, 'run'])->middleware('throttle:cron');
Route::post('/cron/demo-reset', [CronController::class, 'demoReset'])->middleware('throttle:cron');

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/demo', [AuthController::class, 'demo'])->middleware('throttle:demo');

// Authenticated by the realtime JWT alone, not by Sanctum.
Route::post('/broadcasting/auth', BroadcastAuthController::class)->middleware('throttle:realtime');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/realtime/token', RealtimeTokenController::class);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);

    // Authorization runs as middleware, before validation, so non-members can't tell a project exists from a 422.
    Route::prefix('projects/{project}')->scopeBindings()->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->can('view', 'project');
        Route::patch('/', [ProjectController::class, 'update'])->can('manage', 'project');
        Route::post('/rotate-key', [ProjectController::class, 'rotateKey'])->can('manageAccess', 'project');

        Route::get('/members', [MemberController::class, 'index'])->can('view', 'project');
        Route::post('/members', [MemberController::class, 'store'])->can('manageAccess', 'project');
        Route::patch('/members/{member}', [MemberController::class, 'update'])->can('manageAccess', 'project')->whereNumber('member');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->can('manageAccess', 'project')->whereNumber('member');

        Route::get('/events', [EventController::class, 'index'])->can('view', 'project');
        Route::get('/events/{event}', [EventController::class, 'show'])->can('view', 'project')->where('event', '[0-9A-Za-z]{26}');
        Route::post('/test-error', TestErrorController::class)->can('manage', 'project')->middleware('throttle:test-error');

        Route::get('/issues', [IssueController::class, 'index'])->can('view', 'project');
        Route::patch('/issues/{issue}', [IssueController::class, 'update'])->can('view', 'project')->whereNumber('issue');

        Route::get('/webhooks', [WebhookController::class, 'index'])->can('view', 'project');
        Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])->can('view', 'project')->whereNumber('webhook');
        Route::middleware('throttle:webhook-writes')->group(function () {
            Route::post('/webhooks', [WebhookController::class, 'store'])->can('manageWebhooks', 'project');
            Route::patch('/webhooks/{webhook}', [WebhookController::class, 'update'])->can('manageWebhooks', 'project')->whereNumber('webhook');
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->can('manageWebhooks', 'project')->whereNumber('webhook');
        });
        Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])
            ->can('manageWebhooks', 'project')->middleware('throttle:webhook-test')->whereNumber('webhook');
    });
});
