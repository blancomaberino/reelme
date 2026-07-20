<?php

namespace App\Services\Places\Enrichment;

use App\Models\Place;
use App\Services\Reviews\ReviewSource;

/**
 * One pluggable source the "enrich as business" action pulls from (T-084) —
 * Google/GMB, the business's own website, the review aggregator, … — mirroring
 * the {@see ReviewSource} seam. A source proposes a
 * curated-field patch; it may also refresh out-of-band caches (reviews) and
 * return nothing. The {@see BusinessEnricher} isolates failures, merges patches,
 * and applies them respecting locked fields — so a source never writes directly.
 */
interface BusinessEnrichmentSource
{
    /** Stable identifier, used in the audit/result (e.g. 'google', 'website'). */
    public function id(): string;

    /**
     * Whatever curated fields this source discovered, as a {field: value} patch
     * over {@see Place::CURATED_FIELDS} (may be empty). May throw — the enricher
     * catches and reports, degrading to the other sources.
     *
     * @return array<string, mixed>
     */
    public function enrich(Place $place): array;
}
