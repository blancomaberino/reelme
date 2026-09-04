<?php

namespace App\Services\Geo;

/**
 * Resolve a point on Earth to the IANA timezone id in force there (T-155).
 *
 * A separate contract from {@see Geocoder} and {@see BusinessDetailProvider} for
 * two reasons, both practical:
 *
 * 1. **It takes coordinates, not a place id.** Google Place Details — the call
 *    behind `businessDetails()` — does not return a timezone at any field mask,
 *    so this is a different request to a different API, billed under a different
 *    SKU, and it belongs at the seam that already holds a located `Place`.
 * 2. **It must be fakeable.** NFR-15: no test may reach a third party. The whole
 *    open/closed cue is downstream of this value, so the fake is what makes the
 *    cue testable at all.
 *
 * Implementations return `null` rather than guessing. A null timezone means the
 * place shows its hours lines and **no** status cue — never a cue computed
 * against the server's own timezone, which would be right only for venues that
 * happen to share it.
 */
interface TimezoneResolver
{
    /**
     * The IANA zone id for a coordinate ("America/Montevideo"), or null when it
     * cannot be established.
     *
     * Implementations must never return a fixed UTC offset ("-03:00") or an
     * abbreviation ("EST"): an offset is wrong for half the year wherever DST
     * applies, and a status cue computed from one is wrong for half the year.
     */
    public function resolve(float $lat, float $lng): ?string;
}
