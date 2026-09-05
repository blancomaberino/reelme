<?php

use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceOpenPeriod;
use App\Models\User;
use App\Services\Places\OpenPeriodMaterializer;
use App\Services\Places\PlaceEditor;
use App\Support\OpeningSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

function placeWithHours(?array $periods, ?string $timezone = MONTEVIDEO): Place
{
    return Place::factory()->active()->atPoint(-34.90, -56.16)->create([
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

    expect(Place::query()->openNow($at)->pluck('id'))->not->toContain($unknown->id);
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
    // Madrid moves off summer time on 2026-10-25 at 03:00 local. A place open
    // 19:00–23:00 LOCAL is open at 20:00 local on both sides of that boundary,
    // even though the UTC offset differs — which is exactly what an interval
    // stored in UTC would get wrong for half the year.
    $place = placeWithHours([period(0, '19:00', 0, '23:00')], 'Europe/Madrid'); // Sundays
    $zone = new DateTimeZone('Europe/Madrid');

    expect(Place::query()->openNow(new DateTimeImmutable('2026-10-18 20:00', $zone))->pluck('id'))
        ->toContain($place->id) // CEST, UTC+2
        ->and(Place::query()->openNow(new DateTimeImmutable('2026-11-01 20:00', $zone))->pluck('id'))
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
        $at = $cursor->modify("+{$i} minutes")->modify('+'.($i * 29).' minutes');
        $listed = Place::query()->openNow($at)->pluck('id')->all();

        foreach ($places as $label => $place) {
            $cue = OpeningSchedule::stateAt($place->opening_hours_periods_json, $place->timezone, $at);
            $sql = in_array($place->id, $listed, strict: true);

            if (($cue['open_now'] ?? false) !== $sql) {
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
        'public index' => '/api/v1/places?open_now=1',
        'map' => '/api/v1/map/places?open_now=1&bbox=-56.3,-35.0,-56.0,-34.8&zoom=13',
        'my places' => '/api/v1/me/places?open_now=1',
    };

    $response = $surface === 'my places'
        ? $this->actingAs($user)->getJson($url)
        : $this->getJson($url);

    $response->assertOk();

    // The map answers under `data.pins`; the listings under `data`. Ids
    // serialize as STRINGS, so they are cast rather than compared loosely — a
    // loose comparison here would have passed while the filter did nothing.
    $ids = collect(data_get($response->json(), $surface === 'map' ? 'data.pins' : 'data'))
        ->pluck('id')->map(intval(...))->all();

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($closed->id)
        ->and($ids)->not->toContain($unknown->id);
})->with(['public index', 'map', 'my places']);

it('leaves the listing unfiltered when open_now is absent or false', function () {
    $this->travelTo(new DateTimeImmutable('2026-09-08 20:00', new DateTimeZone(MONTEVIDEO)));

    $closed = placeWithHours([period(2, '08:00', 2, '15:00')]);

    // Absent and explicitly-false must both mean "do not filter" — a truthiness
    // bug here would make `open_now=0` narrow the list, which is worse than
    // ignoring it because the caller cannot tell.
    foreach (['/api/v1/places', '/api/v1/places?open_now=0'] as $url) {
        $ids = collect(data_get($this->getJson($url)->assertOk()->json(), 'data'))
            ->pluck('id')->map(intval(...))->all();
        expect($ids)->toContain($closed->id);
    }
});

// ---------------------------------------------------------------------------
// The bypass the observer cannot see.
// ---------------------------------------------------------------------------

/**
 * The set of query-builder writers of `places`, each with a reason.
 *
 * The observer fires on Eloquent events, so a query-builder write to that table
 * is a potential bypass — the rows would silently describe last week's hours.
 * This asserts over the SET OF WRITERS rather than over any one call's spelling,
 * because the same guard written for `dishes` (T-157) originally grepped for the
 * column name and missed the writer that passes a whole row array, where the
 * column name never appears in the source text.
 *
 * A new query-builder write to `places` fails here until someone adds it to this
 * list, which forces "does this touch the hours, and if so who re-projects
 * them?" to be answered deliberately rather than discovered in production.
 *
 * @return array<string, string>
 */
function knownPlaceQueryBuilderWriters(): array
{
    return [
        // Reads a row for the geofence check. No write at all.
        'app/Services/Redemptions/RedemptionGeofence.php' => 'read-only distance lookup',
        // Dev-only performance fixture. Bulk-inserts places with no hours; the
        // backfill would give them rows if they ever gained any.
        'database/seeders/MapPerformanceSeeder.php' => 'dev seeder, bulk insert, writes no hours',
        // Three historical data migrations, all of which predate the hours
        // columns (added 2026_09_03) and so cannot have touched them: they write
        // `status`, and `website_source` / `phone_source`. Listed rather than
        // pattern-exempted, because "it is a migration" is not a reason — a data
        // migration is exactly where a mass rewrite of every row's hours would
        // live, and it would fire no model events.
        'database/migrations/2026_07_16_100000_add_removed_to_place_status_check.php' => 'sets status only',
        'database/migrations/2026_07_19_000001_activate_google_verified_pending_places.php' => 'sets status only',
        'database/migrations/2026_08_19_120000_add_contact_field_provenance_to_places.php' => 'sets contact provenance only',
    ];
}

function mentionsPlacesQueryBuilder(string $code): bool
{
    // `Schema::table('places', …)` is DDL and is excluded: it cannot change a
    // row's hours, and including it would put all nine schema migrations on the
    // allow-list, where a real DML writer would then be invisible among them.
    $code = preg_replace('/Schema::table\(/', 'SchemaDdl(', $code) ?? $code;

    return preg_match("/(DB::table|->table|->from)\(\s*'places'\s*\)/", $code) === 1;
}

function stripCommentsForOpenPeriodGuard(string $code): string
{
    // Comments name these symbols constantly — this very file does — so a guard
    // that scanned raw text would flag prose and, worse, teach the next person
    // to add an exemption for a file that writes nothing.
    return preg_replace('!/\*.*?\*/|//[^\n]*!s', '', $code) ?? $code;
}

it('has no UNKNOWN query-builder writer of places, which would bypass the observer', function () {
    $offenders = [];

    // `database/` as well as `app/`: seeders and data migrations write this
    // table too, and a guard scanning only app_path() would let a migration
    // rewrite every row's hours in silence.
    foreach ([app_path(), base_path('database')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Keyed on the path RELATIVE TO THE REPO, not the basename: keying
            // on the filename would let a brand-new file inherit an exemption.
            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (mentionsPlacesQueryBuilder(stripCommentsForOpenPeriodGuard((string) file_get_contents($file->getPathname())))
                && ! array_key_exists($relative, knownPlaceQueryBuilderWriters())) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('detects a writer that the allow-list does not name', function () {
    // The detector has to be shown to DETECT. A guard nobody has watched fire is
    // worth as much as the bug it was written to catch — the dishes guard had
    // never been proved before it was found to be missing a real violation.
    expect(mentionsPlacesQueryBuilder("DB::table('places')->update(['timezone' => 'X']);"))->toBeTrue()
        ->and(mentionsPlacesQueryBuilder("\$q->from('places')->insert(\$row);"))->toBeTrue()
        // DDL is not a row write, and must not drag every schema migration in.
        ->and(mentionsPlacesQueryBuilder("Schema::table('places', function (\$t) {});"))->toBeFalse()
        // A different table is not this table.
        ->and(mentionsPlacesQueryBuilder("DB::table('place_sources')->update([]);"))->toBeFalse()
        // And prose about it is not a write.
        ->and(stripCommentsForOpenPeriodGuard("// see DB::table('places')\ncode();"))->not->toContain('places');
});
