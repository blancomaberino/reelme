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
    public function __construct(private readonly DishMaterializer $materializer) {}

    public function saved(PlaceSource $source): void
    {
        if (! $this->dishEvidenceMoved($source)) {
            return;
        }

        try {
            $this->materializer->materialize($source);
        } catch (Throwable $e) {
            // Derived data with a rebuild path (`reelmap:dishes:backfill`) must
            // never fail the publish or resolve that produced the snapshot —
            // the same rule PlacePublisher applies to TagMaterializer, and this
            // has strictly less claim on the caller than tags do. The
            // materializer's own nested transaction rolled back to its
            // SAVEPOINT, so the caller's transaction is intact.
            //
            // The structured log is the load-bearing half, because a RETRY
            // CANNOT HEAL THIS: a re-dispatched job re-assigns the same
            // snapshot, jsonb round-trips to an equivalent PHP array,
            // `wasChanged()` is therefore false, and the guard above skips. The
            // log line is the only way anyone learns this source needs the
            // backfill.
            report($e);
            Log::warning('dishes.materialize_failed', ['place_source_id' => $source->id]);
        }
    }

    /**
     * Did the dish evidence actually move? A source is also saved on publish
     * (`published_at`) and on demotion (`is_primary`), and rewriting the rows
     * there would churn ids for nothing.
     *
     * `wasRecentlyCreated` is scoped to the INSERT itself rather than trusted on
     * its own: it stays true for the instance's whole lifetime, so a later
     * `$source->update(['published_at' => now()])` on the same object would
     * otherwise rewrite the rows from the in-memory snapshot — discarding a
     * concurrent update. On an insert there are no changes yet; on an update
     * only a real snapshot change qualifies, and it rewrites from the value that
     * was just persisted.
     */
    private function dishEvidenceMoved(PlaceSource $source): bool
    {
        // `getChanges()`, not `wasChanged()` — the latter returns a BOOL, so
        // `wasChanged() === []` is always false and this branch would never fire
        // on an insert. (It didn't, for one run: every "rows are written" test
        // went red at once, which is the only reason this is a comment and not a
        // shipped bug.)
        $justInserted = $source->wasRecentlyCreated && $source->getChanges() === [];

        return $justInserted || $source->wasChanged('extraction_snapshot_json');
    }
}
