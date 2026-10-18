<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/demo', [AuthController::class, 'demo'])->middleware('throttle:demo');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);

    // Authorization runs as middleware, before validation, so non-members can't tell a project exists from a 422.
    Route::prefix('projects/{project}')->scopeBindings()->group(function () {
        Route::get('/', [ProjectController::class, 'show'])->can('view', 'project');
        Route::patch('/', [ProjectController::class, 'update'])->can('manage', 'project');
        Route::post('/rotate-key', [ProjectController::class, 'rotateKey'])->can('manage', 'project');

        Route::get('/members', [MemberController::class, 'index'])->can('view', 'project');
        Route::post('/members', [MemberController::class, 'store'])->can('manage', 'project');
        Route::patch('/members/{member}', [MemberController::class, 'update'])->can('manage', 'project')->whereNumber('member');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->can('manage', 'project')->whereNumber('member');
    });
});
