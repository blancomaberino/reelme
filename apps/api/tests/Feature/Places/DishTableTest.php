<?php

use App\Enums\ShareStatus;
use App\Jobs\PublishShare;
use App\Models\Dish;
use App\Models\Place;
use App\Models\PlaceMerge;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Observers\PlaceSourceObserver;
use App\Services\Moderation\ShareModerator;
use App\Services\Places\DishMaterializer;
use App\Services\Places\PlaceMerger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/**
 * T-157 — dishes as first-class rows: the projection (who writes it, and from
 * which of the several snapshot writers), and the `?dish=` filter it exists for.
 */
function sourceWithDishes(array $dishes, ?Place $place = null, bool $published = true): PlaceSource
{
    return PlaceSource::factory()->create([
        'place_id' => ($place ?? Place::factory()->active()->atPoint(-34.90, -56.16)->create())->id,
        'extraction_snapshot_json' => ['dishes' => $dishes],
        // `?dish=` only matches PUBLISHED evidence, and the factory leaves
        // `published_at` null — so a fixture that forgets this would prove the
        // filter works by proving nothing matches.
        'published_at' => $published ? now() : null,
    ]);
}

it('writes a dish row per claimed dish, keyed to the source that claimed it', function () {
    $source = sourceWithDishes([
        ['name' => 'Milanesa napolitana', 'shown_in_video' => true, 'price' => '$450'],
        ['name' => 'Ñoquis', 'shown_in_video' => false],
    ]);

    $rows = Dish::query()->where('place_source_id', $source->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(2);
    expect($rows[0]->only(['name', 'name_normalized', 'price', 'shown_in_video']))->toBe([
        'name' => 'Milanesa napolitana',
        'name_normalized' => 'milanesa napolitana',
        'price' => '$450',
        'shown_in_video' => true,
    ]);
    expect($rows[1]->name_normalized)->toBe('noquis')       // accent-folded
        ->and($rows[1]->price)->toBeNull()
        ->and($rows[1]->shown_in_video)->toBeFalse();
});

it('produces NO rows for a place whose extraction carried no dishes', function () {
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'extraction_snapshot_json' => ['cuisines' => ['italian'], 'dishes' => []],
    ]);
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'extraction_snapshot_json' => ['cuisines' => ['italian']], // key absent entirely
    ]);

    // The control is the whole test: counting to zero from an empty table
    // proves nothing — it passes just as well when the projection is dead.
    // One dish-bearing source in the same table says the writer is alive AND
    // that these two produced nothing.
    $control = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);

    expect(Dish::query()->count())->toBe(1)
        ->and(Dish::query()->sole()->place_source_id)->toBe($control->id);
});

/**
 * The completeness assertion CLAUDE.md's "a new rule needs every writer" asks
 * for: the rule is "a source's dish rows mirror its snapshot", so the test
 * enumerates every way a snapshot comes to exist or change — not just the one
 * path T-157 happened to be written against.
 */
it('keeps the rows in step through EVERY writer of extraction_snapshot_json', function (string $_label, Closure $write) {
    $source = $write();

    expect(Dish::query()->where('place_source_id', $source->id)->pluck('name')->all())
        ->toBe(['Fainá']);
})->with([
    // 1. PlaceResolver::attach() / ResolvePendingPlace — a fresh source.
    'firstOrCreate (resolver)' => ['firstOrCreate (resolver)', fn () => PlaceSource::query()->firstOrCreate(
        ['place_id' => Place::factory()->create()->id, 'share_id' => Share::factory()->create()->id],
        [
            'source_post_id' => SourcePost::factory()->create()->id,
            'extraction_snapshot_json' => ['dishes' => [['name' => 'Fainá', 'shown_in_video' => true]]],
        ],
    )],
    // 2. PublishShare's corrected-snapshot overwrite — an existing source whose
    //    dishes the sharer edited in review. The OLD rows must not survive.
    'corrected overwrite (publish)' => ['corrected overwrite (publish)', function () {
        $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);
        $source->extraction_snapshot_json = ['dishes' => [['name' => 'Fainá', 'shown_in_video' => true]]];
        $source->save();

        return $source;
    }],
    // 3. Mass update through the model (a Filament edit, an admin correction).
    'model update()' => ['model update()', function () {
        $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);
        $source->update(['extraction_snapshot_json' => ['dishes' => [['name' => 'Fainá', 'shown_in_video' => true]]]]);

        return $source;
    }],
]);

/**
 * The set of query-builder writers of `place_sources`, each with a reason.
 *
 * The observer fires on Eloquent events, so ANY query-builder write to that
 * table is a potential bypass — and the previous version of this guard grepped
 * for statements that spelled `extraction_snapshot_json` literally, which missed
 * the one writer that already violated the invariant: `PlaceMerger::unmerge()`
 * passes a whole row array, so the column name never appears in its source text.
 *
 * So the guard asserts over the SET OF WRITERS instead of over one call's
 * spelling. A new query-builder write to this table fails here until someone
 * adds it to this list, which forces the question "does this touch the snapshot,
 * and if so who re-projects the dishes?" to be answered deliberately.
 *
 * @return array<string, string>
 */
