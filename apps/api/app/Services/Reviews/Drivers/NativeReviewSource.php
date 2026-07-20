<?php

namespace App\Services\Reviews\Drivers;

use App\Http\Controllers\Api\V1\PlaceController;
use App\Models\Place;
use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\ReviewSourceSummary;

/**
 * The native Reelmap review source (T-082) — wraps the `reviews_count` /
 * `reviews_avg_rating` aggregate that {@see PlaceController}
 * loads (hidden/moderated reviews already excluded). Intrinsic to the place, so
 * it always "resolves"; it is omitted only when there are zero reviews (an empty
 * "Reelmap · 0 reviews" row is noise — the in-app composer already invites the
 * first one). No external url: native reviews live in-app, rendered inline by
 * the detail screen's own reviews section, so `snippets` stays empty here too.
 */
class NativeReviewSource implements ReviewSource
{
    public const ID = 'native';

    public function id(): string
    {
        return self::ID;
    }

    public function summary(Place $place): ?ReviewSourceSummary
    {
        $count = (int) ($place->reviews_count ?? 0);
        if ($count <= 0) {
            return null;
        }

        return new ReviewSourceSummary(
            source: self::ID,
            rating: round((float) $place->reviews_avg_rating, 1),
            count: $count,
            url: null,
            syncedAt: null,
            snippets: [],
        );
    }
}
