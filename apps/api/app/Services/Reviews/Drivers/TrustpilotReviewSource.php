<?php

namespace App\Services\Reviews\Drivers;

use App\Models\Place;
use App\Services\Reviews\ReviewSnippet;
use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\ReviewSourceSummary;
use App\Services\Reviews\Trustpilot\TrustpilotReviewRefresher;

/**
 * The Trustpilot review source (T-082). Reads the cached `external_place_reviews`
 * row that {@see TrustpilotReviewRefresher}
 * populates out of band (keyed on the business domain derived from the place's
 * website) — the request path never touches Trustpilot's API.
 *
 * Id resolution is the presence of that cached row: no row (place has no
 * website, or the sweep hasn't run / found nothing) → omitted. The driver reads
 * the already-loaded `externalReviews` relation when present to avoid an N+1.
 */
class TrustpilotReviewSource implements ReviewSource
{
    public const ID = 'trustpilot';

    public function id(): string
    {
        return self::ID;
    }

    public function summary(Place $place): ?ReviewSourceSummary
    {
        $row = $place->externalReview(self::ID);
        if ($row === null) {
            return null;
        }

        $rating = $row->rating !== null ? (float) $row->rating : null;
        $snippets = ReviewSnippet::listFromArray($row->snippets_json);

        // A stale, emptied cache row (score dropped, no snippets) is not shown.
        if ($rating === null && $snippets === []) {
            return null;
        }

        return new ReviewSourceSummary(
            source: self::ID,
            rating: $rating,
            count: $row->review_count,
            url: $row->url,
            syncedAt: $row->synced_at,
            snippets: $snippets,
        );
    }
}
