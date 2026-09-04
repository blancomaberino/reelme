<?php

namespace App\Services\Places\Enrichment\Sources;

use App\Models\Place;
use App\Services\Geo\TimezoneResolver;
use App\Services\Places\Enrichment\BusinessEnrichmentSource;

/**
 * The venue's IANA timezone, resolved from the coordinates the place already
 * carries (T-155). Without it there is no open/closed cue at all — the whole
 * status computation is gated on this column being present.
 *
 * Its own source rather than a few lines inside {@see GoogleBusinessSource},
 * because the two have different preconditions and would otherwise be coupled
 * by accident: that source needs a `google_place_id` and is gated on
 * `places.enrich.google.enabled`, while a timezone needs only a point on Earth
 * and is just as resolvable for a place Nominatim geocoded. Folding this in
 * there would silently deny a status cue to every non-Google place.
 *
 * Resolved ONCE. A place that already has a timezone contributes nothing on
 * later runs: a restaurant does not move, and the lookup is a separately billed
 * request. (A place that genuinely relocates is a re-geocode, which clears the
 * column — not an enrich.)
 */
class TimezoneBusinessSource implements BusinessEnrichmentSource
{
    public const SOURCE_ID = 'timezone';

    public function __construct(private readonly TimezoneResolver $resolver) {}

    public function id(): string
    {
        return self::SOURCE_ID;
    }

    /**
     * @return array<string, mixed>
     */
    public function enrich(Place $place): array
    {
        if (! (bool) config('places.enrich.timezone.enabled', true)) {
            return [];
        }

        // Already known: contribute nothing rather than re-bill the lookup.
        if (filled($place->timezone)) {
            return [];
        }

        // `coordinates()` re-reads the PostGIS point with its own query, so it is
        // called once here and only for a place that still has a row to read.
        if (! $place->exists) {
            return [];
        }

        $coordinates = $place->coordinates();
        $zone = $this->resolver->resolve($coordinates['lat'], $coordinates['lng']);

        // A null resolve is not a failure to report — it is "no cue for this
        // place", which is the honest outcome and the one the status computation
        // is built to handle.
        return $zone === null ? [] : ['timezone' => $zone];
    }
}
