<?php

namespace App\Providers;

use App\Services\Reviews\Drivers\GoogleReviewSource;
use App\Services\Reviews\Drivers\NativeReviewSource;
use App\Services\Reviews\Drivers\TrustpilotReviewSource;
use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\ReviewSourceRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the multi-source review aggregator (T-082): the enabled {@see ReviewSource}
 * drivers, in `config/reviews.php` order, behind a singleton
 * {@see ReviewSourceRegistry}. Registration gates purely on
 * `reviews.sources.<id>.enabled`. The Trustpilot api key gates *fetching*, not
 * registration — an enabled-but-unkeyed Trustpilot driver is still registered
 * but only ever reads an empty cache (the sweep no-ops without a key), so it
 * returns null and is harmlessly omitted. Tests can rebind with a fixed set.
 */
class ReviewsServiceProvider extends ServiceProvider
{
    /** id → driver class, in display order. */
    private const DRIVERS = [
        NativeReviewSource::ID => NativeReviewSource::class,
        GoogleReviewSource::ID => GoogleReviewSource::class,
        TrustpilotReviewSource::ID => TrustpilotReviewSource::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ReviewSourceRegistry::class, function (): ReviewSourceRegistry {
            $sources = [];
            foreach (self::DRIVERS as $id => $class) {
                if ((bool) config("reviews.sources.{$id}.enabled")) {
                    /** @var ReviewSource $driver */
                    $driver = $this->app->make($class);
                    $sources[] = $driver;
                }
            }

            return new ReviewSourceRegistry($sources);
        });
    }
}
