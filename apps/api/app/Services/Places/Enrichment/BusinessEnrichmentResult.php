<?php

namespace App\Services\Places\Enrichment;

use App\Models\PlaceEdit;

/**
 * The outcome of one "enrich as business" run (T-084): the per-source outcomes
 * (for the Filament notification / logs) and the audit row written, if any field
 * actually changed. A run with every source skipped/failed and no change yields
 * a null {@see $edit} but still reports its source statuses.
 */
final readonly class BusinessEnrichmentResult
{
    /**
     * @param  list<array{source: string, status: string, fields: list<string>}>  $sources
     */
    public function __construct(
        public array $sources,
        public ?PlaceEdit $edit,
    ) {}

    /**
     * Curated field names that actually changed on the place.
     *
     * @return list<string>
     */
    public function changedFields(): array
    {
        return $this->edit !== null ? array_keys($this->edit->changes) : [];
    }

    public function anyFailed(): bool
    {
        foreach ($this->sources as $source) {
            if ($source['status'] === 'failed') {
                return true;
            }
        }

        return false;
    }
}
