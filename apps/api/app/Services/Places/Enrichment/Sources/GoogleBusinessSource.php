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

        $details = $this->geocoder->businessDetails($placeId);
        if ($details === null) {
            return [];
        }

        $patch = $details->toPlacePatch();

        // Google photos ride along as gallery entries tagged `google` (T-099); the
        // enricher ranks/dedups/caps them against the website-owned images. The
        // ownership signal is only the photo's attribution, so ranking (business
        // name/domain match) is the enricher's job, not ours.
        $gallery = array_map(
            fn (array $image): array => ['url' => $image['url'], 'source' => 'google', 'attribution' => $image['attribution'] ?? null],
            $details->images,
        );
        if ($gallery !== []) {
            $patch['gallery_json'] = $gallery;
        }

        return $patch;
    }
}
