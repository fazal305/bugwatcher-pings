<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json(
            ['message' => 'Too many attempts. Please wait a minute and try again.'],
            429,
            $headers,
        );
        $email = fn (Request $request) => strtolower(trim((string) $request->input('email')));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip())->response($tooMany));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by('login-ip:'.$request->ip())->response($tooMany),
            Limit::perMinute(5)->by('login-email:'.$email($request))->response($tooMany),
        ]);

        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by('register-ip:'.$request->ip())->response($tooMany),
            Limit::perHour(20)->by('register-ip-hour:'.$request->ip())->response($tooMany),
            Limit::perMinute(3)->by('register-email:'.$email($request))->response($tooMany),
        ]);

        RateLimiter::for('demo', fn (Request $request) => [
            Limit::perMinute(5)->by('demo-ip:'.$request->ip())->response($tooMany),
            Limit::perDay(50)->by('demo-ip-day:'.$request->ip())->response($tooMany),
        ]);
    }
}
