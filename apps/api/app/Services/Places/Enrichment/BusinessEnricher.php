<?php

namespace App\Services\Places\Enrichment;

use App\Models\Place;
use App\Models\PlaceEdit;
use App\Services\Places\PlaceEditor;
use Throwable;

/**
 * Orchestrates the "enrich as business" action (T-084): runs each configured
 * {@see BusinessEnrichmentSource} in order, failure-isolated, merges their
 * proposed patches (the first source to supply a field wins — authoritative
 * sources are ordered first), and applies the merged patch through the single
 * {@see PlaceEditor} write path. That editor drops any human-locked field and
 * writes the audit row, so a manual override always survives an enrichment.
 *
 * It NEVER throws: a source blowing up is reported and degrades to the others,
 * mirroring the review aggregator's registry.
 */
class BusinessEnricher
{
    /**
     * @param  list<BusinessEnrichmentSource>  $sources  in merge-priority order
     */
    public function __construct(
        private readonly array $sources,
        private readonly PlaceEditor $editor,
    ) {}

    public function enrich(Place $place, ?int $userId = null): BusinessEnrichmentResult
    {
        /** @var array<string, mixed> $merged */
        $merged = [];
        $statuses = [];

        foreach ($this->sources as $source) {
            try {
                $patch = $source->enrich($place);
                foreach ($patch as $field => $value) {
                    if (! array_key_exists($field, $merged) && $value !== null && $value !== '' && $value !== []) {
                        $merged[$field] = $value;
                    }
                }
                $statuses[] = ['source' => $source->id(), 'status' => 'ok', 'fields' => array_keys($patch)];
            } catch (Throwable $e) {
                report($e);
                $statuses[] = ['source' => $source->id(), 'status' => 'failed', 'fields' => []];
            }
        }

        // Apply respecting locks + audit (no-op when nothing new/unlocked changed).
        $edit = $this->editor->apply($place, $merged, PlaceEdit::ORIGIN_ENRICHMENT, $userId);

        // Record that an enrichment ran, even if it changed nothing (a review-only
        // refresh, or every field already locked/current).
        $place->forceFill(['enriched_at' => now()])->save();

        return new BusinessEnrichmentResult($statuses, $edit);
    }
}
