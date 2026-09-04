<?php

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * T-156 — distance and open/closed reach the surface where the choice is made.
 *
 * The rule under all of it: a viewer-relative field is ABSENT without a viewer,
 * and NULL when the answer is not knowable. Never 0, never false — a zero
 * distance reads as "you are here" and a false `open_now` reads as "closed",
 * and a client cannot tell either from the real thing.
 */
$bbox = 'bbox=-56.30,-35.00,-56.00,-34.80&zoom=15';

function placeAt(float $lat, float $lng, array $attributes = []): Place
{
    return Place::factory()->active()->atPoint($lat, $lng)->create($attributes);
}

/** Montevideo, open 09:00–23:00 every day, in a real zone. */
function openAllWeek(): array
{
    return [
        'timezone' => 'America/Montevideo',
        'opening_hours_periods_json' => collect(range(0, 6))->map(fn ($d) => [
            // `HH:MM`, which is the NORMALIZED stored shape — not Google's raw
            // `0900`. OpeningSchedule::time() rejects the latter, and a rejected
            // period makes the whole schedule unknowable, so a fixture in the
            // wrong shape would quietly test the null branch while claiming to
            // test the open one.
            'open_day' => $d, 'open_time' => '09:00',
            'close_day' => $d, 'close_time' => '23:00',
        ])->all(),
    ];
}

it('omits the viewer-relative pair entirely when no position is given', function () use ($bbox) {
    placeAt(-34.90, -56.16, openAllWeek());

    $pin = $this->getJson("/api/v1/map/places?{$bbox}")->assertOk()->json('data.pins.0');

    // ABSENT, not null and not zero. `array_key_exists` on purpose: a
    // `toBeNull()` assertion passes for a key that is present and null, which is
    // a different payload and a different meaning.
    expect(array_key_exists('distance_m', $pin))->toBeFalse()
        ->and(array_key_exists('open_state', $pin))->toBeFalse();
});

it('carries distance and open_now when the viewer shares a position', function () use ($bbox) {
    placeAt(-34.9011, -56.1645, openAllWeek());

    // ~1.1km away.
    $pin = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.9111,-56.1645")
        ->assertOk()->json('data.pins.0');

    expect($pin['distance_m'])->toBeGreaterThan(900)->toBeLessThan(1300)
        ->and($pin['open_state']['open_now'])->toBeBool()
        // The whole object, so the client can say "open until 23:00" and can age
        // the cue out — neither of which a bare boolean supports.
        ->and($pin['open_state'])->toHaveKeys(['open_now', 'closes_at', 'opens_at']);
});

it('reports open_state as NULL, never a fabricated closed, when the hours are not knowable', function () use ($bbox) {
    // No structured periods and no timezone: the T-155 case.
    placeAt(-34.90, -56.16, ['opening_hours_periods_json' => null, 'timezone' => null]);

    $pin = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.91,-56.16")
        ->assertOk()->json('data.pins.0');

    // The key is PRESENT (a position was given) but the value is null.
    expect(array_key_exists('open_state', $pin))->toBeTrue()
        ->and($pin['open_state'])->toBeNull();
});

it('reports open_state as null when periods exist but the timezone does not', function () use ($bbox) {
    // Half the data is the trap: it is exactly the state that tempts a default,
    // and a default here is a guess about a real shop's front door.
    placeAt(-34.90, -56.16, [...openAllWeek(), 'timezone' => null]);

    $pin = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.91,-56.16")
        ->assertOk()->json('data.pins.0');

    expect($pin['open_state'])->toBeNull();
});

it('computes distance in SQL — one query for the pins however many come back', function () use ($bbox) {
    for ($i = 0; $i < 25; $i++) {
        placeAt(-34.90 - ($i / 1000), -56.16, openAllWeek());
    }

    DB::enableQueryLog();
    $pins = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.95,-56.16")->assertOk()->json('data.pins');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // The assertion that matters is the SHAPE, not a magic number: exactly one
    // statement mentions ST_Distance, so distance is a column of the pin query
    // rather than a per-row computation. A PHP haversine per pin would show up
    // as zero such statements; an N+1 would show up as 25.
    $withDistance = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'ST_Distance'))->count();

    expect($pins)->toHaveCount(25)
        ->and($withDistance)->toBe(1);
});

it('keeps the pair on a singleton pin in the CLUSTERED response too', function () {
    // Same pin, different zoom. Without this the fields appear and disappear as
    // the user pinches — the kind of bug only a test at both zooms finds.
    placeAt(-34.90, -56.16, openAllWeek());

    $low = $this->getJson('/api/v1/map/places?bbox=-56.30,-35.00,-56.00,-34.80&zoom=8&near=-34.91,-56.16')
        ->assertOk();

    expect($low->json('data.pins.0.distance_m'))->not->toBeNull()
        ->and(array_key_exists('open_state', $low->json('data.pins.0')))->toBeTrue();
});

it('rejects a malformed position instead of quietly ignoring it', function () use ($bbox) {
    placeAt(-34.90, -56.16);

    // A caller that sent a position and got a 200 with no distances would
    // reasonably believe the map had one.
    $this->getJson("/api/v1/map/places?{$bbox}&near=-34.90")
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('serves open_state on the LIST surface with the same never-guess rule', function () {
    $known = placeAt(-34.90, -56.16, openAllWeek());
    $unknown = placeAt(-34.91, -56.17, ['opening_hours_periods_json' => null, 'timezone' => null]);

    $rows = collect($this->getJson('/api/v1/places')->assertOk()->json('data'))->keyBy('id');

    expect($rows[(string) $known->id]['open_state']['open_now'])->toBeBool()
        ->and($rows[(string) $unknown->id]['open_state'])->toBeNull();
});
