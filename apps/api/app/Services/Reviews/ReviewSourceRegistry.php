<?php

namespace App\Services\Reviews;

use App\Models\Place;
use App\Providers\ReviewsServiceProvider;
use Throwable;

/**
 * Ordered set of enabled {@see ReviewSource} drivers (T-082), wired in
 * {@see ReviewsServiceProvider}. Summarizing a place asks each
 * driver in turn and collects the non-null summaries — the `review_sources[]`
 * the place detail renders.
 *
 * Failure isolation is the whole point: drivers already never throw, but the
 * registry still guards each call so one misbehaving provider can never blank
 * the others (or the response). A place with no resolvable source yields [].
 */
class ReviewSourceRegistry
{
    /**
     * @param  list<ReviewSource>  $sources
     */
    public function __construct(private readonly array $sources) {}

    /**
     * The non-null summaries for a place, in driver order.
     *
     * @return list<ReviewSourceSummary>
     */
    public function summarize(Place $place): array
    {
        $summaries = [];

        foreach ($this->sources as $source) {
            try {
                $summary = $source->summary($place);
            } catch (Throwable $e) {
                // A driver contract-violation (it should have returned null) must
                // still not sink the aggregate — report and skip just this one.
                report($e);

                continue;
            }

            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        return $summaries;
    }

    /**
     * The enabled driver ids, in order — for diagnostics/tests.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(fn (ReviewSource $s): string => $s->id(), $this->sources);
    }
}