function knownPlaceSourceQueryBuilderWriters(): array
{
    return [
        // Merge: rehomes place_id / demotes is_primary. Never touches the
        // snapshot, and dishes key on place_source_id, so they follow the row.
        'app/Services/Places/PlaceMerger.php' => 'merge/unmerge row surgery — unmerge re-materializes explicitly',
        // Un-publish + orphan cleanup. Deletes rows (dishes cascade) or writes
        // published_at; never the snapshot.
        'app/Http/Controllers/Api/V1/MePlacesController.php' => 'removes my sources — deletes cascade to dishes',
    ];
}

it('has no UNKNOWN query-builder writer of place_sources, which would bypass the observer', function () {
    $offenders = [];

    // `database/` as well as `app/`: migrations and seeders write this table too,
    // and an earlier version scanned only app_path(), so a migration could have
    // rewritten every snapshot with the guard silent.
    foreach ([app_path(), base_path('database')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Keyed on the path RELATIVE TO THE REPO, not the basename: keying on
            // the filename meant a brand-new `app/Http/Controllers/Api/V2/
            // MePlacesController.php` inherited the exemption for free.
            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (dishGuardMutatesPlaceSources(stripPhpComments((string) file_get_contents($file->getPathname())))
                && ! array_key_exists($relative, knownPlaceSourceQueryBuilderWriters())) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('the writer detector actually detects — positive and negative controls', function (string $_label, string $code, bool $shouldFlag) {
    expect(dishGuardMutatesPlaceSources(stripPhpComments($code)))->toBe($shouldFlag);
})->with([
    // Every shape a previous version of this guard let through. Without these
    // the detector is never shown to detect, and a typo in the pattern would
    // make the test above pass over a codebase full of bypasses.
    ['single quotes update', "DB::table('place_sources')->update(['extraction_snapshot_json' => \$x]);", true],
    ['double quotes', 'DB::table("place_sources")->update(["extraction_snapshot_json" => $x]);', true],
    ['insertOrIgnore of a row array', "DB::table('place_sources')->insertOrIgnore(\$row);", true],
    ['upsert', "DB::table('place_sources')->upsert(\$rows, ['id']);", true],
    ['aliased table', "DB::table('place_sources as ps')->update(['x' => 1]);", true],
    ['explicit connection', "DB::connection('pgsql')->table('place_sources')->update(['x' => 1]);", true],
    ['eloquent mass update', 'PlaceSource::query()->whereKey($id)->update([$x]);', true],
    ['eloquent toBase update', 'PlaceSource::query()->toBase()->update([$x]);', true],
    ['eloquent toBase insert', 'PlaceSource::query()->toBase()->insert($rows);', true],
    ['eloquent upsert', 'PlaceSource::query()->upsert($rows, ["id"]);', true],
    ['raw delete', "DB::table('place_sources')->where('id', 1)->delete();", true],
    ['DB::update', "DB::update('UPDATE place_sources SET extraction_snapshot_json = ?', [\$j]);", true],
    ['DB::unprepared', "DB::unprepared('UPDATE place_sources SET is_primary = true');", true],
    ['raw INSERT INTO', "DB::statement('INSERT INTO place_sources (id) VALUES (1)');", true],
    ['raw DELETE FROM', "DB::statement('DELETE FROM place_sources WHERE id = 1');", true],
    ['schema-qualified', "DB::statement('UPDATE public.place_sources SET is_primary = true');", true],
    ['quoted identifier', 'DB::statement(\'UPDATE "place_sources" SET is_primary = true\');', true],
    // Event-free Eloquent — the likeliest future mistake, since it looks ordinary.
    ['updateQuietly', "\$s = PlaceSource::find(1); \$s->updateQuietly(['extraction_snapshot_json' => \$j]);", true],
    ['saveQuietly', '$s = PlaceSource::find(1); $s->saveQuietly();', true],
    ['withoutEvents', 'PlaceSource::withoutEvents(fn () => $source->save());', true],
    // …and the same shapes on another model must NOT flag.
    ['updateQuietly elsewhere', "\$user->updateQuietly(['name' => 'x']);", false],
    ['quiet write via the relation', "\$share->placeSources->each->updateQuietly(['extraction_snapshot_json' => \$r]);", true],
    // Must NOT flag: a plain Eloquent delete cascades the dish rows at the DB
    // level, which is exactly what these two production paths rely on.
    ['eloquent delete (cascades)', 'PlaceSource::query()->where("share_id", $id)->delete();', false],
    ['raw statement', "DB::statement('UPDATE place_sources SET extraction_snapshot_json = ?', [\$j]);", true],
    ['a comment describing one', "// DB::table('place_sources')->update(['extraction_snapshot_json' => 1]);", false],
    ['a read, not a write', "DB::table('place_sources')->where('id', 1)->get();", false],
    ['a different table', "DB::table('places')->update(['name' => 'x']);", false],
    ['a model save', '$source->save();', false],
]);

it('replaces rather than appends, so the backfill is idempotent', function () {
    $source = sourceWithDishes([
        ['name' => 'Chivito', 'shown_in_video' => true],
        ['name' => 'Fainá', 'shown_in_video' => false],
    ]);

    expect(Dish::query()->count())->toBe(2);

    // Start from EMPTY, not from the rows the observer already wrote: running
    // the command twice over an already-correct table also passes when the
    // command is a complete no-op. From empty, the first run has to build the
    // rows and the second has to not duplicate them.
    Dish::query()->delete();

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();
    expect(Dish::query()->count())->toBe(2);

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect(Dish::query()->count())->toBe(2)
        ->and(Dish::query()->where('place_source_id', $source->id)->pluck('name')->all())
        ->toBe(['Chivito', 'Fainá']);
});

it('does not re-insert (and poison the caller\'s transaction) on a second no-op save', function () {
    // The regression for a real bug. The observer used to hang off `saved` and
    // ask "was this the insert?" as `wasRecentlyCreated && getChanges() === []`.
    // But `saved` ALSO fires on a save with nothing dirty, where both halves are
    // still true — so a second save took the insert path again, re-inserted the
    // same dishes, and hit `dishes_place_source_id_name_unique`.
    //
    // The row count alone does NOT catch it (the failed insert changes nothing)
    // and neither does an exception (the observer swallows). What catches it is
    // the damage: on Postgres a duplicate key aborts the SURROUNDING
    // transaction, so the next statement dies with 25P02 and the cause is gone.
    Log::spy();

    $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);

    $source->save();   // nothing dirty — `updated` must not fire

    // The projection never failed…
    Log::shouldNotHaveReceived('warning', ['dishes.materialize_failed', Mockery::any()]);
    // …the rows are untouched…
    expect(Dish::query()->where('place_source_id', $source->id)->pluck('name')->all())->toBe(['Chivito']);
    // …and the connection still works, which is what a poisoned transaction breaks.
    expect(PlaceSource::query()->whereKey($source->id)->exists())->toBeTrue();
});

it('leaves the caller\'s transaction usable when materializing REALLY fails', function () {
    // The invariant the observer's `catch` depends on: "whatever we swallow was
    // already rolled back". It holds because DishMaterializer wraps both paths
    // in a transaction, so a failure unwinds to a SAVEPOINT instead of aborting
    // the caller's.
    //
    // The first version of this test threw a RuntimeException inside a bare
    // `DB::transaction`, which tested LARAVEL'S savepoint handling and would
    // have passed identically with no transaction in DishMaterializer at all —
    // a PHP exception does not poison a Postgres transaction; only a database
    // error does. So this drives the real thing: a PlaceSource id with no row
    // behind it makes the real materializer's INSERT violate the foreign key,
    // inside the real savepoint, through the real observer.
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();

    DB::transaction(function () use ($place) {
        $survivor = PlaceSource::factory()->create([
            'place_id' => $place->id,
            'extraction_snapshot_json' => ['dishes' => [['name' => 'Chivito', 'shown_in_video' => true]]],
        ]);

        // A source object whose id exists nowhere: `dishes.place_source_id`'s FK
        // rejects the insert.
        $phantom = new PlaceSource;
        $phantom->id = 999_999;
        $phantom->extraction_snapshot_json = ['dishes' => [['name' => 'Fantasma', 'shown_in_video' => true]]];

        Log::spy();
        Exceptions::fake();

        app(PlaceSourceObserver::class)->created($phantom);

        // It failed for the RIGHT REASON — a real foreign-key violation (23503)
        // from the real INSERT. Asserting only "a warning was logged" would hold
        // for ANY throwable, so `throw new RuntimeException` as the first line of
        // materialize() would keep this green: the same fake-failure defect as
        // the version this replaced, moved out of the test's own `throw` and into
        // an unasserted one.
        Exceptions::assertReported(
            fn (QueryException $e) => $e->getCode() === '23503',
        );
        Log::shouldHaveReceived('warning')
            ->with('dishes.materialize_failed', ['place_source_id' => 999_999])
            ->once();
        expect(Dish::query()->where('place_source_id', 999_999)->count())->toBe(0);

        // …and the CALLER's transaction is still usable, which is the assertion.
        // Against an aborted transaction every one of these raises 25P02.
        expect(PlaceSource::query()->whereKey($survivor->id)->exists())->toBeTrue();
        $place->update(['name' => 'Still writable']);
    });

    expect($place->fresh()->name)->toBe('Still writable')
        ->and(Dish::query()->pluck('name')->all())->toBe(['Chivito']);
});

it('re-reads the snapshot under the lock, so a stale in-memory copy cannot overwrite newer dishes', function () {
    // The regression for a check-then-act race. `materialize()` used to parse
    // the `$source` it was handed BEFORE opening the transaction and taking the
    // lock — so the lock protected the write and left the read unprotected, and
    // a lock in front of a value nobody re-reads is decoration.
    //
    // The real interleaving: `reelmap:dishes:backfill` hydrates 200 sources per
    // chunk; while it works through them a user republishes source #7 with a
    // corrected menu; the backfill reaches #7 holding the OLD snapshot, deletes
    // the corrected rows and reinstates the menu the user just fixed.
    //
    // Simulated deterministically: hydrate, let someone else commit a newer
    // snapshot behind our back, then materialize from the stale object.
    $source = sourceWithDishes([['name' => 'Wrong menu', 'shown_in_video' => true]]);

    // The concurrent writer. `DB::table` on purpose — it fires no observer, so
    // the ONLY thing that can pick this up is the re-read under the lock.
    DB::table('place_sources')->where('id', $source->id)->update([
        'extraction_snapshot_json' => json_encode(['dishes' => [['name' => 'Corrected menu', 'shown_in_video' => true]]]),
    ]);

    // `$source` still holds the OLD snapshot in memory.
    expect($source->extraction_snapshot_json['dishes'][0]['name'])->toBe('Wrong menu');

    $statements = [];
    DB::listen(function ($q) use (&$statements) {
        $statements[] = $q->sql;
    });

    app(DishMaterializer::class)->materialize($source);

    // The committed snapshot wins, not the stale copy in the caller's hand.
    expect(Dish::query()->pluck('name')->all())->toBe(['Corrected menu']);

    // …and the re-read is UNDER THE LOCK, not merely fresher. Asserting only the
    // outcome above leaves the fix half-provable: hoisting the re-read out of
    // the transaction, without `lockForUpdate`, also returns the newer snapshot
    // and stays green — while reopening the race, because two callers would
    // again read before either writes. That is the same "above or below the
    // lock" question this whole finding turned on, so it gets its own assertion.
    $select = collect($statements)->first(fn (string $sql) => str_contains($sql, 'place_sources'));

    expect($select)->toContain('for update');
});

it('materializes through the REAL publish job, not just a model save', function () {
    // Acceptance (a) is "rows written at publish". Every other test drives the
    // model directly, which proves the observer fires but NOT that PublishShare
    // reaches it — swap its save for `updateQuietly()` and all of them stay
    // green while no corrected dish ever materializes.
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing]);
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    PlaceSource::factory()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => ['dishes' => [['name' => 'Wrong dish', 'shown_in_video' => true]]],
    ]);

    // The sharer corrected the extraction in the review sheet.
    $share->corrected_extraction_json = [
        'places' => [['dishes' => [['name' => 'Chivito canadiense', 'shown_in_video' => true]]]],
    ];
    $share->save();

    (new PublishShare($share->id))->handle();

    expect(Dish::query()->pluck('name')->all())->toBe(['Chivito canadiense']);
    expect($this->getJson('/api/v1/places?dish=chivito')->assertOk()->json('data'))->toHaveCount(1);
});

