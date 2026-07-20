<?php

namespace App\Services\Places\Enrichment\Sources;

use App\Models\Place;
use App\Services\Geo\BusinessDetailProvider;
use App\Services\Geo\Geocoder;
use App\Services\Places\Enrichment\BusinessEnrichmentSource;

/**
 * Google/GMB business fields (T-084): phone, website, opening hours for a place
 * that already carries a `google_place_id`, via the geocoder's wider — opt-in,
 * more billable — {@see BusinessDetailProvider} mask. Rating/review content stays
 * on Google's own columns, refreshed by {@see ReviewsBusinessSource}. A miss, a
 * place without a Google id, or a provider that can't supply details yields no
 * patch. Config-gated by `places.enrich.google.enabled`.
 */
class GoogleBusinessSource implements BusinessEnrichmentSource
{
    public function __construct(private readonly Geocoder $geocoder) {}

    public function id(): string
    {
        return 'google';
    }

    /**
     * @return array<string, mixed>
     */
    public function enrich(Place $place): array
    {
        if (! (bool) config('places.enrich.google.enabled', true)) {
            return [];
        }

        $placeId = trim((string) $place->google_place_id);
        if ($placeId === '' || ! $this->geocoder instanceof BusinessDetailProvider) {
            return [];
        }

        return $this->geocoder->businessDetails($placeId)?->toPlacePatch() ?? [];
    }
}
