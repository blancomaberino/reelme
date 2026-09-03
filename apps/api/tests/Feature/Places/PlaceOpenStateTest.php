<?php

use App\Models\Place;
use App\Services\Geo\TimezoneResolver;
use App\Services\Places\Enrichment\Sources\TimezoneBusinessSource;

/**
 * The served open/closed cue (T-155), end to end: the column pair on the place,
 * the resolver that fills the timezone half, and what `GET /places/{slug}`
 * actually returns.
 *
 * The rule under test throughout is the one the whole task exists for: a status
 * is served only when it is a fact. Either half missing must yield `open_state:
 * null` — the client's instruction to show the hours lines and NO cue — never a
 * `false` that renders as "Closed" on a restaurant that is open.
 */

/** A `TimezoneResolver` that answers with whatever the test hands it, without leaving the process (NFR-15). */
function fakeTimezoneResolver(?string $zone): TimezoneResolver
{
    return new class($zone) implements TimezoneResolver
    {
        public int $calls = 0;

        public function __construct(private readonly ?string $zone) {}

        public function resolve(float $lat, float $lng): ?string
        {
            $this->calls++;

            return $this->zone;
        }
    };
}

/** Monday 11:00–23:00, in Google's day numbering (0 = Sunday). */
function mondayLunchToLate(): array
{
    return [['open_day' => 1, 'open_time' => '11:00', 'close_day' => 1, 'close_time' => '23:00']];
}

it('serves no state at all when the place has no structured hours', function () {
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: 11:00 AM – 11:00 PM'],
        'opening_hours_periods_json' => null,
        'timezone' => 'America/Montevideo',
    ]);

    $body = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    // The human-readable lines still render — losing the cue must not lose the hours.
    expect($body['opening_hours'])->toBe(['Monday: 11:00 AM – 11:00 PM']);
    expect($body['open_state'])->toBeNull();
});

it('serves no state at all when the place has no timezone', function () {
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: 11:00 AM – 11:00 PM'],
        'opening_hours_periods_json' => mondayLunchToLate(),
        'timezone' => null,
    ]);

    $body = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data');

    // Periods alone cannot place a venue in time. Answering from the SERVER's
    // timezone would be right only for venues that happen to share it.
    expect($body['open_state'])->toBeNull();
});

it('serves the computed state when both halves are present', function () {
    // 2026-09-07 18:00 UTC is Monday 15:00 in Montevideo — inside 11:00–23:00.
    $this->travelTo('2026-09-07 18:00:00');

    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: 11:00 AM – 11:00 PM'],
        'opening_hours_periods_json' => mondayLunchToLate(),
        'timezone' => 'America/Montevideo',
    ]);

    expect($this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data.open_state'))
        ->toBe(['open_now' => true, 'closes_at' => '23:00', 'opens_at' => null]);
});

it('flips to closed at the same place two hours later', function () {
    // 2026-09-08 02:30 UTC = Monday 23:30 local, half an hour after closing. The
    // pair with the test above is the point: same row, same request, different
    // answer — proving the cue is computed at read time, not stored.
    $this->travelTo('2026-09-08 02:30:00');

    $place = Place::factory()->active()->create([
        'opening_hours_periods_json' => mondayLunchToLate(),
        'timezone' => 'America/Montevideo',
    ]);

    expect($this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data.open_state'))
        ->toBe(['open_now' => false, 'closes_at' => null, 'opens_at' => null]);
});

it('never forwards a stale provider verdict — the state is recomputed per request', function () {
    // Google's own `open_now` is true at fetch time and a lie for the 30 days the
    // response is cached, so nothing may carry it. Storing a verdict on the row
    // and serving it would fail exactly here.
    $place = Place::factory()->active()->create([
        'opening_hours_periods_json' => mondayLunchToLate(),
        'timezone' => 'America/Montevideo',
    ]);

    $this->travelTo('2026-09-07 18:00:00'); // Monday 15:00 local — open
    expect($this->getJson("/api/v1/places/{$place->slug}")->json('data.open_state.open_now'))->toBeTrue();

    $this->travelTo('2026-09-09 18:00:00'); // Wednesday — the week has no period
    expect($this->getJson("/api/v1/places/{$place->slug}")->json('data.open_state.open_now'))->toBeFalse();
});

// ------------------------------------------------- the timezone half's source

it('resolves and stores a timezone for a place that has none', function () {
    $place = Place::factory()->active()->create(['timezone' => null]);
    $resolver = fakeTimezoneResolver('America/Montevideo');

    expect((new TimezoneBusinessSource($resolver))->enrich($place))
        ->toBe(['timezone' => 'America/Montevideo']);
    expect($resolver->calls)->toBe(1);
});

it('does not re-bill the lookup for a place that already has one', function () {
    // A separately billed API call, and a restaurant does not move.
    $place = Place::factory()->active()->create(['timezone' => 'America/Montevideo']);
    $resolver = fakeTimezoneResolver('Europe/Madrid');

    expect((new TimezoneBusinessSource($resolver))->enrich($place))->toBe([]);
    expect($resolver->calls)->toBe(0);
});

it('contributes nothing — rather than a guess — when the resolver cannot answer', function () {
    $place = Place::factory()->active()->create(['timezone' => null]);

    expect((new TimezoneBusinessSource(fakeTimezoneResolver(null)))->enrich($place))->toBe([]);
});

it('contributes nothing when the source is switched off', function () {
    config()->set('places.enrich.timezone.enabled', false);

    $place = Place::factory()->active()->create(['timezone' => null]);
    $resolver = fakeTimezoneResolver('America/Montevideo');

    expect((new TimezoneBusinessSource($resolver))->enrich($place))->toBe([]);
    expect($resolver->calls)->toBe(0);
});
