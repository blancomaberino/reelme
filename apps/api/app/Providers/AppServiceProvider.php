<?php

namespace App\Providers;

use App\Models\Influencer;
use App\Models\Offer;
use App\Models\Place;
use App\Models\Share;
use App\Models\SourcePost;
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
        // Every polymorphic target in the app — aliases in the DB, never FQCNs.
        //
        // `enforceMorphMap` (not `morphMap`) is deliberate: it makes an
        // unmapped model throw instead of silently falling back to writing its
        // class name, which is the failure that produces a table with two
        // spellings of the same type and queries that match half the rows.
        //
        // Anything queried by morph column must use `$model->getMorphClass()`,
        // never `Model::class` — comparing against the FQCN matches zero rows
        // and does it silently (T-050 shipped that bug in the purge; three
        // tests passed because the fixtures made the same mistake).
        Relation::enforceMorphMap([
            // Follow targets (T-037).
            'user' => User::class,
            'influencer' => Influencer::class,
            // Report targets (T-049, 02 §3.17 + 03 §2.16).
            'place' => Place::class,
            'share' => Share::class,
            'source_post' => SourcePost::class,
            'offer' => Offer::class,
        ]);

        // Register the SocialiteProviders "instagram" driver (T-015). This
        // codebase has no EventServiceProvider, so wire the listener explicitly.
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('instagram', InstagramProvider::class);
        });

        // Push/DB notifications on pipeline outcomes (T-027). No EventServiceProvider
        // here either, so register the listener explicitly.
        /*
         * Listeners in `app/Listeners` are NOT registered here.
         *
         * Laravel discovers them automatically from their `handle()` type hint,
         * so a manual `Event::listen()` registers the SAME listener a second
         * time and it runs twice per event. That was live for
         * SendShareStatusNotification (every share status change sent two
         * notifications) and was caught adding the T-043 redemption listener —
         * where a duplicate would become a duplicate LEDGER ENTRY once T-044
         * hangs the fee posting off the same event, i.e. a restaurant billed
         * twice for one visit.
         *
         * `tests/Feature/Queue/EventListenerRegistrationTest.php` pins the rule.
         */

        /*
         * Rate limits (03 §1, T-051). Every ceiling comes from config/quotas.php
         * so it can be raised during an incident without a deploy — a limiter
         * nobody can raise is a limiter somebody removes.
         *
         * Authenticated limits key on the USER id, never the IP: mobile
         * carriers NAT thousands of subscribers behind one address, so an
         * IP-keyed authenticated limit throttles a city because one person was
         * busy.
         */

        // Auth endpoints: IP-keyed on purpose — there is no user yet, and the
        // thing being bounded is guessing. The 429 renders through
        // ApiExceptionRenderer as a rate_limited envelope with Retry-After.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(
            (int) config('quotas.rate.auth')
        )->by($request->ip()));

        // The catch-all for authenticated traffic, falling back to IP for the
        // handful of public reads that carry no session.
        RateLimiter::for('api', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('quotas.rate.default'))->by('api:'.$request->user()->id)
            : Limit::perMinute((int) config('quotas.rate.public'))->by('api:ip:'.$request->ip()));

        /*
         * Share-status polling. AnalysisStatus polls every 2.5s = 24/min, and
         * that is ONE screen — watching two ingests would eat most of the
         * default before the app made any other request, and the user would be
         * throttled for using the product exactly as designed.
         */
        RateLimiter::for('polling', fn (Request $request) => Limit::perMinute(
            (int) config('quotas.rate.polling')
        )->by('polling:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));

        // POST /shares: a burst limit AND the daily quota (NFR-12).
        RateLimiter::for('shares', fn (Request $request) => [
            Limit::perMinute(10)->by('shares:min:'.$request->user()?->id),
            Limit::perDay((int) config('quotas.daily.shares'))->by('shares:day:'.$request->user()?->id),
        ]);

        // GET /map/places: 120/min per user (falls back to IP for anonymous —
        // the route has no auth middleware, so resolve via the sanctum guard).
        RateLimiter::for('map', fn (Request $request) => Limit::perMinute((int) config('quotas.rate.map'))
            ->by('map:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));

        // Review writes + reports (T-059): spam-adjacent like shares — bound
        // them so one token can't churn reviews or flood the moderation queue.
        RateLimiter::for('reviews', fn (Request $request) => [
            Limit::perMinute(10)->by('reviews:min:'.$request->user()?->id),
            Limit::perDay((int) config('quotas.daily.reviews'))->by('reviews:day:'.$request->user()?->id),
        ]);

        // Redemption verify: keyed on the STAFF ACCOUNT, not the IP — one till
        // behind a shop's NAT must not throttle the shop next door. A busy
        // counter is bursty; the real anti-fraud bound is the hourly cap in
        // RedemptionGuards, not this.
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(
            (int) config('quotas.rate.verify')
        )->by('verify:'.($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip())));
    }
}
