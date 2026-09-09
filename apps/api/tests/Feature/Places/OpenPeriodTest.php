<?php

use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceOpenPeriod;
use App\Models\User;
use App\Services\Places\OpenPeriodMaterializer;
use App\Services\Places\PlaceEditor;
use App\Support\OpeningSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;

uses(RefreshDatabase::class);

/**
 * T-158 — "open now" as a WHERE clause: the `place_open_periods` projection
 * (who writes it, and from which of the several hours writers) and the
 * `openNow()` filter it exists for.
 */
const MONTEVIDEO = 'America/Montevideo';

/** A stored period in the shape {@see OpeningSchedule::fromProvider()} produces. */
function period(int $openDay, string $openTime, ?int $closeDay, ?string $closeTime): array
{
    return ['open_day' => $openDay, 'open_time' => $openTime, 'close_day' => $closeDay, 'close_time' => $closeTime];
}

/**
 * The place ids in a response payload, as integers.
 *
 * One home for the cast, because the cast is where this file got it wrong: the
 * four copies of this chain all read `->map(intval(...))`, and `Collection::map`
 * passes ($value, $key) into `intval`'s ($value, $base) — so element 0 was
 * decoded in base 0 (auto, right by accident) and element 1 in base 1, which is
 * invalid and yields 0. Every assertion using it either had a single row or only
 * checked membership of the first, so it survived four review rounds; the first
 * test to assert a full ORDER went red immediately.
 *
 * The map answers under `data.pins`, the listings under `data`. Ids serialize as
 * STRINGS, so they are cast rather than compared loosely — a loose comparison
 * would pass while the filter did nothing.
 */
function idsFrom(array $json, string $surface = 'index'): array
{
    return collect(data_get($json, $surface === 'map' ? 'data.pins' : 'data'))
        ->pluck('id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

function placeWithHours(
    ?array $periods,
    ?string $timezone = MONTEVIDEO,
    // Defaults to the one point every other test in this file uses, so the
    // distance between fixtures is zero and orderings are decided elsewhere.
    // Only the `sort=distance` test needs them to differ.
    float $lat = -34.90,
    float $lng = -56.16,
): Place {
    return Place::factory()->active()->atPoint($lat, $lng)->create([
        'opening_hours_periods_json' => $periods,
        'timezone' => $timezone,
    ]);
}

// ---------------------------------------------------------------------------
// The writers. Not "the setter I happened to edit" — every way the hours change.
// ---------------------------------------------------------------------------

it('materializes on every way the hours can change', function (string $writer) {
    $place = placeWithHours(null, null);
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);

    $hours = ['opening_hours_periods_json' => [period(1, '11:00', 1, '23:00')], 'timezone' => MONTEVIDEO];

    match ($writer) {
        // The enrichment path: BusinessDetails::toArray() is fed to update().
        'update()' => $place->update($hours),
        // The curated write chokepoint the admin surface goes through.
        'PlaceEditor::apply()' => app(PlaceEditor::class)->apply($place, $hours, origin: 'manual'),
        // fill() + save(), which is what Filament does.
        'fill() + save()' => $place->fill($hours)->save(),
        // Direct assignment — how PlaceMerger donates a loser's fields.
        'attribute assignment' => (function () use ($place, $hours) {
            $place->opening_hours_periods_json = $hours['opening_hours_periods_json'];
            $place->timezone = $hours['timezone'];
            $place->save();
        })(),
    };

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->get())->toHaveCount(1)
        ->and(PlaceOpenPeriod::query()->where('place_id', $place->id)->first())
        ->open_minute->toBe(1 * 1440 + 11 * 60)      // Monday 11:00
        ->close_minute->toBe(1 * 1440 + 23 * 60)     // Monday 23:00
        ->timezone->toBe(MONTEVIDEO);
})->with(['update()', 'PlaceEditor::apply()', 'fill() + save()', 'attribute assignment']);

it('materializes on the INSERT, not only on later updates', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);
});

it('materializes when only the TIMEZONE arrives, which is the half that is easy to forget', function () {
    // A place enriched with periods BEFORE TimezoneBusinessSource resolved its
    // zone has no rows: the zone is what places the week. The write that gives
    // it one touches `timezone` and nothing else, so an observer watching only
    // the periods column would leave this place permanently unlistable while its
    // detail screen showed a perfectly correct cue.
    $place = placeWithHours([period(1, '11:00', 1, '23:00')], timezone: null);
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);

    $place->update(['timezone' => MONTEVIDEO]);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);
});

it('clears the rows when the hours are taken away', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);

    $place->update(['opening_hours_periods_json' => null]);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);
});

it('drops the rows with the place', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);

    $place->delete();

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Both halves required — the T-128/T-155 rule, as a property of the data.
// ---------------------------------------------------------------------------

it('writes NO rows when either half of the hours is unusable', function (?array $periods, ?string $timezone) {
    $place = placeWithHours($periods, $timezone);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);
})->with([
    'no periods' => [null, MONTEVIDEO],
    'empty periods' => [[], MONTEVIDEO],
    'no zone' => [[['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']], null],
    // A fixed offset is wrong for half the year wherever DST applies, and
    // Postgres would accept it — PHP is what refuses it.
    'a fixed offset, not a zone' => [[['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']], '-03:00'],
    'an abbreviation, not a zone' => [[['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']], 'EST'],
    'junk in the zone column' => [[['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']], 'Not/AZone'],
    'malformed periods' => [[['open_day' => 9, 'open_time' => 'noon']], MONTEVIDEO],
]);

