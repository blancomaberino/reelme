<?php

namespace App\Console\Commands;

use App\Models\PlaceSource;
use App\Services\Places\TagMaterializer;
use Illuminate\Console\Command;

/**
 * One-off backfill for places published before T-031: re-runs the tag
 * materializer over every place_source snapshot. Idempotent (max-confidence
 * merge on re-attach) — safe to run repeatedly.
 */
class BackfillTags extends Command
{
    protected $signature = 'reelmap:tags:backfill';

    protected $description = 'Materialize tags from existing place_source snapshots (pre-T-031 rows)';

    public function handle(TagMaterializer $materializer): int
    {
        $count = 0;

        PlaceSource::query()->with(['place', 'analysisRun'])->chunkById(200, function ($sources) use ($materializer, &$count) {
            foreach ($sources as $source) {
                $place = $source->place;
                if ($place === null) {
                    continue;
                }

                $confidence = $source->analysisRun?->overall_confidence;
                $materializer->materialize(
                    $place,
                    $source->extraction_snapshot_json,
                    $confidence !== null ? (float) $confidence : null,
                );
                $place->save(); // re-syncs the search document with its tag slugs

                $count++;
            }
        });

        $this->components->info("Backfilled tags from {$count} place sources.");

        return self::SUCCESS;
    }
}
