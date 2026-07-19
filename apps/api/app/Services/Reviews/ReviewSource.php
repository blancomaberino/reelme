<?php

namespace App\Services\Reviews;

use App\Models\Place;
use App\Services\Geo\Geocoder;

/**
 * A pluggable review provider (T-082) — same shape as the {@see Geocoder}
 * and SourceAdapter seams the codebase already uses. Each driver reads a place's
 * *already-cached* signal for one source (never fetches inline on the request)
 * and reduces it to a {@see ReviewSourceSummary}.
 *
 * A provider that cannot resolve the place — no id for it, cache empty, source
 * disabled — returns null and is simply omitted (no empty row). Implementations
 * MUST NOT throw: a broken source degrades to the others, so any error is
 * swallowed to null. The registry isolates failures too, as belt-and-braces.
 */
interface ReviewSource
{
    /** Stable source id used as the payload key and UI label lookup (e.g. `google`). */
    public function id(): string;

    /** The place's summary for this source, or null when it does not resolve. Never throws. */
    public function summary(Place $place): ?ReviewSourceSummary;
}
