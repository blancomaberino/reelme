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
 * Column order matters and DESC does not: a btree is scanned backwards for
 * `ORDER BY … DESC` at no cost, and `(created_at, id)` is exactly the tuple the
 * cursor compares. Both columns are immutable after insert, so the index is paid
 * for on INSERT only — enrichment, merges and admin edits never touch it.
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
