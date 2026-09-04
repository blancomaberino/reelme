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
        // But `open_state` IS here, and the asymmetry is deliberate: open-or-
        // closed is a fact about the VENUE and nothing about the viewer. Gating
        // it on `near` meant a user who declined location saw no open/closed cue
        // on any pin while /places showed one for the same restaurants.
        ->and($pin['open_state']['open_now'])->toBeTrue();
});

it('serves open_state to a viewer who shared NO position — it is not viewer-relative', function () use ($bbox) {
    // The regression test for the gating this shipped with first. It reads as a
    // duplicate of the assertion above and is not: that one proves the key is
    // present, this one proves the VALUE is right, at a known instant, for a
    // request that carries no `near` at all.
    $this->travelTo('2026-09-07 18:00:00', function () use ($bbox) {
        placeAt(-34.90, -56.16, openAllWeek());

        $pin = $this->getJson("/api/v1/map/places?{$bbox}")->assertOk()->json('data.pins.0');

        expect($pin['open_state'])->toBe(['open_now' => true, 'closes_at' => '23:00', 'opens_at' => null]);
    });
});

it('carries distance and open_now when the viewer shares a position', function () use ($bbox) {
    placeAt(-34.9011, -56.1645, openAllWeek());

    // ~1.1km away.
    $pin = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.9111,-56.1645")
        ->assertOk()->json('data.pins.0');

    expect($pin['distance_m'])->toBeGreaterThan(900)->toBeLessThan(1300)
        // The whole object, so the client can say "open until 23:00" and can age
        // the cue out — neither of which a bare boolean supports.
        ->and($pin['open_state'])->toHaveKeys(['open_now', 'closes_at', 'opens_at']);
});

it('answers open and closed with the VALUE, at a known instant, not merely a boolean', function () use ($bbox) {
    // `toBeBool()` was all this file asserted, and it is satisfied by the bug it
    // exists to catch: return a fabricated `open_now => false` for every
    // knowable schedule and a type assertion stays green while the app tells
    // people a restaurant that is open is shut. The clock has to be frozen for
    // the value to be assertable at all — the sibling PlaceOpenStateTest does
    // exactly this.
    placeAt(-34.9011, -56.1645, openAllWeek());
    $url = "/api/v1/map/places?{$bbox}&near=-34.9111,-56.1645";

    // 18:00 local, inside 09:00–23:00.
    $open = $this->travelTo('2026-09-07 18:00:00', fn () => $this->getJson($url)->assertOk()->json('data.pins.0'));
    expect($open['open_state'])->toBe(['open_now' => true, 'closes_at' => '23:00', 'opens_at' => null]);

    // 07:00 local, before it opens — and the honest "closed" the null rule is
    // NOT about. Without this the negative assertions elsewhere could all pass
    // by the cue never rendering at all.
    $shut = $this->travelTo('2026-09-07 07:00:00', fn () => $this->getJson($url)->assertOk()->json('data.pins.0'));
    expect($shut['open_state'])->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => '09:00']);
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
    // Flushed, not merely enabled: the log ACCUMULATES across enable/disable
    // pairs, so a second measurement added to this test later would silently
    // count this one's statements too.
    DB::flushQueryLog();
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

    // The VALUE, not `not->toBeNull()`. `pin()` casts a missing `distance`
    // attribute through `(float) null` to 0 — which is not null, so a
    // presence-only assertion is satisfied by exactly the regression this test
    // is named for: passing `null` to `selectPinFields` on the clustered path
    // while still passing `$near` to `pin()`. The place is ~1.1 km away.
    expect($low->json('data.pins.0.distance_m'))->toBeGreaterThan(900)->toBeLessThan(1300)
        ->and(array_key_exists('open_state', $low->json('data.pins.0')))->toBeTrue();
});

it('rejects a malformed position instead of quietly ignoring it, and says why in the caller\'s words', function () use ($bbox) {
    placeAt(-34.90, -56.16);

    // A caller that sent a position and got a 200 with no distances would
    // reasonably believe the map had one.
    $response = $this->getJson("/api/v1/map/places?{$bbox}&near=-34.90")
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    // The MESSAGE, not just the code. Without the messages() override the map
    // answers "The near lat field is required when near is present." — naming
    // `nearLat`, an internal field the caller never sent — while /places answers
    // the identical input with the string below. One parameter, two endpoints,
    // two stories about it.
    expect($response->json('error.details.nearLat'))->toContain('near must be "lat,lng".');
});

it('rejects an ARRAY position rather than 500ing on it', function () use ($bbox) {
    // `?near[]=1&near[]=2` was a real 500 on the sibling endpoint (T-042). The
    // map copied the `is_string` guard that fixed it; this is the test that
    // proves the copy works, which the copy did not come with.
    placeAt(-34.90, -56.16);

    $this->getJson("/api/v1/map/places?{$bbox}&near[]=-34.90&near[]=-56.16")
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('ignores caller-supplied nearLat/nearLng — they are derived, never input', function () use ($bbox) {
    // `nearLat`/`nearLng` are split out of `near` for the validator's benefit.
    // While the merge only happened on a SUCCESSFUL split, a caller could supply
    // them directly: `near` failed to split, nothing overwrote them, every rule
    // passed, and the map measured every distance from the caller's own pair
    // while `near` said something else — a 200 with no way to notice.
    placeAt(-34.90, -56.16);

    $this->getJson("/api/v1/map/places?{$bbox}&near=1,2,3&nearLat=-34.91&nearLng=-56.16")
        ->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
});

it('serves open_state on the LIST surface with the same never-guess rule', function () {
    $known = placeAt(-34.90, -56.16, openAllWeek());
    $unknown = placeAt(-34.91, -56.17, ['opening_hours_periods_json' => null, 'timezone' => null]);

    $rows = collect($this->getJson('/api/v1/places')->assertOk()->json('data'))->keyBy('id');

    expect($rows[(string) $known->id]['open_state']['open_now'])->toBeBool()
        ->and($rows[(string) $unknown->id]['open_state'])->toBeNull();
});
