<?php

use App\Models\Place;
use App\Services\Geo\TimezoneResolver;
use App\Services\Places\Enrichment\Sources\TimezoneBusinessSource;
use App\Services\Places\PlaceEditor;

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

// ------------------------------------------------------ hours in the reader's language

it('serves the hours in the language the reader asked for', function () {
    // The bug the owner reported from a screenshot: a voseo-Spanish app showing
    // "Monday: Closed". Same row, same request, two readers — this is the
    // assertion T-168 exists for.
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: 12:00 – 4:00 PM'],
        'opening_hours_periods_json' => mondayLunchToLate(),
    ]);

    $es = $this->withHeader('Accept-Language', 'es')
        ->getJson("/api/v1/places/{$place->slug}")->json('data.opening_hours');
    $en = $this->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/places/{$place->slug}")->json('data.opening_hours');

    expect($es[0])->toBe('Lunes: 11:00 – 23:00');
    expect($en)->toContain('Monday: 11:00 AM – 11:00 PM');
    // Spanish starts the week on Monday, English on Sunday.
    expect($en[0])->toStartWith('Sunday');
    // And the source's English prose is nowhere in the Spanish answer.
    expect(implode(' ', $es))->not->toContain('Closed');
});

it('still renders the source’s prose VERBATIM when there are no structured periods', function () {
    // The other half of the pair, and the T-128 rule that does not go away: with
    // nothing structured to render from, the lines are the source's own words, in
    // the source's language and day order, untouched. Translating them would mean
    // parsing them, which is the defect T-128 removed.
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: Closed', 'Tuesday: 12:00 – 4:00 PM'],
        'opening_hours_periods_json' => null,
    ]);

    expect($this->withHeader('Accept-Language', 'es')
        ->getJson("/api/v1/places/{$place->slug}")->json('data.opening_hours'))
        ->toBe(['Monday: Closed', 'Tuesday: 12:00 – 4:00 PM']);
});

it('lets a curator’s hand-typed hours win over the generated ones', function () {
    // Nothing curated can write the PERIODS: Filament edits the lines, the
    // suggest-an-edit request allows only the lines, and enrichment is the sole
    // writer of periods. So without this, a moderator correcting "closes 22:00,
    // not 23:00" would save, lock the column — and every reader would go on
    // seeing the generated line from Google's stale week. The correction would be
    // invisible, and permanent, since the lock then stops enrichment refreshing
    // anything. Users sent to a closed door, moderation with no visible effect.
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Lunes: cerramos 22:00 (corregido a mano)'],
        'opening_hours_periods_json' => mondayLunchToLate(),
    ]);
    $place->lockFields(['opening_hours_json']);
    $place->save();

    expect($this->withHeader('Accept-Language', 'es')
        ->getJson("/api/v1/places/{$place->slug}")->json('data.opening_hours'))
        ->toBe(['Lunes: cerramos 22:00 (corregido a mano)']);
});

it('generates again once the hours are unlocked', function () {
    // The lock is the whole condition — an unlocked place with periods still gets
    // localized lines, so the guard narrows nothing it should not.
    $place = Place::factory()->active()->create([
        'opening_hours_json' => ['Monday: 12:00 – 4:00 PM'],
        'opening_hours_periods_json' => mondayLunchToLate(),
    ]);

    expect($this->withHeader('Accept-Language', 'es')
        ->getJson("/api/v1/places/{$place->slug}")->json('data.opening_hours.0'))
        ->toBe('Lunes: 11:00 – 23:00');
});

// ------------------------------------------------- the timezone half's source

it('resolves a timezone for a place that has none', function () {
    $place = Place::factory()->active()->create(['timezone' => null]);
    $resolver = fakeTimezoneResolver('America/Montevideo');

    expect((new TimezoneBusinessSource($resolver))->enrich($place))
        ->toBe(['timezone' => 'America/Montevideo']);
    expect($resolver->calls)->toBe(1);
});

it('PERSISTS both columns through the real enricher, not just returns them', function () {
    // The test this file was missing, and the reason a blocking defect shipped
    // green: every other case here writes the columns with the factory, which
    // is mass assignment and bypasses the only path that writes them for real.
    // `PlaceEditor::apply()` filters every patch to `Place::CURATED_FIELDS`, so
    // omitting the two new names there made them unwritable in production while
    // the whole suite stayed green — enrichment ran, reported success, stamped
    // `enriched_at`, and silently discarded both values.
    $place = Place::factory()->active()->create(['timezone' => null]);

    app(PlaceEditor::class)->apply($place, [
        'timezone' => 'America/Montevideo',
        'opening_hours_periods_json' => mondayLunchToLate(),
    ], 'enrichment');

    $fresh = $place->fresh();
    expect($fresh->timezone)->toBe('America/Montevideo');
    // `toEqual`, not `toBe`: the column is jsonb, and Postgres stores object keys
    // sorted by length then bytewise rather than as written — so a round-trip
    // returns open_day, close_day, open_time, close_time. The CONTENT is what the
    // contract pins; key order is the database's business. (An identity assertion
    // here fails for a reason that has nothing to do with the code, which is how
    // someone ends up "fixing" it by reordering the writer.)
    expect($fresh->opening_hours_periods_json)->toEqual(mondayLunchToLate());
});

it('normalizes a malformed period list on the way through the editor', function () {
    // The write path is also the normalization point: whatever reaches the
    // column is the contract shape or null, never a caller's raw payload.
    $place = Place::factory()->active()->create();

    app(PlaceEditor::class)->apply($place, [
        'opening_hours_periods_json' => [['open_day' => 99, 'open_time' => 'nope']],
    ], 'enrichment');

    expect($place->fresh()->opening_hours_periods_json)->toBeNull();
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
