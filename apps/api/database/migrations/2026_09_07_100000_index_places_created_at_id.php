<?php

use App\Filament\Resources\Places\Tables\PlacesTable;
use App\Http\Controllers\Api\V1\PlaceController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the default listing sort has always needed, and now cannot do
 * without (T-158).
 *
 * `GET /places` orders by `created_at DESC, id DESC` and pages with the matching
 * keyset predicate `(created_at, id) < (?, ?)`
 * ({@see PlaceController::applySort()}). With no
 * index on those columns, Postgres has to materialize and sort the ENTIRE
 * filtered set before the LIMIT can apply — the cost is the size of what the
 * filters left behind, not the size of the page.
 *
 * That was survivable while the filters were cheap. `?open_now=1` is not: it is
 * a correlated EXISTS evaluated per surviving row, so "sort everything, then
 * take 20" means probing every row's opening periods first. Requiring `near`
 * bounds the set geographically, but only nominally for this corpus — a 50km
 * radius (the request's ceiling) around any point in Montevideo encloses
 * essentially all of it, and at that selectivity the planner abandons the GiST
 * bound for a sequential scan anyway.
 *
 * With this index the plan CAN walk rows in sort order and stop at the LIMIT,
 * which is the one bound that does not depend on how much the filters happened
 * to remove. "Can", not "does": whether the planner takes that path depends on
 * how selective `?open_now=1` turns out to be over a real corpus, and the dev
 * database has twenty rows, so nothing here has been measured — it is reasoned.
 * The claim that is safe either way is that the worst case is unchanged: without
 * the index the sort was mandatory, with it the sort is optional.
 *
 * SCOPE, added after review corrected the paragraph above: all of that is about
 * `sort=recent`, the DEFAULT sort — and Tonight, the feature that introduced
 * `?open_now=1`, does not use it. `useTonight` always sends `sort=distance`,
 * which orders by `ST_Distance(...)` and not the KNN `<->` operator, so no btree
 * is reachable from that plan and the filtered set is sorted in full on every
 * page. This index bounds the default listing and the Filament table; the
 * distance path is bounded by `near` + `radius_m` alone. Both statements are
 * needed, and only the first one was here.
 *
 * AND `<->` IS NOT THE DROP-IN THAT PARAGRAPH IMPLIES. A second review measured
 * it on a 200k-row copy, because the sentence above was going to be the next
 * reader's starting point:
 *
 *  - `ORDER BY location <-> point` alone does get a GiST index scan (3.5 ms vs
 *    5499 ms for the current plan). Add `, id` — the tiebreaker at
 *    `PlaceController::applySort()`, which is not optional, since a keyset needs
 *    a total order — and the planner CHOSE a full sort on the 200k-row copy this
 *    was measured on. Stated as a measurement rather than a law on purpose —
 *    but the obvious escape does NOT appear to exist here, which was itself
 *    re-measured: on PG 16 no incremental-sort path over the KNN scan is
 *    generated at all, not even with `enable_seqscan=off` and `enable_sort=off`
 *    together, while a btree control on the same database does produce one. So
 *    the path is absent rather than out-costed. Re-measure on the version you
 *    are on before concluding it is either shut or open.
 *  - The keyset predicate cannot be an index condition either, so page 2 onward
 *    is a sequential scan whatever page 1 does.
 *  - `<->` on geography is a SPHERE distance; `ST_Distance(geog, geog)` is
 *    SPHEROID. Measured 0.23% apart on real rows. Ordering by one while the
 *    cursor and the response's `distance_m` carry the other is a keyset
 *    mismatch — duplicated and skipped rows at page boundaries — and converting
 *    all three changes the metres the API returns.
 *
 * So the follow-up is real but it is a task, not an edit: roughly a 5x win on
 * page one, against a distance definition change and a cursor migration. Left
 * out of T-158 deliberately, with the numbers here so the next person starts
 * from them rather than from the optimism of the paragraph above.
 *
 * Column order matters and DESC does not: a btree is scanned backwards for
 * `ORDER BY … DESC` at no cost, and `(created_at, id)` is exactly the tuple the
 * cursor compares. Both columns are immutable after insert, so no UPDATE ever
 * changes what this index STORES — but "paid for on INSERT only", as this said
 * before review, is wrong: a non-HOT update writes a new tuple into every index
 * on the table whatever it changed, and `places` is updated often enough by
 * enrichment to make that the common case. The honest claim is narrower and
 * still enough: one more btree on a table that already carries nine.
 *
 * LOCKING, which this docblock did not mention at all. `Schema::table()->index()`
 * emits a plain `CREATE INDEX`, taking a SHARE lock that conflicts with the ROW
 * EXCLUSIVE every writer holds. `scripts/deploy.sh` puts the app in maintenance
 * mode before migrating, so no HTTP writer is live — but it terminates Horizon
 * AFTER `migrate`, and says so in its own comments, so enrichment jobs from the
 * previous release ARE still writing `places` while this builds. Milliseconds at
 * today's ~20 rows; a deploy stall at some corpus size nobody will predict.
 *
 * Deliberately NOT `CREATE INDEX CONCURRENTLY`: that requires
 * `$withinTransaction = false` and gives up the property that `down()` is a true
 * reverse — a failed concurrent build leaves an INVALID index behind for someone
 * to find by hand. Moving `horizon:terminate` ahead of `migrate` is the other
 * fix, and `deploy.sh` rejects it for a separate good reason (replacement
 * workers would boot against the new schema). So the lock is accepted, and the
 * point of this paragraph is that it is accepted rather than unnoticed.
 *
 * NOT a partial index over `publiclyVisible()`, tempting as that is: Filament's
 * places table sorts by `created_at` over EVERY place including hidden, removed
 * and merged ones ({@see PlacesTable}),
 * so a partial index would quietly stop serving the admin and send it back to a
 * full sort.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'places_created_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table): void {
            $table->dropIndex('places_created_at_id_index');
        });
    }
};
