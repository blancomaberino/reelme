<?php

namespace App\Services\Places\Enrichment\Sources;

use App\Models\Place;
use App\Services\Places\Enrichment\BusinessEnrichmentSource;
use App\Services\Places\GooglePlaceRefresher;
use App\Services\Reviews\ReviewSourceRegistry;
use App\Services\Reviews\Trustpilot\TrustpilotReviewRefresher;

/**
 * Refreshes a place's external review signals as part of an enrich run (T-084) —
 * the Google review columns (via {@see GooglePlaceRefresher}, within ToS) and the
 * Trustpilot cache (via {@see TrustpilotReviewRefresher}). Reviews live in their
 * own columns/tables, so this source contributes no curated-field patch; it only
 * updates the caches the {@see ReviewSourceRegistry} reads.
 * Each provider is individually gated by its `reviews.sources.*` toggle.
 */
class ReviewsBusinessSource implements BusinessEnrichmentSource
{
    public function __construct(
        private readonly GooglePlaceRefresher $google,
        private readonly TrustpilotReviewRefresher $trustpilot,
    ) {}

    public function id(): string
    {
        return 'reviews';
    }

    /**
     * @return array<string, mixed>
     */
    public function enrich(Place $place): array
    {
        if (! (bool) config('places.enrich.reviews.enabled', true)) {
            return [];
        }

        if ((bool) config('reviews.sources.google.enabled', true) && trim((string) $place->google_place_id) !== '') {
            $this->google->refresh($place);
        }

        if ((bool) config('reviews.sources.trustpilot.enabled', false)) {
            $this->trustpilot->refresh($place);
        }

        return [];
    }
}
