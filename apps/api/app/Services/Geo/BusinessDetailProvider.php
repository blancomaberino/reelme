<?php

namespace App\Services\Geo;

use App\Services\Geo\Exceptions\GeocodeFailed;

/**
 * An optional capability a {@see Geocoder} may also implement (T-084): fetch a
 * resolved place's extended business details (phone, website, hours) with a
 * wider, more billable provider field mask than {@see Geocoder::findPlace()}.
 * Kept separate from the base contract so a provider without it (Nominatim) — and
 * test doubles that only implement `Geocoder` — need not change; the enricher
 * feature-detects with `instanceof`.
 */
interface BusinessDetailProvider
{
    /**
     * Extended details for a known Google place id, or null when unsupported,
     * unconfigured (no key), or a miss. Only invoked by the on-demand enrich
     * action, never the pipeline, so the wider billed SKU is opt-in.
     *
     * @throws GeocodeFailed on a transient provider/network error (caller isolates)
     */
    public function businessDetails(string $googlePlaceId): ?BusinessDetails;
}
