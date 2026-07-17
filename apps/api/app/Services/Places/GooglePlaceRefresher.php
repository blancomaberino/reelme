<?php

namespace App\Services\Places;

use App\Models\Place;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;

/**
 * Refresh-or-drop for a place's cached Google signal (rating + review snippets),
 * shared by the daily `reelmap:google:refresh-stale` sweep AND the on-demand
 * refresh a re-share triggers when it re-resolves a known place (T-059/T-080).
 *
 * Google-ToS: cached Places review content may not be kept beyond ~30 days.
 * Past that window we re-fetch through the geocoder and, when that yields nothing
 * usable, DROP the cached content rather than keep it — compliance over
 * prettiness. Only a result that is the SAME Google place carrying a rating +
 * reviews is trusted; anything else drops.
 */
class GooglePlaceRefresher
{
    public function __construct(private readonly Geocoder $geocoder) {}

    /** Max age (days) of cached Google content before it must be refreshed or dropped. */
    public function windowDays(?int $override = null): int
    {
        return max(1, $override ?? (int) config('places.google.refresh_after_days', 30));
    }

    /**
     * True when the place carries cached Google review snippets that have aged
     * past the ToS window (or were never stamped). Scoped to snippet content
     * exactly like the sweep query — a rating-only place (no `google_reviews_json`)
     * is never "stale", so it is neither refreshed nor dropped.
     */
    public function isStale(Place $place, ?int $windowDays = null): bool
    {
        if ($place->google_reviews_json === null) {
            return false;
        }

        $syncedAt = $place->google_reviews_synced_at;

        return $syncedAt === null || $syncedAt->lt(now()->subDays($this->windowDays($windowDays)));
    }

    /**
     * Apply an already-fetched geocode as a refresh-or-drop. The resolve path
     * holds a `$geo` from the dedup lookup, so no extra Google call is made.
     * Mutates in memory — the caller persists. Returns whether the model changed.
     */
    public function applyGeocode(Place $place, ?GeocodeResult $geo): bool
    {
        if ($this->isTrustworthy($place, $geo)) {
            /** @var GeocodeResult $geo */
            $place->google_rating = (string) $geo->rating;
            $place->google_rating_count = $geo->ratingCount;
            $place->google_reviews_json = $geo->reviews;
            $place->google_reviews_synced_at = now();

            return true;
        }

        return $this->drop($place);
    }

    /**
     * Fetch a fresh geocode for a stale place and refresh-or-drop it, saving when
     * changed. The sweep's per-row entry point. Never suppresses provider errors
     * — the sweep isolates them per row. Returns 'refreshed' | 'dropped' |
     * 'unchanged'.
     */
    public function refresh(Place $place): string
    {
        $geo = $this->geocoder->findPlace($place->name, new GeoHints(
            city: $place->city,
            country: $place->country_code,
        ));

        if (! $this->applyGeocode($place, $geo)) {
            return 'unchanged';
        }

        $place->save();

        return $place->google_rating !== null ? 'refreshed' : 'dropped';
    }

    /** Trust only the SAME Google place carrying an actual rating + review snippets. */
    private function isTrustworthy(Place $place, ?GeocodeResult $geo): bool
    {
        return $geo !== null
            && $place->google_place_id !== null
            && $geo->googlePlaceId === $place->google_place_id
            && $geo->rating !== null
            && $geo->reviews !== [];
    }

    /** Null out the whole cached Places signal. Returns whether anything changed. */
    private function drop(Place $place): bool
    {
        if ($place->google_rating === null
            && $place->google_rating_count === null
            && $place->google_reviews_json === null
            && $place->google_reviews_synced_at === null) {
            return false;
        }

        $place->google_rating = null;
        $place->google_rating_count = null;
        $place->google_reviews_json = null;
        $place->google_reviews_synced_at = null;

        return true;
    }
}