it('writes no rows for a zone PHP knows but POSTGRES does not, which would 500 the listing', function () {
    // The two tz databases are updated independently, so "PHP accepted it" is
    // not the same claim as "Postgres can resolve it" — at the time of writing
    // PHP knows America/Coyhaique (tzdata 2025a) and this Postgres does not.
    // `AT TIME ZONE` THROWS on an id it does not know, and the query that throws
    // is a public listing, so one such row is a 500 for everyone.
    //
    // The divergent id is DISCOVERED rather than hard-coded: pinning
    // America/Coyhaique would turn this into a test that fails the day the
    // server's tzdata catches up, which is the day the test stops being needed.
    $divergent = collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))
        ->first(fn (string $id): bool => str_contains($id, '/')
            && ! DB::table('pg_timezone_names')->where('name', $id)->exists());

    if ($divergent === null) {
        $this->markTestSkipped('This PHP build and this Postgres agree on every zone id; nothing to diverge on.');
    }

    $place = placeWithHours([period(2, '19:00', 2, '23:00')], $divergent);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);

    // And the consequence the guard exists for: the listing still ANSWERS.
    // Without the check this is a QueryException, not a shorter list.
    $at = new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO));
    expect(Place::query()->openNow($at)->pluck('id'))->not->toContain($place->id);
});

// ---------------------------------------------------------------------------
// The filter.
// ---------------------------------------------------------------------------

it('finds a place that is open, and not one that is closed', function () {
    // Tuesday 2026-09-08, 20:00 in Montevideo.
    $at = new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO));

    $open = placeWithHours([period(2, '19:00', 2, '23:00')]);
    $closed = placeWithHours([period(2, '08:00', 2, '15:00')]);

    $ids = Place::query()->openNow($at)->pluck('id');

    expect($ids)->toContain($open->id)->not->toContain($closed->id);
});

it('EXCLUDES a place whose hours are unknown, rather than guessing either way', function () {
    $at = new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO));
    $unknown = placeWithHours(null, null);
    // The CONTROL is what makes the exclusion mean anything. Without a place
    // that must come back, this test passes just as well when `openNow()`
    // returns nothing at all — which is the shape of a filter that is broken
    // rather than one that is strict.
    $open = placeWithHours([period(2, '19:00', 2, '23:00')]);

    expect(Place::query()->openNow($at)->pluck('id'))
        ->toContain($open->id)
        ->not->toContain($unknown->id);
});

it('holds a span that crosses midnight into the next day', function () {
    $place = placeWithHours([period(6, '22:00', 0, '01:00')]); // Sat 22:00 → Sun 01:00

    // Sunday 00:30 — inside a span that STARTED last week by minute-of-week
    // arithmetic. This is the case the modular containment exists for.
    $sundayNight = new DateTimeImmutable('2026-09-13 00:30', new DateTimeZone(MONTEVIDEO));
    expect(Place::query()->openNow($sundayNight)->pluck('id'))->toContain($place->id);

    // Sunday 02:00 — after it closed.
    $after = new DateTimeImmutable('2026-09-13 02:00', new DateTimeZone(MONTEVIDEO));
    expect(Place::query()->openNow($after)->pluck('id'))->not->toContain($place->id);
});

it('holds a venue that never closes, at any instant', function () {
    $place = placeWithHours([period(0, '00:00', null, null)]);

    foreach (['2026-09-08 03:00', '2026-09-13 00:30', '2026-09-11 14:22'] as $when) {
        expect(Place::query()->openNow(new DateTimeImmutable($when, new DateTimeZone(MONTEVIDEO)))->pluck('id'))
            ->toContain($place->id);
    }
});

it('answers in the place’s OWN timezone, not the server’s', function () {
    // 23:00 in Montevideo is 04:00 the next day in Madrid. A Madrid place open
    // 19:00–23:00 local is CLOSED at that instant; a Montevideo one is open.
    $at = new DateTimeImmutable('2026-09-08 23:00', new DateTimeZone(MONTEVIDEO));

    $montevideo = placeWithHours([period(2, '19:00', 2, '23:30')], MONTEVIDEO);
    $madrid = placeWithHours([period(2, '19:00', 2, '23:30')], 'Europe/Madrid');

    $ids = Place::query()->openNow($at)->pluck('id');

    expect($ids)->toContain($montevideo->id)->not->toContain($madrid->id);
});

it('uses the instant it is GIVEN, not the database clock', function () {
    // The filter has to be movable by the application's clock, or nothing that
    // depends on the time of day is testable. A `now()` inside the SQL would
    // read the server's clock and pass this test only by accident of when it
    // ran — so the assertion is that two different instants give two different
    // answers for the same row.
    $place = placeWithHours([period(2, '19:00', 2, '23:00')]);
    $zone = new DateTimeZone(MONTEVIDEO);

    expect(Place::query()->openNow(new DateTimeImmutable('2026-09-08 20:00', $zone))->pluck('id'))
        ->toContain($place->id)
        ->and(Place::query()->openNow(new DateTimeImmutable('2026-09-08 09:00', $zone))->pluck('id'))
        ->not->toContain($place->id);
});

it('stays correct across a DST transition, which is why the spans are local wall-clock', function () {
    // Madrid leaves summer time on 2026-10-25 at 03:00 local. A place open
    // 19:00–23:00 LOCAL is open at 22:30 local on both sides of that boundary,
    // even though the UTC offset differs — which is what an interval stored in
    // UTC gets wrong for half the year.
    //
    // 22:30 rather than 20:00, and the half hour matters. At 20:00 the probe sat
    // three hours inside a four-hour window, so a DST-BLIND implementation — a
    // hard-coded `+ interval '2 hours'` instead of `AT TIME ZONE` — passed both
    // assertions and the test could not fail on the bug it is named for. At
    // 22:30 that same mutant computes 23:30 on the CET date and drops the row.
    $place = placeWithHours([period(0, '19:00', 0, '23:00')], 'Europe/Madrid'); // Sundays
    $zone = new DateTimeZone('Europe/Madrid');

    expect(Place::query()->openNow(new DateTimeImmutable('2026-10-18 22:30', $zone))->pluck('id'))
        ->toContain($place->id) // CEST, UTC+2
        ->and(Place::query()->openNow(new DateTimeImmutable('2026-11-01 22:30', $zone))->pluck('id'))
        ->toContain($place->id); // CET, UTC+1
});

// ---------------------------------------------------------------------------
// The whole justification for the design: SQL and PHP must not diverge.
// ---------------------------------------------------------------------------

