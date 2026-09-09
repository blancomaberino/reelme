<?php

namespace App\Console\Commands;

use App\Models\Place;
use App\Services\Places\OpenPeriodMaterializer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    protected $signature = 'reelmap:open-periods:backfill {--fail-on-drift : exit non-zero if any place had to be repaired}';

    protected $description = 'Materialize open-period rows from existing places (pre-T-158 rows)';

    public function handle(OpenPeriodMaterializer $materializer): int
    {
        $places = 0;
        /** @var list<int> $failed */
        $failed = [];
        /** @var list<int> $repaired */
        $repaired = [];

        // A place that cannot produce rows is cleared in ONE statement rather
        // than one transaction each. `materialize()` on such a place costs a
        // lock, a DELETE and a BEGIN/COMMIT to do nothing, and the great
        // majority of the corpus has no structured hours at all — T-155 shipped
        // them with no backfill, so a place only has periods once it has been
        // re-enriched since.
        //
        // This USED to say "runs inside the deploy's maintenance window, so that
        // time is downtime", and that stopped being true when T-158 scheduled
        // the command nightly: it now also runs at 04:00 against live traffic.
        // The justification has to stand on its own, and it does, but for a
        // different reason. The statement takes row locks on
        // `place_open_periods` ONLY — `USING places` reads the join side without
        // locking it — so it cannot invert lock order against the materializer,
        // which takes `places` first and then rewrites that place's periods. The
        // worst case is a brief wait on the rows of one place, not a deadlock.
        //
        // What keeps it small in steady state: after the first run this deletes
        // only rows whose place has LOST its hours since the last pass. The
        // unbounded shape is a first-run cost.
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
            // Only the key: `materialize()` re-reads the row under its lock
            // anyway (deliberately — see there), so hydrating 37 columns and
            // five jsonb blobs here is a wide-row read per place, discarded one
            // line later. That matters more now than when it was written for the
            // maintenance window: since T-158 this walk also runs nightly beside
            // live traffic, one short transaction per place, which is why it is
            // chunked and why each place's failure is recorded rather than
            // aborting the pass.
            ->select('places.id')
            ->whereRaw($carriesHours)
            ->chunkById(200, function ($chunk) use ($materializer, &$places, &$failed, &$repaired): void {
                foreach ($chunk as $place) {
                    try {
                        if ($materializer->materialize($place)) {
                            $repaired[] = $place->id;
                        }
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

        $this->components->info("Checked open periods for {$places} places.");

        // Say what was REPAIRED, not just what was visited. The nightly run
        // exists because a swallowed observer failure leaves drift that neither
        // surface can show — the place keeps rendering a correct "Open" cue from
        // the jsonb while being absent from every `?open_now=1` listing. A pass
        // that silently fixed that and printed the same sentence either way
        // would hide the broken writer instead of surfacing it, which is the
        // rule `reelmap:offers:reconcile-quotas` already states for the same
        // shape of problem.
        //
        // In a healthy system this is zero every night: the observer keeps the
        // projection in step, so anything found here is a place it dropped.
        if ($repaired !== []) {
            $this->components->warn('Repaired drift on '.count($repaired).' place(s).');

            // Structured, and the ids are BOUNDED — a first run repairs the
            // whole corpus by definition, and a log record carrying 200k ids is
            // one nobody can read and a payload that can fill a disk.
            Log::warning('open_periods.drift_repaired', [
                'places' => count($repaired),
                'sample' => array_slice($repaired, 0, 20),
            ]);
        }

        // `--fail-on-drift` only for the SCHEDULED run, which is the one where a
        // non-zero count means something is wrong. The deploy pass repairs the
        // whole corpus on purpose — it is the migration — and failing there
        // would make every release's backfill red for doing its job.
        if ($this->option('fail-on-drift') && $repaired !== []) {
            return self::FAILURE;
        }

        if ($failed !== []) {
            // Reported AND non-zero exit: a partial run that looks successful is
            // how a place stays unlistable with nobody knowing.
            $this->components->error('Failed on '.count($failed).' place(s): '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
