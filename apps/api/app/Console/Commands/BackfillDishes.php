<?php

namespace App\Console\Commands;

use App\Models\PlaceSource;
use App\Services\Places\DishMaterializer;
use Illuminate\Console\Command;

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
 * ({@see App\Models\Concerns\MaterializesDishes}) the moment a snapshot is saved.
 */
class BackfillDishes extends Command
{
    protected $signature = 'reelmap:dishes:backfill';

    protected $description = 'Materialize first-class dish rows from existing place_source snapshots (pre-T-157 rows)';

    public function handle(DishMaterializer $materializer): int
    {
        $sources = 0;

        PlaceSource::query()->chunkById(200, function ($chunk) use ($materializer, &$sources): void {
            foreach ($chunk as $source) {
                $materializer->materialize($source);
                $sources++;
            }
        });

        $this->components->info("Materialized dishes from {$sources} place sources.");

        return self::SUCCESS;
    }
}