it('clears rows for a source whose snapshot NO LONGER carries dishes', function () {
    // The one case the backfill's bulk DELETE exists for, and the one no other
    // test covers: every other backfill test starts from an empty table, so
    // inverting the statement's `NOT` — or deleting it outright — stays green
    // in all of them. Here the rows exist and the snapshot has moved on.
    $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);
    expect(Dish::query()->where('place_source_id', $source->id)->count())->toBe(1);

    // Bypass the observer, which is the whole point: this simulates the rows
    // having been left behind (a swallowed materialize failure, a pre-T-157
    // edit) rather than the happy path that would have cleaned them up.
    DB::table('place_sources')->where('id', $source->id)
        ->update(['extraction_snapshot_json' => json_encode(['cuisines' => ['italian']])]);

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect(Dish::query()->where('place_source_id', $source->id)->count())->toBe(0);
});

it('survives a snapshot whose `dishes` is not an array, rather than aborting the whole backfill', function () {
    // `jsonb_array_length` RAISES on a non-array. One hand-edited row would have
    // taken down the entire backfill — and because deploy.sh treats the step as
    // non-fatal, the deploy would finish with EVERY place showing an empty menu.
    $good = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);
    $malformed = PlaceSource::factory()->create(['extraction_snapshot_json' => ['dishes' => ['not' => 'a list']]]);

    Dish::query()->delete();

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect(Dish::query()->where('place_source_id', $good->id)->pluck('name')->all())->toBe(['Chivito'])
        ->and(Dish::query()->where('place_source_id', $malformed->id)->count())->toBe(0);
});

