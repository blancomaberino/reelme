<?php

namespace App\Services\Reviews\Drivers;

use App\Models\Place;
use App\Services\Places\GooglePlaceRefresher;
use App\Services\Reviews\ReviewSnippet;
use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\ReviewSourceSummary;

/**
 * The cached Google Places review source (T-082) — wraps the columns the
 * Geocoder populates and the {@see GooglePlaceRefresher}
 * keeps inside the ToS window (rating, count, `google_reviews_json`,
 * `google_reviews_synced_at`). Reads the cache only; it never calls Google.
 *
 * Id resolution keys on `google_place_id`: without one the place is omitted.
 * A place that has a place id but whose cached signal was dropped (rating gone,
 * no snippets) is also omitted rather than shown as an empty Google row.
 */
class GoogleReviewSource implements ReviewSource
{
    public const ID = 'google';

    public function id(): string
    {
        return self::ID;
    }

    public function summary(Place $place): ?ReviewSourceSummary
    {
        if ($place->google_place_id === null || $place->google_place_id === '') {
            return null;
        }

        $rating = $place->google_rating !== null ? (float) $place->google_rating : null;
        $snippets = array_map(
            ReviewSnippet::fromArray(...),
            array_values(array_filter(
                $place->google_reviews_json ?? [],
                is_array(...),
            )),
        );

        // A resolvable id but no usable content (dropped cache) → omit, no empty row.
        if ($rating === null && $snippets === []) {
            return null;
        }

        return new ReviewSourceSummary(
            source: self::ID,
            rating: $rating,
            count: (int) ($place->google_rating_count ?? 0),
            url: 'https://search.google.com/local/reviews?placeid='.rawurlencode($place->google_place_id),
            syncedAt: $place->google_reviews_synced_at,
            snippets: $snippets,
        );
    }
}
