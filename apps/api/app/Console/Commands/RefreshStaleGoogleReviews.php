<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeoHints;
use Illuminate\Console\Command;
use Throwable;

/**
 * Google-ToS compliance (T-059): Places review content may not be cached
 * beyond ~30 days. For every place whose cached snippets are stale, try to
 * re-fetch through the configured geocoder; when that yields nothing (keyless
 * Nominatim dev setup, place no longer found, API error) the cached content
 * is DROPPED rather than kept — compliance beats prettiness. Scheduled daily.
 */
class RefreshStaleGoogleReviews extends Command
{
    protected $signature = 'reelmap:google:refresh-stale {--days=30 : Max cache age before refresh-or-drop}';

    protected $description = 'Refresh or drop cached Google review snippets older than the ToS window';

    public function handle(Geocoder $geocoder): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $refreshed = 0;
        $dropped = 0;

        Place::query()
            ->whereNotNull('google_reviews_json')
            ->where(fn ($q) => $q
                ->whereNull('google_reviews_synced_at')
                ->orWhere('google_reviews_synced_at', '<', $cutoff))
            ->chunkById(100, function ($places) use ($geocoder, &$refreshed, &$dropped) {
                foreach ($places as $place) {
                    // Per-row isolation: one bad row must never abort the run —
                    // every remaining stale place would keep its expired content.
                    try {
                        $fresh = null;
                        $result = $geocoder->findPlace($place->name, new GeoHints(
                            city: $place->city,
                            country: $place->country_code,
                        ));
                        // Only trust a result that is the SAME Google place and
                        // actually carries a rating + reviews.
                        if ($result !== null
                            && $place->google_place_id !== null
                            && $result->googlePlaceId === $place->google_place_id
                            && $result->rating !== null
                            && $result->reviews !== []) {
                            $fresh = $result;
                        }

                        if ($fresh !== null) {
                            $place->google_rating = (string) $fresh->rating;
                            $place->google_rating_count = $fresh->ratingCount;
                            $place->google_reviews_json = $fresh->reviews;
                            $place->google_reviews_synced_at = now();
                            $refreshed++;
                        } else {
                            // Drop the whole cached Places signal — the rating is
                            // Places content under the same caching policy.
                            $place->google_rating = null;
                            $place->google_rating_count = null;
                            $place->google_reviews_json = null;
                            $place->google_reviews_synced_at = null;
                            $dropped++;
                        }
                        $place->save();
                    } catch (Throwable $e) {
                        report($e);
                    }
                }
            });

        $this->components->info("Google review cache: {$refreshed} refreshed, {$dropped} dropped.");

        return self::SUCCESS;
    }
}
