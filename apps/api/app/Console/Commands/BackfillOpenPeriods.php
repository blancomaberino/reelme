<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Places\OpenPeriodMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Populates `place_open_periods` for places enriched before T-158 — everything
 * whose structured hours only ever existed on the `places` row.
 *
 * Idempotent by construction, not by convention: {@see OpenPeriodMaterializer}
 * REPLACES a place's whole set, so a second run rewrites the same rows and the
 * count is unchanged. Safe to re-run after a partial failure.
 *
 * Newly enriched or edited places need nothing from this — their rows are
 * written by {@see App\Observers\PlaceObserver} the moment the hours or the
 * timezone are saved.
 */
class BackfillOpenPeriods extends Command
{
    protected $signature = 'reelmap:open-periods:backfill';

    protected $description = 'Materialize open-period rows from existing places (pre-T-158 rows)';

    public function handle(OpenPeriodMaterializer $materializer): int
    {
        $places = 0;
        /** @var list<int> $failed */
        $failed = [];

        // A place that cannot produce rows is cleared in ONE statement rather
        // than one transaction each. `materialize()` on such a place costs a
        // lock, a DELETE and a BEGIN/COMMIT to do nothing, and the great
        // majority of the corpus has no structured hours at all — T-155 shipped
        // them with no backfill, so a place only has periods once it has been
        // re-enriched since. This runs inside the deploy's maintenance window,
        // so that time is downtime.
        //
        // `jsonb_typeof(...) = 'array'` rather than a bare
        // `jsonb_array_length`, which RAISES on a key that is present and not an
        // array. The CASE wraps the VALUE instead of guarding the call with an
        // `AND`, because Postgres does not promise to evaluate AND operands left
        // to right — the same shape, and the same reason, as
        // {@see BackfillDishes} and {@see App\Models\Place::DISCOUNTS_JSONB}.
        // Every column is qualified with `places.`, which is load-bearing in
        // BOTH statements: `timezone` exists on `place_open_periods` too, so the
        // DELETE below is ambiguous without it — and the alias has to be the
        // real table name so the same string can be reused by the Eloquent query.
        $periodsArray = "CASE WHEN jsonb_typeof(places.opening_hours_periods_json) = 'array'
             THEN places.opening_hours_periods_json ELSE '[]'::jsonb END";
        $carriesHours = "places.timezone IS NOT NULL AND jsonb_array_length({$periodsArray}) > 0";

        DB::statement(
            'DELETE FROM place_open_periods pop USING places
             WHERE pop.place_id = places.id AND NOT ('.$carriesHours.')'
        );

        Place::query()
            ->withoutGlobalScopes()
            ->whereRaw($carriesHours)
            ->chunkById(200, function ($chunk) use ($materializer, &$places, &$failed): void {
                foreach ($chunk as $place) {
                    try {
                        $materializer->materialize($place);
                        $places++;
                    } catch (Throwable $e) {
                        // A place deleted or re-enriched by a worker mid-run is a
                        // routine race against a live app, not a reason to abandon
                        // the walk with no record of where it stopped. A re-enriched
                        // place got its rows from the observer anyway.
                        $failed[] = $place->id;
                        report($e);
                    }
                }
            });

        $this->components->info("Materialized open periods for {$places} places.");

        if ($failed !== []) {
            // Reported AND non-zero exit: a partial run that looks successful is
            // how a place stays unlistable with nobody knowing.
            $this->components->error('Failed on '.count($failed).' place(s): '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
