<?php

namespace App\Services\Geo;

use App\Services\Geo\Exceptions\GeocodeFailed;

/**
 * Provider-agnostic geocoding seam (04 §6). ResolvePlace (T-023) depends on this
 * interface, not on Google, so the provider can be swapped and tests bind a
 * FakeGeocoder. A legitimate miss returns null; only transient/provider errors
 * throw.
 */
interface Geocoder
{
    /**
     * Resolve a place name (in native script) to a canonical place + coordinates.
     *
     * @throws GeocodeFailed on a transient provider/network error (retryable)
     */
    public function findPlace(string $name, GeoHints $hints): ?GeocodeResult;
}