it('backfills a source whose rows were never written (a pre-T-157 row)', function () {
    $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);
    // Simulate the pre-migration corpus: the snapshot exists, the rows do not.
    Dish::query()->delete();

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect(Dish::query()->where('place_source_id', $source->id)->pluck('name')->all())->toBe(['Chivito']);
});

it('drops a source\'s dishes with the source', function () {
    $source = sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]]);

    // Prove the row EXISTED. Without this the test passes when the projection
    // never wrote anything at all — 0 before, 0 after — and so tests neither
    // the cascade nor the observer.
    expect(Dish::query()->where('place_source_id', $source->id)->count())->toBe(1);

    $source->delete();

    expect(Dish::query()->count())->toBe(0);
});

it('treats dish text as untrusted model output: capped, deduped, bounded per source', function () {
    $long = str_repeat('a', 200);
    $dishes = [
        ['name' => $long, 'shown_in_video' => false, 'price' => str_repeat('9', 80)],
        ['name' => 'Chivito', 'shown_in_video' => false],
        ['name' => 'Chivito', 'shown_in_video' => true],   // exact dup → first wins
    ];
    // …plus more than the per-source cap allows.
    for ($i = 0; $i < 40; $i++) {
        $dishes[] = ['name' => "Plato {$i}", 'shown_in_video' => false];
    }

    $source = sourceWithDishes($dishes);
    $rows = Dish::query()->where('place_source_id', $source->id)->orderBy('id')->get();

    // LITERALS, not the constants: `mb_strlen($name) === Dish::MAX_NAME` passes
    // for any value of MAX_NAME, including one that drifted away from the
    // column width and the extraction contract.
    expect($rows)->toHaveCount(32)
        ->and(mb_strlen($rows[0]->name))->toBe(120)
        ->and(mb_strlen((string) $rows[0]->price))->toBe(40)
        ->and($rows->where('name', 'Chivito'))->toHaveCount(1)
        ->and($rows->firstWhere('name', 'Chivito')->shown_in_video)->toBeFalse();
});

