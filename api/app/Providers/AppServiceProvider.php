<?php

namespace App\Providers;

use App\Pings\Masker;
use App\Pings\PingRateLimiter;
use App\Pings\PingSpool;
use App\Pings\PingValidator;
use App\Pings\ProjectKeyResolver;
use App\Realtime\Jwt;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProjectKeyResolver::class, fn () => new ProjectKeyResolver(
            Cache::store(config('pings.cache_store')),
            config('pings.key_cache_ttl'),
            config('pings.key_cache_negative_ttl'),
        ));
        $this->app->singleton(PingRateLimiter::class, fn () => new PingRateLimiter(
            Cache::store(config('pings.cache_store')),
            config('pings.rate_limit_per_key'),
            config('pings.rate_limit_per_ip'),
        ));
        $this->app->singleton(PingValidator::class, fn () => new PingValidator(config('pings.max_frames'), config('pings.max_context_lines')));
        $this->app->singleton(Masker::class, fn () => new Masker(config('pings.sensitive_keys')));
        $this->app->singleton(PingSpool::class, fn () => new PingSpool(
            Redis::connection(config('pings.redis_connection')),
            config('pings.spool_key'),
            config('pings.dead_letter_key'),
        ));
        $this->app->singleton(Jwt::class, fn () => new Jwt((string) config('services.realtime.jwt_secret')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The ingest route is called from any website and sets its own CORS headers; it also reads the raw body,
        // so the global input-rewriting middleware would only cost time.
        $isPing = fn (Request $request) => $request->is('api/v1/ping');
        HandleCors::skipWhen($isPing);
        TrimStrings::skipWhen($isPing);
        ConvertEmptyStringsToNull::skipWhen($isPing);

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

        // Generous enough for a dashboard resubscribing after reconnects, tight enough to blunt token guessing.
        RateLimiter::for('realtime', fn (Request $request) => Limit::perMinute(60)->by('realtime-ip:'.$request->ip())->response($tooMany));

        RateLimiter::for('cron', fn (Request $request) => Limit::perMinute(10)->by('cron-ip:'.$request->ip())->response($tooMany));

        RateLimiter::for('demo', fn (Request $request) => [
            Limit::perMinute(5)->by('demo-ip:'.$request->ip())->response($tooMany),
            Limit::perDay(50)->by('demo-ip-day:'.$request->ip())->response($tooMany),
        ]);
    }
}
