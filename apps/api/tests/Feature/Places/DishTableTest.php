<?php

use App\Enums\ShareStatus;
use App\Models\Dish;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * T-157 — dishes as first-class rows: the projection (who writes it, and from
 * which of the several snapshot writers), and the `?dish=` filter it exists for.
 */
function sourceWithDishes(array $dishes, ?Place $place = null): PlaceSource
{
    return PlaceSource::factory()->create([
        'place_id' => ($place ?? Place::factory()->active()->atPoint(-34.90, -56.16)->create())->id,
        'extraction_snapshot_json' => ['dishes' => $dishes],
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

    expect(Dish::query()->count())->toBe(0);
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

it('has no query-builder write to the snapshot column, which would bypass the model hook', function () {
    // The hook fires on Eloquent events only. A `DB::table('place_sources')
    // ->update(['extraction_snapshot_json' => …])` anywhere in the app would
    // leave the dish rows describing a snapshot that no longer exists — and no
    // behavioural test could see it, because the rows would still be *a* valid
    // projection of *an* older truth. So the guard is structural.
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }
        // Comments are stripped first: the concern that documents this rule
        // spells the forbidden call out in its own docblock.
        $code = '';
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        if (preg_match("/DB::table\(\s*'place_sources'\s*\)[^;]*extraction_snapshot_json/s", $code)) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('replaces rather than appends, so the backfill is idempotent', function () {
    $source = sourceWithDishes([
        ['name' => 'Chivito', 'shown_in_video' => true],
        ['name' => 'Fainá', 'shown_in_video' => false],
    ]);

    $before = Dish::query()->count();
    expect($before)->toBe(2);

    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();
    $this->artisan('reelmap:dishes:backfill')->assertSuccessful();

    expect(Dish::query()->count())->toBe($before)
        ->and(Dish::query()->where('place_source_id', $source->id)->pluck('name')->all())
        ->toBe(['Chivito', 'Fainá']);
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

    expect($rows)->toHaveCount(32)                                   // MAX_DISHES_PER_SOURCE
        ->and(mb_strlen($rows[0]->name))->toBe(Dish::MAX_NAME)
        ->and(mb_strlen((string) $rows[0]->price))->toBe(Dish::MAX_PRICE)
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

    $names = collect($this->actingAs($user)->getJson('/api/v1/me/places?dish=pasta')->assertOk()->json('data'))
        ->pluck('name')->all();

    expect($names)->toBe(['Mine With Pasta']);
});

it('rides the trigram index rather than scanning every dish', function () {
    sourceWithDishes([['name' => 'Pasta al pesto', 'shown_in_video' => true]]);

    $plan = collect(DB::select(
        "EXPLAIN SELECT 1 FROM dishes WHERE name_normalized LIKE '%pasta%'"
    ))->pluck('QUERY PLAN')->implode(' ');

    // Postgres will still prefer a seq scan on a table this small, so force its
    // hand: what matters is that the index is USABLE for this predicate — an
    // unusable one is silently a full scan on a corpus of every dish we know.
    DB::statement('SET LOCAL enable_seqscan = off');
    $forced = collect(DB::select(
        "EXPLAIN SELECT 1 FROM dishes WHERE name_normalized LIKE '%pasta%'"
    ))->pluck('QUERY PLAN')->implode(' ');

    expect($forced)->toContain('dishes_name_normalized_trgm');
    // The unforced plan is only reported for context — on a table this small
    // Postgres is right to prefer a sequential scan.
    expect($plan)->toBeString();
});