it('agrees with the cue on every shape, because both read one implementation', function () {
    // NOTE ON WHAT THIS DOES AND DOES NOT PROVE — established by mutation.
    // Removing the week-wrap from `intervals()` leaves this test GREEN, because
    // both sides read that one function and so both got the same bug and went on
    // agreeing. Parity proves AGREEMENT, not correctness; the correctness of the
    // wrap is pinned by 'holds a span that crosses midnight', which does fail.
    // Keep both — this one catches a second implementation appearing, that one
    // catches the shared one being wrong.
    //
    // If these two ever disagree, a place is listed as open with a "Closed"
    // badge on it, or found by nobody while its own screen says "Open until
    // 23:00". This is the test that would catch a second implementation of the
    // schedule creeping into the query — the failure the projection exists to
    // make impossible.
    $shapes = [
        'weekday lunch' => [period(2, '12:00', 2, '15:00')],
        'weekday dinner' => [period(2, '19:00', 2, '23:00')],
        'two services' => [period(2, '12:00', 2, '15:00'), period(2, '19:00', 2, '23:00')],
        'past midnight' => [period(2, '19:00', 3, '02:00')],
        'across the week boundary' => [period(6, '22:00', 0, '01:00')],
        'never closes' => [period(0, '00:00', null, null)],
        'opens and closes at the same minute' => [period(3, '00:00', 3, '00:00')],
        'sunday only' => [period(0, '10:00', 0, '14:00')],
        'saturday late' => [period(6, '20:00', 6, '23:59')],
    ];

    $places = [];
    foreach ($shapes as $label => $periods) {
        $places[$label] = placeWithHours($periods);
    }

    $zone = new DateTimeZone(MONTEVIDEO);
    $disagreements = [];

    // A full week, every 30 minutes: 336 instants across all nine shapes.
    $cursor = new DateTimeImmutable('2026-09-06 00:00', $zone); // a Sunday
    for ($i = 0; $i < 336; $i++) {
        $at = $cursor->modify('+'.($i * 30).' minutes');
        $listed = Place::query()->openNow($at)->pluck('id')->all();

        foreach ($places as $label => $place) {
            $cue = OpeningSchedule::stateAt($place->opening_hours_periods_json, $place->timezone, $at);
            $sql = in_array($place->id, $listed, strict: true);

            // `?? false` would let a cue that became UNKNOWABLE agree with an
            // empty projection, so the two would "match" by both failing.
            if ($cue === null) {
                $disagreements[] = $label.' at '.$at->format('D H:i').' — cue is null; every shape here is knowable';

                continue;
            }

            if ($cue['open_now'] !== $sql) {
                $disagreements[] = $label.' at '.$at->format('D H:i').' — cue: '
                    .var_export($cue['open_now'] ?? null, true).', sql: '.var_export($sql, true);
            }
        }
    }

    expect($disagreements)->toBe([]);
});

// ---------------------------------------------------------------------------
// The backfill.
// ---------------------------------------------------------------------------

it('backfills places materialized before the projection existed', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);
    // Simulate a pre-T-158 row: hours on the place, nothing in the projection.
    PlaceOpenPeriod::query()->where('place_id', $place->id)->delete();

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);
});

it('is idempotent — a second backfill rewrites, it does not double', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00'), period(2, '11:00', 2, '23:00')]);

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);
    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(2);
});

it('clears rows the backfill finds orphaned by hours that went away', function () {
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);
    // A query-builder write, which fires no model events — exactly the bypass
    // the backfill has to be able to repair.
    DB::table('places')->where('id', $place->id)->update(['opening_hours_periods_json' => null]);
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(0);
});