it('stores markup verbatim and serves it as data, never as markup', function () {
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    sourceWithDishes([['name' => '<script>alert(1)</script>', 'shown_in_video' => false]], $place);

    $res = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    // Stored and returned exactly as the model wrote it — no silent sanitising,
    // which would make the stored value and the shown value disagree…
    expect($res->json('data.dishes.0.name'))->toBe('<script>alert(1)</script>');

    // …and served as DATA: an `application/json` body is parsed by a JSON
    // parser, never by an HTML one, so the bytes are a string and not a tag.
    // (`<` is deliberately not entity-escaped: that is HTML's escaping, and
    // applying it here would corrupt a dish legitimately named "Fish & Chips".)
    $res->assertHeader('content-type', 'application/json');
    expect(json_decode((string) $res->getContent(), true)['data']['dishes'][0]['name'])
        ->toBe('<script>alert(1)</script>');

    // The one place a dish name reaches an HTML renderer is the source embed,
    // which goes through the same JSON boundary.
    $embed = $this->getJson("/api/v1/places/{$place->slug}?include=sources")->assertOk();
    expect($embed->json('data.sources.0.highlights.dishes.0'))->toBe('<script>alert(1)</script>');
});

it('filters places to those serving a matching dish, case- and accent-insensitively', function () {
    $pasta = Place::factory()->active()->atPoint(-34.90, -56.16)->create(['name' => 'Pasta House']);
    sourceWithDishes([['name' => 'Ñoquis del 29', 'shown_in_video' => true]], $pasta);

    $parrilla = Place::factory()->active()->atPoint(-34.91, -56.17)->create(['name' => 'Parrilla']);
    sourceWithDishes([['name' => 'Asado', 'shown_in_video' => true]], $parrilla);

    $dishless = Place::factory()->active()->atPoint(-34.92, -56.18)->create(['name' => 'Dishless']);
    PlaceSource::factory()->create(['place_id' => $dishless->id, 'extraction_snapshot_json' => []]);

    foreach (['ñoquis', 'NOQUIS', 'Ñoquis', 'noqui'] as $needle) {
        $names = collect($this->getJson('/api/v1/places?dish='.urlencode($needle))->assertOk()->json('data'))
            ->pluck('name')->all();

        expect($names)->toBe(['Pasta House'], "?dish={$needle} should match only the place serving it");
    }
});

