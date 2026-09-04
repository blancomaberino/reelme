<?php

namespace App\Observers;

use App\Models\PlaceSource;
use App\Services\Places\DishMaterializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps a source's {@see App\Models\Dish} rows in step with its extraction
 * snapshot (T-157).
 *
 * THIS is the rule's home, deliberately. A dish row is derived state, and the
 * state it derives from is one column on one table — so the rule belongs at the
 * one place that column changes, not at the publish seam where the sibling
 * {@see App\Services\Places\TagMaterializer} is called. Hooking publish would
 * have covered the path being written the day the feature landed and missed the
 * other two that already write that column
 * ({@see App\Services\Places\PlaceResolver::attach()} and
 * {@see App\Services\Places\ResolvePendingPlace}) — the failure CLAUDE.md's "a
 * new rule needs every writer" describes, which passes its own test and is
 * invisible to a diff-scoped review.
 *
 * An observer rather than a trait on the model: this issues cross-table DML and
 * resolves a service out of the container, and neither belongs in `App\Models`.
 * (It is NOT the same shape as `DerivesNameColumns`, which only assigns two
 * attributes on the row being saved and cannot fail.)
 *
 * WHAT STILL GETS PAST IT: a query-builder write to `extraction_snapshot_json`,
 * which fires no model events. `PlaceMerger` does use query-builder writes on
 * `place_sources` — `place_id` and `is_primary` on merge, and a full-row
 * `insertOrIgnore` on unmerge — and only the last one carries the snapshot, so
 * `PlaceMerger::unmerge()` re-materializes explicitly and says so. The
 * "no unguarded query-builder write to the snapshot" test in
 * `tests/Feature/Places/DishTableTest.php` fails if a new one appears.
 */
class PlaceSourceObserver
{
    /**
     * The INSERT. Fires exactly once, on the row's creation — which is why this
     * is `created` and not `saved` plus a "was this the insert?" heuristic.
     *
     * That heuristic was here, and it was wrong: `saved` ALSO fires on a save
     * with nothing dirty, where `wasRecentlyCreated` is still true and
     * `getChanges()` is still empty — so a second `$source->save()` on the same
     * instance took the insert path again, re-inserted the same dishes, and hit
     * `dishes_place_source_id_name_unique`. On Postgres that aborts the
     * SURROUNDING transaction (25P02), so the catch below swallowed the cause
     * while every later statement in the caller's transaction failed.
     */
    public function created(PlaceSource $source): void
    {
        // Nothing to replace and nothing to race: a fresh bigserial id cannot
        // already own dish rows, and no other session can reference it yet. So
        // this path skips the row lock and the DELETE.
        $this->project($source, isInsert: true);
    }

    /**
     * A snapshot that CHANGED. `updated` only fires when something was actually
     * dirty, so a no-op save cannot reach here either.
     *
     * A source is also updated on publish (`published_at`) and on demotion
     * (`is_primary`); rewriting the rows for those would churn ids for nothing.
     */
    public function updated(PlaceSource $source): void
    {
        if ($source->wasChanged('extraction_snapshot_json')) {
            $this->project($source);
        }
    }

    private function project(PlaceSource $source, bool $isInsert = false): void
    {
        try {
            // Resolved HERE rather than constructor-injected: Laravel re-resolves
            // an observer on every event dispatch (there is no listener instance
            // cache), so autowiring the dependency would build a DishMaterializer
            // on every PlaceSource save and discard it at the guard above.
            app(DishMaterializer::class)->materialize($source, $isInsert);
        } catch (Throwable $e) {
            // Derived data with a rebuild path (`reelmap:dishes:backfill`) must
            // never fail the publish or resolve that produced the snapshot —
            // the same rule PlacePublisher applies to TagMaterializer, and this
            // has strictly less claim on the caller than tags do.
            //
            // Safe to swallow only because the materializer wraps BOTH paths in
            // its own transaction: the failure rolls back to a SAVEPOINT and the
            // caller's transaction survives. Without that, catching here would
            // hide an error that had already poisoned the outer transaction —
            // which is exactly what the previous version did.
            //
            // The structured log is the load-bearing half, and the reason is
            // narrower than an earlier version of this comment claimed. It said
            // a retry CANNOT heal this. Measured, it usually can — but only by
            // accident: jsonb stores object keys in its own order, so a
            // re-dispatched job re-assigning the same PHP array compares
            // UNEQUAL to the value read back, the model is dirty, and
            // `updated()` fires. A snapshot whose key order already matches
            // jsonb's does not, and neither does a retry that re-reads rather
            // than re-assigns. So: sometimes self-healing, never reliably, and
            // this log line is the only thing that says which happened.
            report($e);
            Log::warning('dishes.materialize_failed', ['place_source_id' => $source->id]);
        }
    }
}