it('survives a malformed periods value rather than aborting the whole walk', function () {
    // `jsonb_array_length` RAISES on a key that is present and not an array. If
    // that reached the bulk DELETE, ONE bad row would leave the ENTIRE corpus
    // unmaterialized — and deploy.sh treats this command as non-fatal, so the
    // release would ship with nothing listable and no failure.
    $good = placeWithHours([period(1, '11:00', 1, '23:00')]);
    $bad = placeWithHours([period(1, '11:00', 1, '23:00')]);
    DB::table('places')->where('id', $bad->id)->update([
        'opening_hours_periods_json' => DB::raw("'{\"closed\": true}'::jsonb"),
    ]);

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    expect(PlaceOpenPeriod::query()->where('place_id', $good->id)->count())->toBe(1)
        ->and(PlaceOpenPeriod::query()->where('place_id', $bad->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Failure handling.
// ---------------------------------------------------------------------------

it('never fails the write that produced the hours, and says so in the log', function () {
    Log::spy();

    // Force the materializer to throw for every call.
    $this->mock(OpenPeriodMaterializer::class, function ($mock) {
        $mock->shouldReceive('materialize')->andThrow(new RuntimeException('projection is down'));
    });

    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);

    // The place still saved: derived data with a rebuild path must not take the
    // enrichment down with it.
    expect(Place::query()->whereKey($place->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->with('open_periods.materialize_failed', ['place_id' => $place->id]);
});

it('does NOT swallow a failure that left the caller transaction unusable', function () {
    // Through the REAL framework path, which the first version of this test did
    // not reach: it called `DB::beginTransaction()` by hand, so
    // `handleTransactionException()` never ran and the test pinned a mechanism
    // the actual failure does not produce. It passed against a guard that could
    // not fire.
    //
    // What genuinely happens: the materializer opens a nested transaction, a
    // statement aborts the Postgres transaction, and the error is one
    // `causedByConcurrencyError()` matches. Laravel then takes its early branch
    // — decrement the counter, rethrow, NO `ROLLBACK TO SAVEPOINT` — so the
    // depth is restored while the connection is still poisoned. Both halves are
    // reproduced here: the `1/0` does the real aborting, the message makes
    // Laravel classify it, and neither is decoration.
    Log::spy();

    $this->mock(OpenPeriodMaterializer::class, function ($mock) {
        $mock->shouldReceive('materialize')->andReturnUsing(function () {
            DB::transaction(function () {
                try {
                    DB::statement('select 1/0');
                } catch (Throwable) {
                    // Swallowed on purpose: the point is the ABORTED connection
                    // it leaves behind, not this exception.
                }

                throw new RuntimeException('deadlock detected');
            });
        });
    });

    // A caller transaction, which is the only context where any of this matters.
    // `DeadlockException` is what Laravel's early branch rethrows, and what the
    // observer must let past rather than turn into a warning.
    expect(fn () => DB::transaction(fn () => placeWithHours([period(1, '11:00', 1, '23:00')])))
        ->toThrow(DeadlockException::class, 'deadlock detected');

    // And NOT reported as a survivable projection failure: the write did not
    // survive. The NO-ARGUMENT form, deliberately. Mockery's second parameter is
    // `withArgs()`, so any argument list here has to match the real two-argument
    // call exactly — and the place id is a sequence value that a full-suite run
    // never makes 1. A `never()` expectation whose arguments cannot match passes
    // whatever the code does, which is how this assertion first shipped green
    // against an observer that was still logging.
    Log::shouldNotHaveReceived('warning');
});

// ---------------------------------------------------------------------------
// Reachability: a scope with no caller is not a feature. All three surfaces
// that take the faceted filters take this one, because a filter one surface
// silently DROPS returns a 200 the caller believes was filtered — the exact
// failure `?dish=` shipped with on the map (T-157) and had to fix in review.
// ---------------------------------------------------------------------------

it('filters on every surface that takes the faceted filters', function (string $surface) {
    // Tuesday 20:00 in Montevideo, frozen so the endpoint's own `now()` is the
    // instant these fixtures were built around.
    $this->travelTo(new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO)));

    $open = placeWithHours([period(2, '19:00', 2, '23:00')]);
    $closed = placeWithHours([period(2, '08:00', 2, '15:00')]);
    $unknown = placeWithHours(null, null);

    $user = User::factory()->create();

    // Saved to one of the user's lists — which is what puts a place in "my
    // places". One fixture set drives all three surfaces, so a surface that
    // ignores the filter is caught by the same assertion rather than by a test
    // someone has to remember to write for it.
    $list = PlaceList::factory()->create(['user_id' => $user->id]);
    foreach ([$open, $closed, $unknown] as $place) {
        $list->items()->create(['place_id' => $place->id]);
    }

    $url = match ($surface) {
        // The public index requires a point alongside the flag — see the
        // 422 test below for why — so this URL carries one. The map's bound is
        // its bbox and "my places" is scoped to the caller, so neither needs one.
        'public index' => '/api/v1/places?open_now=1&near=-34.90,-56.16&radius_m=50000',
        'map' => '/api/v1/map/places?open_now=1&bbox=-56.3,-35.0,-56.0,-34.8&zoom=13',
        'my places' => '/api/v1/me/places?open_now=1',
    };

    $response = $surface === 'my places'
        ? $this->actingAs($user)->getJson($url)
        : $this->getJson($url);

    $response->assertOk();

    $ids = idsFrom($response->json(), $surface);

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($closed->id)
        ->and($ids)->not->toContain($unknown->id);
})->with(['public index', 'map', 'my places']);

it('refuses open_now without a point on the public index, and only there', function (string $surface, int $status) {
    $this->travelTo(new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO)));

    $user = User::factory()->create();
    $open = placeWithHours([period(2, '11:00', 2, '23:00')]);
    // A place that is CLOSED at the travelled instant, saved and mapped
    // alongside the open one. Without it the 200 cases below would pass with the
    // filter removed entirely — one fixture cannot tell "it filtered" from "it
    // returned everything".
    $closed = placeWithHours([period(2, '08:00', 2, '15:00')]);
    $list = PlaceList::factory()->create(['user_id' => $user->id]);
    $list->items()->create(['place_id' => $open->id]);
    $list->items()->create(['place_id' => $closed->id]);

    // The filter is a correlated EXISTS with nothing to bound it: on the public
    // index a point is the only thing that cuts the candidate set before the
    // periods of every visible place are probed. The map is bounded by its
    // required bbox and "my places" by the caller's own list, so the SAME flag
    // is accepted there without one — which is the half of this that a rule
    // copied onto all three request classes would have broken.
    $url = match ($surface) {
        'public index' => '/api/v1/places?open_now=1',
        'map' => '/api/v1/map/places?open_now=1&bbox=-56.3,-35.0,-56.0,-34.8&zoom=13',
        'my places' => '/api/v1/me/places?open_now=1',
    };

    $response = $surface === 'my places'
        ? $this->actingAs($user)->getJson($url)
        : $this->getJson($url);

    $response->assertStatus($status);

    if ($status === 422) {
        // The envelope is this API's own (`error.details`), not Laravel's
        // default `errors` — asserting the wrong shape is how a 422 test comes
        // to pass on any 422 at all.
        expect($response->json('error.details'))->toHaveKey('open_now');

        return;
    }

    // Not just a 200: the flag still FILTERS on the surfaces that accept it
    // without a point. Both halves are load-bearing — dropping `openNow()` from
    // these two paths keeps the `toContain` green and turns the `not->toContain`
    // red, which is the mutation this pair exists to catch.
    $ids = idsFrom($response->json(), $surface);

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($closed->id);
})->with([
    'public index' => ['public index', 422],
    'map' => ['map', 200],
    'my places' => ['my places', 200],
]);

it('indexes the columns the default sort pages by', function () {
    // A schema pin, not a plan assertion: EXPLAIN output depends on statistics
    // and row counts a test database does not have, so asserting "it uses the
    // index" here would assert the planner's mood. What must not silently
    // disappear is the index itself — `sort=recent` orders by
    // `created_at DESC, id DESC` and its cursor compares that same tuple, and
    // without it every filtered listing sorts its whole candidate set before the
    // LIMIT applies. That is the only bound `?open_now=1` has that does not
    // depend on how much the geography happened to remove.
    // Matched by the COLUMN LIST rather than by name: renaming an index in a
    // later migration changes nothing about the invariant, and a pin that goes
    // red for a rename teaches the next author to delete pins. `tablename` still
    // scopes it, so an index created on the wrong table does not satisfy this.
    //
    // Precisely: it matches the literal `(created_at,id)` after whitespace is
    // stripped, so an equivalent index declared `(created_at DESC, id DESC)`
    // would render as `(created_atDESC,idDESC)` and fail this. Conservative in
    // the safe direction — it can only ask for a rewrite, never bless a missing
    // index — but "by columns" was a stronger claim than the code makes.
    $matching = DB::table('pg_indexes')
        ->where('tablename', 'places')
        ->pluck('indexdef')
        ->map(fn ($definition) => (string) preg_replace('/\s+/', '', (string) $definition))
        ->filter(fn (string $definition) => str_contains($definition, '(created_at,id)'));

    expect($matching)->not->toBeEmpty();
});

it('filters and orders together on the query Tonight actually sends', function () {
    // This replaces a test that asserted `open_now` on the public index with the
    // DEFAULT sort. Review found it was byte-identical to the `public index` row
    // of the surface test above, whose assertions are a superset — and that the
    // one combination nothing covered was the only one the app ever sends.
    // `useTonight` pairs `open_now=1` with `sort=distance` on every request.
    //
    // Both halves have to hold at once, which is the point: `open_now` must
    // still exclude while the sort is composed on top of it, and the order must
    // survive the correlated EXISTS the filter adds to the query.
    $this->travelTo(new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO)));

    // Montevideo's centre, and the point every URL below measures from.
    $near = '-34.90,-56.16';

    // Open, and the FURTHER of the two open ones — so an endpoint that ignored
    // the sort would be as likely to put it first as not, and one that ignored
    // the filter would still have to place it after `$nearerButClosed`.
    $fartherOpen = placeWithHours([period(2, '19:00', 2, '23:00')], MONTEVIDEO, -34.95, -56.20);
    $nearerOpen = placeWithHours([period(2, '11:00', 2, '23:00')], MONTEVIDEO, -34.901, -56.161);
    $nearerButClosed = placeWithHours([period(2, '08:00', 2, '15:00')], MONTEVIDEO, -34.9005, -56.1605);
    // No periods AND no timezone — the "hours unknown" row, excluded either way.
    $nearestButUnknown = placeWithHours(null, null, -34.9001, -56.1601);

    $ids = idsFrom($this->getJson(
        "/api/v1/places?open_now=1&near={$near}&radius_m=50000&sort=distance"
    )->assertOk()->json());

    // Excluded: shut right now, and hours nobody knows. The unknown one is the
    // NEAREST place in the fixture, so it would head the list if the filter were
    // dropped — the row that must be absent, per T-158's acceptance.
    expect($ids)->not->toContain($nearerButClosed->id);
    expect($ids)->not->toContain($nearestButUnknown->id);

    // Ordered by distance, not by insertion or recency: the nearer open place
    // comes first even though it was created second. Separate `expect()` calls
    // rather than one `->and()` chain — `->not` persists across `->and()` in
    // Pest, which silently inverted this assertion when it was written that way.
    expect($ids)->toBe([$nearerOpen->id, $fartherOpen->id]);
});