it('excludes a place with no dishes at all — the filter never degrades to "everything"', function () {
    $with = Place::factory()->active()->atPoint(-34.90, -56.16)->create(['name' => 'With Pasta']);
    sourceWithDishes([['name' => 'Pasta al pesto', 'shown_in_video' => true]], $with);

    foreach (['Nothing Here', 'Also Nothing'] as $name) {
        $place = Place::factory()->active()->atPoint(-34.91, -56.17)->create(['name' => $name]);
        PlaceSource::factory()->create(['place_id' => $place->id, 'extraction_snapshot_json' => ['cuisines' => ['italian']]]);
    }

    // Sanity: unfiltered, all three are visible — so an empty-ish result below
    // is the filter working, not the fixtures being invisible.
    expect($this->getJson('/api/v1/places')->assertOk()->json('data'))->toHaveCount(3);

    $filtered = collect($this->getJson('/api/v1/places?dish=pasta')->assertOk()->json('data'))->pluck('name')->all();
    expect($filtered)->toBe(['With Pasta']);

    // A dish nobody serves returns nothing — NOT the whole index.
    expect($this->getJson('/api/v1/places?dish=sushi')->assertOk()->json('data'))->toBe([]);
});

it('matches nothing for a term that normalizes away, instead of falling through to every place', function () {
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    sourceWithDishes([['name' => 'Pasta', 'shown_in_video' => true]], $place);

    expect($this->getJson('/api/v1/places?dish='.urlencode('!!!'))->assertOk()->json('data'))->toBe([]);
});

