<?php

namespace App\Providers;

use App\Events\ShareStatusChanged;
use App\Listeners\SendShareStatusNotification;
use App\Models\Influencer;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Instagram\Provider as InstagramProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

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
        // Polymorphic follow targets (T-037) — aliases in the DB, never FQCNs.
        Relation::enforceMorphMap([
            'user' => User::class,
            'influencer' => Influencer::class,
        ]);

        // Register the SocialiteProviders "instagram" driver (T-015). This
        // codebase has no EventServiceProvider, so wire the listener explicitly.
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('instagram', InstagramProvider::class);
        });

        // Push/DB notifications on pipeline outcomes (T-027). No EventServiceProvider
        // here either, so register the listener explicitly.
        Event::listen(ShareStatusChanged::class, SendShareStatusNotification::class);

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

        // Review writes + reports (T-059): spam-adjacent like shares — bound
        // them so one token can't churn reviews or flood the moderation queue.
        RateLimiter::for('reviews', fn (Request $request) => [
            Limit::perMinute(10)->by('reviews:min:'.$request->user()?->id),
            Limit::perDay(100)->by('reviews:day:'.$request->user()?->id),
        ]);
    }
}