it('reads every spelling of the flag the way the caller meant it', function (string $query, ?bool $filtered) {
    $this->travelTo(new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO)));

    $closed = placeWithHours([period(2, '08:00', 2, '15:00')]);

    // Every row carries a point, because the public index refuses `open_now`
    // without one; the flag's SPELLING is what this table is about, and mixing
    // in the missing-point 422 would make three rows fail for two reasons.
    $response = $this->getJson('/api/v1/places?near=-34.90,-56.16&radius_m=50000'
        .str_replace('?', '&', $query));

    if ($filtered === null) {
        $response->assertStatus(422);

        return;
    }

    $ids = idsFrom($response->assertOk()->json());

    expect(in_array($closed->id, $ids, strict: true))->toBe(! $filtered);
})->with([
    // The table is the point, and it is here because the two spellings an
    // earlier version enumerated — absent and `open_now=0` — were the two the
    // code happened to get right. A review flagged `(bool) "false" === true` as
    // a live defect on all three surfaces; the table is what settles that it is
    // NOT, and pins the reason so nobody has to re-derive it.
    //
    // Laravel's `boolean` RULE is strict: `in_array($v, [true, false, 0, 1,
    // '0', '1'], true)`. So "false", "true" and "on" never reach the controller
    // at all — they are 422 at the boundary, which is why reading the flag with
    // `validated()` rather than `Request::boolean()` is safe. If that rule is
    // ever loosened, THESE ROWS GO RED, and the reader is sent to the cast.
    'absent' => ['', false],
    'open_now=0' => ['?open_now=0', false],
    'open_now= (empty)' => ['?open_now=', false],
    'open_now=1' => ['?open_now=1', true],
    'open_now=false is REFUSED, not read as false' => ['?open_now=false', null],
    'open_now=true is REFUSED, not read as true' => ['?open_now=true', null],
    'open_now=on is REFUSED' => ['?open_now=on', null],
]);

it('refuses a mis-spelled flag without a point too, and says so once', function () {
    // The guard on the near-requirement reads the flag with `boolean()`
    // (filter_var), which is looser than the `boolean` RULE — so "yes" is a value
    // the rule rejects and the guard would have read as true. Without the
    // `errors()->has()` check it produced two messages under one key: "must be
    // true or false" and "requires the near parameter", which contradict each
    // other about what is wrong. The request still 422s either way; this pins
    // WHICH complaint it makes.
    $response = $this->getJson('/api/v1/places?open_now=yes');

    $response->assertStatus(422);

    expect($response->json('error.details.open_now'))->toHaveCount(1)
        ->and($response->json('error.details.open_now.0'))->not->toContain('near');
});

// ---------------------------------------------------------------------------
// The bypass the observer cannot see.
// ---------------------------------------------------------------------------

