<?php

namespace App\Console\Commands;

use App\Models\PlaceSource;
use App\Services\Places\DishMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Populates the `dishes` table for sources published before T-157 — everything
 * whose dishes only ever existed inside `extraction_snapshot_json`.
 *
 * Idempotent by construction, not by convention: {@see DishMaterializer}
 * REPLACES a source's dish set, so a second run rewrites the same rows and the
 * row count is unchanged. Safe to re-run after a partial failure, and safe to
 * run against a corpus that is already fully materialized.
 *
 * New sources need nothing from this: their rows are written by the model hook
 * ({@see App\Observers\PlaceSourceObserver}) the moment a snapshot is saved.
 */
class BackfillDishes extends Command
{
    protected $signature = 'reelmap:dishes:backfill';

    protected $description = 'Materialize first-class dish rows from existing place_source snapshots (pre-T-157 rows)';

    public function handle(DishMaterializer $materializer): int
    {
        $sources = 0;
        /** @var list<int> $failed */
        $failed = [];

        // Sources whose snapshot carries no dishes are cleared in ONE statement
        // rather than one transaction each. That is not a micro-optimisation at
        // this size: `materialize()` on a dishless source costs a lock, a DELETE
        // and a BEGIN/COMMIT — four round-trips to do nothing — and most sources
        // carry no dishes, so a 71k-source corpus spent ~170k round-trips on the
        // empty majority. This command runs inside the deploy's maintenance
        // window, so that time is downtime.
        $carriesDishes = "jsonb_array_length(coalesce(extraction_snapshot_json->'dishes', '[]'::jsonb)) > 0";

        DB::statement(
            'DELETE FROM dishes d USING place_sources ps
             WHERE d.place_source_id = ps.id AND NOT ('.$carriesDishes.')'
        );

        PlaceSource::query()
            ->whereRaw($carriesDishes)
            ->chunkById(200, function ($chunk) use ($materializer, &$sources, &$failed): void {
                foreach ($chunk as $source) {
                    try {
                        $materializer->materialize($source);
                        $sources++;
                    } catch (Throwable $e) {
                        // A source deleted or republished by a worker mid-run is a
                        // routine race against a live app, not a reason to abandon a
                        // walk of the whole corpus at source 71,000 with no record of
                        // where it stopped. A republished source got its rows from
                        // the observer anyway.
                        $failed[] = $source->id;
                        report($e);
                    }
                }
            });

        $this->components->info("Materialized dishes from {$sources} place sources.");

        if ($failed !== []) {
            // Reported AND non-zero exit: a partial run that looks successful is
            // how a place keeps an empty menu with nobody knowing.
            $this->components->error('Failed on '.count($failed).' source(s): '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
