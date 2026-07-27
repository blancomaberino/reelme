<?php

namespace App\Jobs;

use App\Models\Place;
use App\Services\Places\Enrichment\BusinessEnricher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs the "enrich as business" pass ({@see BusinessEnricher}) off the request
 * path (T-099 follow-up): dispatched when a place is first published so a shared
 * place gets its curated business fields + photo gallery automatically, without
 * a manual admin action. Best-effort — the enricher never throws and marks each
 * source's status; a run that finds nothing still stamps `enriched_at`.
 *
 * Idempotent for the auto path: unless `$force`, a place that already has an
 * `enriched_at` is skipped, so a re-share (or a duplicate dispatch from a
 * multi-source publish) never re-bills the external sources. The Filament action
 * re-runs synchronously and is unaffected.
 */
class EnrichPlace implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** Hold the per-place uniqueness lock long enough to cover one enrich run. */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $placeId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('default');
    }

    /**
     * Dedup concurrent dispatches for the same place (a multi-source publish, or
     * two near-simultaneous re-shares) so the external sources are billed once,
     * not per dispatch — the `enriched_at` run-time guard only catches the
     * sequential case. A forced re-enrich is exempt so an admin can always re-run.
     */
    public function uniqueId(): string
    {
        return $this->placeId.($this->force ? ':force' : '');
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ["place:{$this->placeId}", 'stage:enrich'];
    }

    public function handle(BusinessEnricher $enricher): void
    {
        $place = Place::find($this->placeId);

        // Gone, or already enriched (a sibling job/prior run got here) → skip so we
        // don't re-bill Google/website on a re-share.
        if ($place === null || (! $this->force && $place->enriched_at !== null)) {
            return;
        }

        $enricher->enrich($place);
    }
}