/**
 * The set of query-builder writers of `places`, each with a reason.
 *
 * The observer fires on Eloquent events, so a write that bypasses them is a
 * potential bypass — the projection would silently describe last week's hours.
 * This asserts over the SET OF WRITERS rather than any one call's spelling,
 * because the sibling guard for `dishes` (T-157) originally grepped for the
 * column name and missed the writer that passes a whole row array.
 *
 * A new writer fails here until someone adds it to this list, which forces
 * "does this touch the hours, and if so who re-projects them?" to be answered
 * deliberately rather than discovered in production.
 *
 * @return array<string, string>
 */
function knownPlaceQueryBuilderWriters(): array
{
    return [
        // Reads a row for the geofence check. No write at all.
        'app/Services/Redemptions/RedemptionGeofence.php' => 'read-only distance lookup',
        // Dev-only performance fixture. Bulk-inserts places with no hours.
        'database/seeders/MapPerformanceSeeder.php' => 'dev seeder, bulk insert, writes no hours',
        // Three historical data migrations, all predating the hours columns
        // (added 2026_09_03) and so unable to have touched them: they write
        // `status`, and `website_source` / `phone_source`.
        'database/migrations/2026_07_16_100000_add_removed_to_place_status_check.php' => 'sets status only',
        'database/migrations/2026_07_19_000001_activate_google_verified_pending_places.php' => 'sets status only',
        'database/migrations/2026_08_19_120000_add_contact_field_provenance_to_places.php' => 'sets contact provenance only',
        // Raw `UPDATE places SET …`, both writing an unrelated column.
        'database/migrations/2026_07_11_000017_add_review_moderation_and_google_sync.php' => 'sets google_reviews_synced_at only',
        'database/migrations/2026_07_12_000019_add_hidden_place_status.php' => 'sets status only',
        // The Eloquent mass update the first version of this guard could not
        // see: `Place::whereKey($ids)->update(['needs_admin_review' => false])`.
        // Harmless as written — but it is the shape a "set timezone for these
        // venues" bulk action would take, and that one WOULD need to
        // re-materialize. Listed so the next person adding a bulk action here
        // has to answer the question rather than discover it.
        'app/Filament/Resources/Places/Tables/PlacesTable.php' => 'bulk-clears needs_admin_review; touches no hours',
    ];
}

/**
 * Does this source write `places` by a route that fires no model events?
 *
 * THREE spellings, because the first version knew only the first — and the
 * bypass this codebase already contains is the second.
 */
function mentionsPlacesQueryBuilder(string $code): bool
{
    // `Schema::table('places', …)` is DDL: it cannot change a row's hours, and
    // including it would put all nine schema migrations on the allow-list,
    // where a real DML writer would then be invisible among them.
    $code = preg_replace('/Schema::table\(/', 'SchemaDdl(', $code) ?? $code;

    $patterns = [
        // 1. The query builder, by table name.
        "/(DB::table|->table|->from)\(\s*'places'\s*\)/",
        // 2. An ELOQUENT MASS UPDATE — fires no model events either, and
        //    contains no 'places' literal, so the first pattern cannot see it.
        '/\bPlace::(query\(\)|where\w*\()[^;]*?->\s*(update|insert|upsert|delete)\s*\(/s',
        // 3. Raw `UPDATE places SET …`. Data migrations here are routinely
        //    written that way.
        '/\bupdate\s+places\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return true;
        }
    }

    return false;
}

it('has no UNKNOWN query-builder writer of places, which would bypass the observer', function () {
    expect(unknownWritersOf(knownPlaceQueryBuilderWriters(), mentionsPlacesQueryBuilder(...)))->toBe([]);
});

it('detects a writer that the allow-list does not name', function () {
    // The detector has to be shown to DETECT, and each arm separately — a guard
    // nobody has watched fire is worth as much as the bug it was written to
    // catch, and this one shipped blind to two of the three spellings.
    expect(mentionsPlacesQueryBuilder("DB::table('places')->update(['timezone' => 'X']);"))->toBeTrue()
        ->and(mentionsPlacesQueryBuilder("\$q->from('places')->insert(\$row);"))->toBeTrue()
        // The Eloquent mass update — no 'places' literal anywhere in it.
        ->and(mentionsPlacesQueryBuilder("Place::whereKey(\$ids)->update(['timezone' => 'X']);"))->toBeTrue()
        ->and(mentionsPlacesQueryBuilder("Place::query()->whereNull('timezone')->update(['timezone' => 'X']);"))->toBeTrue()
        // Raw SQL, as the data migrations here are written.
        ->and(mentionsPlacesQueryBuilder("DB::statement('UPDATE places SET timezone = NULL');"))->toBeTrue()
        // DDL is not a row write, and must not drag every schema migration in.
        ->and(mentionsPlacesQueryBuilder("Schema::table('places', function (\$t) {});"))->toBeFalse()
        // A different table is not this table, and a READ is not a write.
        ->and(mentionsPlacesQueryBuilder("DB::table('place_sources')->update([]);"))->toBeFalse()
        ->and(mentionsPlacesQueryBuilder("Place::query()->where('id', 1)->first();"))->toBeFalse()
        // And prose about it is not a write — the shared, TOKEN-based stripper
        // removes the comment. The regex this replaced also stripped inside
        // string literals, so a line holding a URL with `//` in it lost
        // everything after it, which could hide a real writer on that line.
        ->and(stripPhpComments("// see DB::table('places')\n\$x = 1;"))->not->toContain('places')
        ->and(stripPhpComments("\$u = 'https://x/places'; DB::table('places')->update([]);"))->toContain("DB::table('places')");
});

// ---------------------------------------------------------------------------
// Two branches the suite reached but never asserted.
// ---------------------------------------------------------------------------