it('rejects a one-character dish term rather than matching most of the corpus', function () {
    $this->getJson('/api/v1/places?dish=p')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('filters my places by dish too', function () {
    $user = User::factory()->create();
    $mine = Place::factory()->active()->atPoint(-34.90, -56.16)->create(['name' => 'Mine With Pasta']);
    $other = Place::factory()->active()->atPoint(-34.91, -56.17)->create(['name' => 'Mine Without']);

    foreach ([[$mine, [['name' => 'Pasta al pesto', 'shown_in_video' => true]]], [$other, []]] as [$place, $dishes]) {
        $share = Share::factory()->create([
            'user_id' => $user->id,
            'status' => ShareStatus::Published,
        ]);
        PlaceSource::factory()->create([
            'place_id' => $place->id,
            'share_id' => $share->id,
            'published_at' => now(),
            'extraction_snapshot_json' => ['dishes' => $dishes],
        ]);
    }

    // Unfiltered control: both places are mine and visible, so the filtered
    // result below is the filter working rather than a fixture that never showed.
    expect($this->actingAs($user)->getJson('/api/v1/me/places')->assertOk()->json('data'))->toHaveCount(2);

    $names = collect($this->actingAs($user)->getJson('/api/v1/me/places?dish=pasta')->assertOk()->json('data'))
        ->pluck('name')->all();

    expect($names)->toBe(['Mine With Pasta']);
});

it('refuses a needle too short for the trigram index instead of scanning the corpus', function (string $raw) {
    // The floor is a PERFORMANCE boundary, not a taste one: pg_trgm extracts no
    // trigram from a wildcard-free segment under three characters, so `%ab%`
    // cannot use dishes_name_normalized_trgm and reads every dish we have — on a
    // public, unauthenticated route.
    //
    // Not all of these reach the builder in production — `a.` and `p!` are two
    // raw characters and the FormRequest rejects them first. That is the point
    // of testing the BUILDER directly: it is callable from anywhere, and only
    // `!!a!!` and `-e-` (which clear a raw `min:3` and still reduce below it)
    // prove the request rule is not the thing holding the line.
    $sql = Place::query()->publiclyVisible()->servingDish($raw)->toSql();

    // The needle never reaches a LIKE: the query is short-circuited to a
    // contradiction, so the corpus is not touched at all.
    expect($sql)->toEndWith('and false')
        ->and($sql)->not->toContain('name_normalized');
})->with(['a.', 'p!', '!!a!!', '-e-', 'ñ.', '!!!']);

it('keeps the write-path caps equal to the column widths they must fit', function () {
    // The constants are checked against the extraction CONTRACT in
    // ExtractionSchemaTest; this is the other end of the same mirror — the
    // columns. A constant that drifts above its column is a Postgres rejection
    // mid-publish, which is a user-visible failure produced by a number nobody
    // thought was load-bearing.
    $columns = collect(DB::select(
        "SELECT column_name, character_maximum_length AS len FROM information_schema.columns WHERE table_name = 'dishes'"
    ))->keyBy('column_name');

    expect((int) $columns['name']->len)->toBe(Dish::MAX_NAME)
        ->and((int) $columns['price']->len)->toBe(Dish::MAX_PRICE)
        ->and((int) $columns['name_normalized']->len)->toBe(Dish::MAX_NAME);
});

it('backs the dish match with a trigram index on the column the query actually filters', function () {
    // Deliberately NOT an assertion about the planner's choice. On a test-sized
    // table Postgres correctly prefers a sequential scan, and forcing its hand
    // with `enable_seqscan = off` proves only that the index is *usable* — which
    // is true of any pattern, including a 2-char needle that then rechecks every
    // row. Both shapes are theatre.
    //
    // What is worth pinning is deterministic: the index exists, it is a trigram
    // GIN, and it is on the column `servingDish()` filters. Those are the three
    // facts the docblock's performance claim rests on, and each of them is one
    // careless migration away from being false.
    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE tablename = 'dishes' AND indexname = 'dishes_name_normalized_trgm'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('USING gin')
        ->and($index->indexdef)->toContain('gin_trgm_ops')
        ->and($index->indexdef)->toContain('name_normalized');

    // …and that the filter really does target that column, so renaming the match
    // column without the index would fail here rather than silently degrade.
    expect(Place::query()->publiclyVisible()->servingDish('pasta')->toSql())
        ->toContain('"dishes"."name_normalized"');
});

it('keeps a moderated contribution out of search when its share is taken down', function () {
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    $keep = sourceWithDishes([['name' => 'Asado', 'shown_in_video' => true]], $place);
    $pulled = sourceWithDishes([['name' => 'Milanesa secreta', 'shown_in_video' => true]], $place);

    // Both searchable while both are live — so the assertion below is about the
    // take-down, not about the fixture never having matched.
    expect($this->getJson('/api/v1/places?dish=milanesa')->json('data'))->toHaveCount(1);

    app(ShareModerator::class)->takeDown($pulled->share, 'dmca');

    // The place survives (another share still evidences it) but the pulled
    // contribution stops steering discovery. A DMCA removal that leaves the text
    // findable corpus-wide has not removed anything.
    expect($this->getJson('/api/v1/places?dish=milanesa')->json('data'))->toBe([])
        ->and(collect($this->getJson('/api/v1/places?dish=asado')->json('data'))->pluck('id')->all())
        ->toBe([(string) $keep->place_id]);
});

it('ignores an unpublished source even when its share is perfectly healthy', function () {
    // Separates "unpublished" from "taken down". The take-down test alone is
    // also satisfied by a gate keyed on `shares.status`, because takeDown()
    // rejects the share AND nulls published_at. This one has a live share and a
    // source that simply has not published yet — the resolver's pre-publish
    // state — so only a `published_at` gate passes it.
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    sourceWithDishes([['name' => 'Borrador', 'shown_in_video' => true]], $place, published: false);

    expect(Dish::query()->count())->toBe(1)                       // the rows exist…
        ->and($this->getJson('/api/v1/places?dish=borrador')->assertOk()->json('data'))
        ->toBe([]);                                               // …and are not discoverable
});

it('restores a dropped duplicate source WITH its dishes when a merge is undone', function () {
    // The gap this covers: merge() hard-deletes a dropped duplicate (its dishes
    // cascade away) and unmerge() re-inserts the row through the query builder,
    // which fires no model events. Without an explicit re-projection the source
    // comes back carrying a snapshot full of dishes and zero dish rows —
    // permanently, and invisibly, because both tables stay internally consistent.
    $winner = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    $loser = Place::factory()->active()->atPoint(-34.91, -56.17)->create();

    $share = Share::factory()->create(['status' => ShareStatus::Published]);
    // The same share on both places is what makes the loser's source a "dropped
    // duplicate" on merge (unique(place_id, share_id) survives; unique(share_id)
    // was dropped by the multi-place migration, so this is reachable).
    PlaceSource::factory()->create([
        'place_id' => $winner->id, 'share_id' => $share->id, 'published_at' => now(),
        'extraction_snapshot_json' => ['dishes' => [['name' => 'Chivito', 'shown_in_video' => true]]],
    ]);
    $dropped = PlaceSource::factory()->create([
        'place_id' => $loser->id, 'share_id' => $share->id, 'published_at' => now(),
        'extraction_snapshot_json' => ['dishes' => [['name' => 'Fainá', 'shown_in_video' => true]]],
    ]);

    expect(Dish::query()->where('place_source_id', $dropped->id)->count())->toBe(1);

    $merger = app(PlaceMerger::class);
    $merger->merge($winner, $loser);
    $merge = PlaceMerge::query()->latest('id')->sole();
    $merger->unmerge($merge);

    expect(PlaceSource::query()->whereKey($dropped->id)->exists())->toBeTrue()
        ->and(Dish::query()->where('place_source_id', $dropped->id)->pluck('name')->all())
        ->toBe(['Fainá']);
});

it('shows no dishes for a pre-T-157 source until the backfill runs, then shows them', function () {
    $place = Place::factory()->active()->atPoint(-34.90, -56.16)->create();
    sourceWithDishes([['name' => 'Chivito', 'shown_in_video' => true]], $place);

    // The corpus as it exists the moment the table is created: snapshots, no rows.
    Dish::query()->delete();

    // This is the regression the migration's inline backfill exists to prevent —
    // asserted at the RESPONSE, because "the table is empty" is not the problem,
    // "every place detail lost its menu" is.
    expect($this->getJson("/api/v1/places/{$place->slug}")->json('data.dishes'))->toBe([]);

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect($this->getJson("/api/v1/places/{$place->slug}")->json('data.dishes.0.name'))->toBe('Chivito');
});

it('filters the map by dish too, rather than silently ignoring the parameter', function () {
    $pasta = Place::factory()->active()->atPoint(-34.9011, -56.1645)->create(['name' => 'Pasta Map']);
    sourceWithDishes([['name' => 'Pasta al pesto', 'shown_in_video' => true]], $pasta);
    $other = Place::factory()->active()->atPoint(-34.9012, -56.1646)->create(['name' => 'Parrilla Map']);
    sourceWithDishes([['name' => 'Asado', 'shown_in_video' => true]], $other);

    $bbox = 'bbox=-56.20,-34.95,-56.10,-34.85&zoom=15';

    // Unfiltered control first: a filter that returns one pin is only meaningful
    // if two were reachable.
    expect($this->getJson("/api/v1/map/places?{$bbox}")->assertOk()->json('data.pins'))->toHaveCount(2);

    $pins = $this->getJson("/api/v1/map/places?{$bbox}&dish=pasta")->assertOk()->json('data.pins');

    expect(collect($pins)->pluck('name')->all())->toBe(['Pasta Map']);
});

/**
 * Source text with comments and docblocks removed — the prose in this repo
 * quotes the forbidden calls when explaining them, and a guard that matched
 * those would flag its own documentation.
 */
function stripPhpComments(string $code): string
{
    // `token_get_all` treats anything before an opening tag as inline HTML, so a
    // bare code fragment (what the control fixtures pass) would come back
    // untouched — comments included — and the negative controls would flag.
    $prefixed = str_contains($code, '<?php') ? $code : "<?php\n".$code;

    $out = '';
    foreach (@token_get_all($prefixed) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
}

/**
 * Does this (comment-stripped) source mutate `place_sources` outside Eloquent
 * model events?
 *
 * Split on `;` first so each check only has to look inside ONE statement.
 *
 * Note the asymmetry, which is deliberate rather than an accident of which
 * pattern fires: a RAW builder write (or an Eloquent query dropped to the base
 * builder) counts for any verb, because it bypasses events entirely; a plain
 * Eloquent query counts only for `update`/`upsert`, because its `delete()` still
 * cascades the dish rows correctly at the database level — which is what
 * `ForceReprocessShare` and `ProcessTakedown` rely on, and neither should flag.
 *
 * The `*Quietly` / `withoutEvents` family is caught too. Those are the likeliest
 * future mistake precisely because they look like ordinary Eloquent.
 */
function dishGuardMutatesPlaceSources(string $code): bool
{
    $table = '/DB::(?:connection\([^)]*\)->)?table\(\s*["\']place_sources(?:\s+as\s+\w+)?["\']\s*\)/';

    // Event-free Eloquent is only interesting where a PlaceSource is in play.
    // Matching `*Quietly` on ANY model flagged User, AccountDeletion and a
    // factory — noise that would have taught the next reader to widen the
    // allowlist instead of reading the finding.
    // Case-INSENSITIVE, and matching the relation spelling too: a redaction path
    // written as `$share->placeSources->each->updateQuietly([...])` writes the
    // snapshot, fires no observer, and never spells `PlaceSource`.
    $touchesPlaceSource = (bool) preg_match('/place_?sources?/i', $code);

    foreach (explode(';', $code) as $statement) {
        if ($touchesPlaceSource
            && (preg_match('/->\s*(?:updateQuietly|saveQuietly)\s*\(/', $statement)
                || preg_match('/PlaceSource::withoutEvents\s*\(/', $statement))) {
            return true;
        }

        $eloquent = str_contains($statement, 'PlaceSource::query()');
        $raw = (bool) preg_match($table, $statement)
            || ($eloquent && str_contains($statement, '->toBase()'));

        $verbs = $raw ? 'update|insert\w*|upsert|delete' : 'update|upsert';

        if (($raw || $eloquent) && preg_match('/->\s*(?:'.$verbs.')\s*\(/', $statement)) {
            return true;
        }

        // Raw SQL: `statement`, `update`, `unprepared`, an optional schema
        // qualifier, and an optionally-quoted identifier. An earlier version
        // matched only DB::statement on a bare unquoted name.
        if (preg_match(
            '/DB::(?:statement|update|unprepared)\(\s*["\'][^"\']*\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(?:\w+\.)?"?place_sources"?\b/i',
            $statement,
        )) {
            return true;
        }
    }

    return false;
}
