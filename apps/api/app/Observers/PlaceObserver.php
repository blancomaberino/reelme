<?php

namespace App\Observers;

use App\Models\Place;
use App\Services\Places\OpenPeriodMaterializer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a place's {@see App\Models\PlaceOpenPeriod} rows in step with its
 * structured hours (T-158).
 *
 * THIS is the rule's home, for the reason CLAUDE.md's "a new rule needs every
 * writer" gives and {@see PlaceSourceObserver} demonstrates: the rows derive
 * from two columns on one table, so the rule belongs where those columns
 * change, not at whichever service was open in the editor. `opening_hours_periods_json`
 * and `timezone` are both `fillable`, and they are written by
 * {@see App\Services\Places\Enrichment\BusinessEnricher} (via
 * {@see App\Services\Geo\BusinessDetails}), by
 * {@see App\Services\Places\PlaceEditor::apply()}, by Filament, and by
 * factories. `PlaceMerger` now donates them too — see below. All of those go through Eloquent, so all of them are covered
 * here by construction.
 *
 * A NOTE ON `PlaceMerger`, because an earlier version of this docblock got it
 * wrong and CLAUDE.md rule 5 is about exactly that: it said the merger donated a
 * loser's hours. It did not — `BACKFILL_FIELDS` carried `opening_hours_json`
 * (the display LINES) and neither structured column. That was survivable while
 * the columns only drove a cue; T-158 makes it cost discoverability, because a
 * survivor that inherits a loser's hours lines but not its periods shows
 * opening hours and can never be returned by `?open_now=1` — and the survivor
 * is the more popular record by construction. Both columns are now in
 * `BACKFILL_FIELDS`, and `backfill()` assigns then `save()`s, so this observer
 * covers that path like any other.
 *
 * WHAT STILL GETS PAST IT: a query-builder write to either column, or an
 * Eloquent MASS update (`Place::whereKey(...)->update([...])`), neither of which
 * fires model events. The "no unguarded query-builder write to the hours columns"
 * test in `tests/Feature/Places/OpenPeriodTest.php` fails if one appears.
 */
class PlaceObserver
{
    /**
     * The INSERT. `created` rather than `saved` plus a "was this the insert?"
     * heuristic — `saved` also fires on a save with nothing dirty, where
     * `wasRecentlyCreated` is still true, so the insert path would run twice and
     * the second run would hit the unique index. On Postgres that aborts the
     * SURROUNDING transaction (25P02) and the catch below would swallow the
     * cause while every later statement failed.
     */
    public function created(Place $place): void
    {
        $this->project($place, isInsert: true);
    }

    /**
     * Hours or zone that CHANGED. `updated` only fires when something was
     * actually dirty, so a no-op save cannot reach here.
     *
     * BOTH columns are watched, and the second one is the easy half to forget: a
     * place enriched with periods before {@see App\Services\Places\Enrichment\Sources\TimezoneBusinessSource}
     * has resolved its zone has no rows, and the write that gives it one is a
     * `timezone`-only update. Watching the periods alone would leave that place
     * permanently unlistable, with a correct cue on its detail screen — the two
     * disagreeing for the one reason nobody would look for.
     */
    public function updated(Place $place): void
    {
        if ($place->wasChanged(['opening_hours_periods_json', 'timezone'])) {
            $this->project($place);
        }
    }

    private function project(Place $place, bool $isInsert = false): void
    {
        $depth = DB::transactionLevel();

        try {
            // Resolved HERE rather than constructor-injected: Laravel re-resolves
            // an observer on every event dispatch, so autowiring would build a
            // materializer on every Place save and discard it at the guard above.
            app(OpenPeriodMaterializer::class)->materialize($place, $isInsert);
        } catch (Throwable $e) {
            // Derived data with a rebuild path (`reelmap:open-periods:backfill`,
            // nightly) must never fail the enrichment or edit that produced the
            // hours. The materializer wraps both paths in its own transaction, so
            // an ordinary failure rolls back to a SAVEPOINT and the caller's
            // transaction survives — that is what makes swallowing safe.
            //
            // It is not safe for every failure, and this guard is the difference.
            // `ManagesTransactions::handleTransactionException()` has an early
            // branch for a concurrency error inside a nested transaction that
            // rethrows WITHOUT issuing `ROLLBACK TO SAVEPOINT`, so the caller's
            // Postgres transaction stays aborted and its next statement dies with
            // a 25P02 whose real cause is only in a log line.
            //
            // ASK THE CONNECTION, do not infer. The first version of this guard
            // compared transaction depth, which review showed cannot fire on the
            // very path it named: that branch does `$this->transactions--` before
            // it throws, so the depth is already back where it started. A cheap
            // statement is the actual invariant — "can the caller still use this
            // transaction?" — and it stays true whatever the framework does to
            // its counter or to which errors it calls concurrency errors.
            if (! $this->callerCanContinue($place, $depth)) {
                throw $e;
            }

            // The log line is the load-bearing half: a retry only heals this by
            // accident, when the next write happens to touch the same columns.
            // The nightly backfill is what actually closes the gap.
            report($e);
            Log::warning('open_periods.materialize_failed', ['place_id' => $place->id]);
        }
    }

    /**
     * Whether the caller's transaction is still usable after a failed projection.
     *
     * At depth 0 there is nothing to poison, so the question does not arise. Any
     * deeper, a trivial statement answers it: Postgres refuses everything but
     * ROLLBACK once a transaction is aborted, so this either returns or tells us
     * the failure was not ours to swallow.
     */
    private function callerCanContinue(Place $place, int $depth): bool
    {
        // A depth that did not come back is its own answer, and the probe cannot
        // see it: a materializer that leaked an open transaction leaves the
        // connection perfectly able to run `select 1` while the caller is now
        // committing inside a frame it did not open.
        if (DB::transactionLevel() !== $depth) {
            return false;
        }

        if ($depth === 0) {
            return true;
        }

        try {
            // The PLACE's connection, not the default one: the materializer
            // transacted on that, and probing a different connection would come
            // back healthy while the poisoned one is the caller's.
            $place->getConnection()->select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