it('reports a venue that never closes as never closing, however it says so', function (array $periods) {
    // The close-less shape and the long way round (open Sunday 00:00, close
    // Sunday 00:00) are the same claim, and `intervals()` folds both into a
    // full-week span so both get the same answer. Before the extraction the
    // second one reported "closes at 00:00" — confidently wrong, and the sort
    // of thing a parity test cannot see, because it only ever compared
    // `open_now`.
    $place = placeWithHours($periods);
    $at = new DateTimeImmutable('2026-09-09 14:00', new DateTimeZone(MONTEVIDEO));

    $cue = OpeningSchedule::stateAt($place->opening_hours_periods_json, $place->timezone, $at);

    expect($cue)->not->toBeNull()
        ->and($cue['open_now'])->toBeTrue()
        ->and($cue['closes_at'])->toBeNull()
        ->and(Place::query()->openNow($at)->pluck('id'))->toContain($place->id);
})->with([
    'the documented close-less shape' => [[['open_day' => 0, 'open_time' => '00:00', 'close_day' => null, 'close_time' => null]]],
    'the same claim said the long way' => [[['open_day' => 0, 'open_time' => '00:00', 'close_day' => 0, 'close_time' => '00:00']]],
]);

it('folds a period a provider sent twice, instead of failing the write on the unique index', function () {
    // A repeated period is a duplicate, not a conflict. Unfolded it reaches
    // `place_open_periods_place_id_open_minute_close_minute_unique`, the insert
    // raises, PlaceObserver swallows it to a warning — and the place is left
    // permanently unlistable while its detail screen shows a correct cue.
    $place = placeWithHours([period(2, '19:00', 2, '23:00'), period(2, '19:00', 2, '23:00')]);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);
});

it('repairs rows that went STALE, not only rows that went missing', function () {
    // The idempotence test proves the backfill does not double. This proves it
    // CORRECTS — which is the job the query-builder bypass creates for it, and
    // the one a walk restricted to places with no rows would silently stop
    // doing while every other backfill test stayed green.
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);
    DB::table('places')->where('id', $place->id)->update([
        'opening_hours_periods_json' => DB::raw('\'[{"open_day":1,"open_time":"09:00","close_day":1,"close_time":"17:00"}]\'::jsonb'),
    ]);
    // Still describing the OLD week: the query-builder write fired no events.
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->value('open_minute'))->toBe(1 * 1440 + 11 * 60);

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->value('open_minute'))->toBe(1 * 1440 + 9 * 60);
});

it('says nothing when the projection is already in step', function () {
    // Half of the signal, and the half a "always warn" implementation would
    // fail. In a healthy system the observer keeps every place materialized, so
    // the nightly pass finds nothing to do and must be quiet — an alert that
    // fires every night is one nobody reads.
    //
    // Its own test rather than the first half of the one below: asserting both
    // polarities of the same spied method in one test makes Mockery verify a
    // `never()` and an `atLeast()` against the same call log.
    Log::spy();

    placeWithHours([period(1, '11:00', 1, '23:00')]);

    $this->artisan('reelmap:open-periods:backfill --fail-on-drift')->assertExitCode(0);

    Log::shouldNotHaveReceived('warning');
});

it('reports the drift it repaired instead of quietly fixing it', function () {
    // The other half, and the reason the nightly pass exists at all. A swallowed
    // observer failure leaves drift neither surface can show — the place keeps
    // rendering a correct "Open" cue from its own jsonb while being absent from
    // every `?open_now=1` listing. A pass that repaired that and printed the
    // same sentence either way would HIDE the broken writer, which is the rule
    // `reelmap:offers:reconcile-quotas` states one screen above this one in
    // `console.php`.
    Log::spy();

    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);

    // Exactly what a swallowed failure leaves behind: rows missing while the
    // place's own hours are intact.
    PlaceOpenPeriod::query()->where('place_id', $place->id)->delete();

    $this->artisan('reelmap:open-periods:backfill')->assertExitCode(0);

    Log::shouldHaveReceived('warning')->with('open_periods.drift_repaired', Mockery::on(
        fn (array $ctx) => $ctx['places'] === 1 && $ctx['sample'] === [$place->id]
    ));

    // Repaired, not merely reported.
    expect(PlaceOpenPeriod::query()->where('place_id', $place->id)->count())->toBe(1);
});

it('fails the SCHEDULED run when it had to repair, and only then', function () {
    // `--fail-on-drift` is what makes the nightly exit code mean "the observer
    // dropped something". Both directions, because a flag that always fails is
    // as useless as one that never does.
    $place = placeWithHours([period(1, '11:00', 1, '23:00')]);

    $this->artisan('reelmap:open-periods:backfill --fail-on-drift')->assertExitCode(0);

    PlaceOpenPeriod::query()->where('place_id', $place->id)->delete();

    $this->artisan('reelmap:open-periods:backfill --fail-on-drift')->assertExitCode(1);
});

it('rebuilds the projection on a schedule, not only at deploy', function () {
    // The observer swallows a materialize failure so the enrichment that
    // produced the hours still succeeds — which leaves drift with no way back:
    // "a retry only heals this by accident", as its own comment says, and the
    // deploy-time backfill only runs at a deploy.
    //
    // Nothing surfaces that drift either: the place keeps rendering a correct
    // "Open" cue on its detail screen, computed in PHP from the jsonb, while
    // being absent from every `?open_now=1` listing. So the schedule is the
    // repair path, and a command nobody runs is not one.
    //
    // Asserted on the OBSERVABLE schedule rather than on the line in
    // `console.php`: both flags matter, because this rewrites the whole table
    // and two hosts doing it at once is waste at best.
    //
    // The `onFailure` alert is deliberately NOT asserted here: it is stored in a
    // protected `afterCallbacks` with no accessor, and reaching into that would
    // pin the framework's internals rather than our behaviour — the failure this
    // repo calls a wrong-reason guard. The sibling audits scheduled beside this
    // one carry the same alert with the same gap.
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'reelmap:open-periods:backfill'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->onOneServer)->toBeTrue()
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expression)->toBe('45 4 * * *')
        // `--fail-on-drift` is the half that makes the run mean something: it is
        // what turns "the observer dropped places" into a non-zero exit, which
        // the `onFailure` alert then carries somewhere. Scheduled without it,
        // the pass repairs drift silently and reports the same thing either way.
        ->and($events->first()->command)->toContain('--fail-on-drift');
});

