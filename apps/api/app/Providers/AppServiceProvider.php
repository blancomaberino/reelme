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
        // Auth endpoints: 5/min per IP (03-api-design §1). The 429 renders through
        // ApiExceptionRenderer as a rate_limited error envelope with Retry-After.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // POST /shares: 10/min + 100/day per user (03 §1).
        RateLimiter::for('shares', fn (Request $request) => [
            Limit::perMinute(10)->by('shares:min:'.$request->user()?->id),
            Limit::perDay(100)->by('shares:day:'.$request->user()?->id),
        ]);

        // GET /map/places: 120/min per user (falls back to IP for anonymous —
        // the route has no auth middleware, so resolve via the sanctum guard).
        RateLimiter::for('map', fn (Request $request) => Limit::perMinute(120)
            ->by('map:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
    }
}