it('refuses a span that would match every instant', function () {
    // The predicate `((now - open + 10080) % 10080) < close - open` is only
    // equivalent to "is now inside this span" while the span is at most a week.
    // A longer one is true for every instant, so a single bad row would put a
    // closed venue in every "open now" listing until something re-materialized
    // it. `intervals()` cannot produce one — this is the database saying so
    // too, since all four columns are fillable and the model is reachable.
    $place = placeWithHours(null, null);

    expect(fn () => PlaceOpenPeriod::query()->create([
        'place_id' => $place->id,
        'open_minute' => 0,
        'close_minute' => 20000,
        'timezone' => 'UTC',
    ]))->toThrow(QueryException::class);
});

it('refuses an open_minute outside the week, which matches every instant too', function () {
    // The sibling above bounds the SPAN. This bounds the operand the span check
    // cannot see, and review found it by inserting exactly this row: span 100,
    // so the span clause accepts it — and then Postgres's `%` takes the sign of
    // the dividend, `now - 20000 + 10080` is negative, and the containment test
    // is trivially true. Same "closed venue in every listing forever" outcome,
    // through the operand nothing constrained.
    //
    // A span of 100 minutes, deliberately: it has to pass the other clause, or
    // this test would go green against a constraint that never mentions
    // `open_minute`.
    $place = placeWithHours(null, null);

    expect(fn () => PlaceOpenPeriod::query()->create([
        'place_id' => $place->id,
        'open_minute' => 20000,
        'close_minute' => 20100,
        'timezone' => 'UTC',
    ]))->toThrow(QueryException::class);
});

it('has no request class that ACCEPTS open_now while its consumer drops it', function () {
    // The surface table above is hand-written, so it proves the three surfaces
    // that existed when it was written. Nothing in it makes a FOURTH listing
    // endpoint red — and "a surface that accepts a filter and quietly ignores
    // it" is the exact failure `?dish=` shipped with on the map in T-157.
    //
    // So this DERIVES the surfaces: every FormRequest declaring `open_now`,
    // then every ACTION taking one, which must apply the filter or hand the
    // request to something that does.
    $requests = [];
    foreach (File::allFiles(app_path('Http/Requests')) as $file) {
        if (preg_match('/[\'"]open_now[\'"]/', stripPhpComments((string) file_get_contents($file->getPathname()))) === 1) {
            $requests[] = $file->getFilenameWithoutExtension();
        }
    }

    // The SET, not merely "not empty". A request that stops declaring the flag —
    // renamed, or moved into a shared trait outside this directory — would
    // otherwise drop silently out of coverage while the other two kept the list
    // non-empty and the guard green.
    expect($requests)->toEqualCanonicalizing(['MapPlacesRequest', 'PlaceIndexRequest', 'PlaceListingRequest']);

    // Delegation targets a consumer may hand the request to instead of
    // filtering itself. `$viewport->respond(` is a CALL — an earlier version
    // also accepted the bare `MapViewport $viewport` type-hint, which meant any
    // file injecting the viewport for an unrelated reason marked itself as
    // filtering. The companion test below proves each of these really applies.
    // `$viewport->respond(`, not a bare `->respond(`: two unrelated responders
    // exist (InfluencerClaimController, UserPlaceTagController), and the loose
    // form would mark one of them the day its own `respond()` met a place
    // request in the same method.
    $appliers = ['placeListResponse(', '$viewport->respond('];

    // The applier files themselves are checked WHOLE, by the companion test
    // below, and skipped here: they legitimately split the work across their own
    // methods (MapViewport takes the request in `respond()` and filters in
    // `baseQuery()`), so per-method scoping would flag the very seams that do
    // the filtering.
    $applierFiles = ['Http/Controllers/Api/V1/Concerns/PaginatesPlaces.php', 'Services/Map/MapViewport.php'];

    $unwired = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (in_array(str_replace(app_path().'/', '', $file->getPathname()), $applierFiles, true)) {
            continue;
        }

        $source = stripPhpComments((string) file_get_contents($file->getPathname()));
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        // PER METHOD, not per file. File scope was a hole with the same shape as
        // the bug this guards: `PlaceController` already contains `openNow(`, so
        // a NEW action added to it that built its own query and forgot the
        // filter was pre-covered for free — and that is precisely how `?dish=`
        // came to be missing on the map.
        foreach (preg_split('/(?=\bfunction\s+\w+\s*\()/', $source) ?: [] as $block) {
            $applies = str_contains($block, 'openNow(');
            foreach ($appliers as $applier) {
                $applies = $applies || str_contains($block, $applier);
            }

            foreach ($requests as $request) {
                if (preg_match('/\b'.preg_quote($request, '/').'\s+\$\w+/', $block) === 1 && ! $applies) {
                    $unwired[] = $relative.'::'.trim(explode('(', $block, 2)[0]).' consumes '.$request;
                }
            }
        }
    }

    expect(array_unique($unwired))->toBe([]);
});

it('the appliers a surface may delegate to apply the filter, in every action they expose', function (string $file, array $expected) {
    // Two assertions, because "the file contains openNow( somewhere" is not
    // enough. The surface guard skips these files whole — they legitimately
    // split the work across their own methods — so a NEW action added to either
    // one that built its own query and forgot the filter would stay green,
    // which is the identical shape to the `?dish=` bug the guard exists to stop.
    //
    // Pinning the SET of request-consuming methods is what closes that: adding
    // `respondCompact(MapPlacesRequest $request)` here fails until someone lists
    // it, and listing it is where they are asked whether it filters.
    $source = stripPhpComments((string) file_get_contents(app_path($file)));

    expect($source)->toContain('openNow(');

    preg_match_all('/function\s+(\w+)\s*\([^)]*\b(MapPlacesRequest|PlaceIndexRequest|PlaceListingRequest)\s+\$/s', $source, $m);

    expect($m[1])->toEqualCanonicalizing($expected);
})->with([
    ['Http/Controllers/Api/V1/Concerns/PaginatesPlaces.php', ['placeListResponse']],
    ['Services/Map/MapViewport.php', ['respond', 'baseQuery', 'pinsResponse', 'clusteredResponse']],
]);
