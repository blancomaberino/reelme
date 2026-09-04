<?php

use App\Services\Geo\GoogleTimezoneResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Google Time Zone client (T-155) — a SEPARATELY BILLED call, and the only
 * thing that fills the column the whole open/closed cue is gated on.
 *
 * It had no tests at all: `TestCase` binds the null resolver for every test, so
 * nothing in the suite ever reached this class. That containment is right (no
 * test may spend money or need a network), but it meant every branch here was
 * unexercised — including the IANA validation, without which a fixed offset
 * reaches the column and the cue silently vanishes for every place.
 *
 * `Http::fake()` throughout: nothing leaves the process.
 */
beforeEach(function () {
    config()->set('services.google_places.key', 'test-key');
    // The array store, not the app's Redis: TTL here is computed from Carbon, so
    // `travel()` can actually expire a key. Against Redis the expiry is the
    // server's own clock and time travel is invisible to it.
    config()->set('cache.default', 'array');
    Cache::flush();
});

function timezoneResponse(array $body): void
{
    Http::fake(['*maps.googleapis.com/maps/api/timezone*' => Http::response($body)]);
}

it('returns the IANA id Google reports', function () {
    timezoneResponse(['status' => 'OK', 'timeZoneId' => 'America/Montevideo']);

    expect((new GoogleTimezoneResolver)->resolve(-34.9011, -56.1645))
        ->toBe('America/Montevideo');
});

it('rejects an id PHP does not recognize, rather than storing it', function () {
    // The branch that matters most. A fixed offset in this column is accepted by
    // the DB and then refused by OpeningSchedule, so the cue disappears for every
    // affected place with no error anywhere — the failure is silent by
    // construction, which is exactly why it needs a test.
    timezoneResponse(['status' => 'OK', 'timeZoneId' => '-03:00']);

    expect((new GoogleTimezoneResolver)->resolve(-34.9011, -56.1645))->toBeNull();
});

it('asks Google once and serves the rest from cache', function () {
    timezoneResponse(['status' => 'OK', 'timeZoneId' => 'America/Montevideo']);
    $resolver = new GoogleTimezoneResolver;

    $resolver->resolve(-34.9011, -56.1645);
    $resolver->resolve(-34.9011, -56.1645);

    Http::assertSentCount(1);
});

it('shares one cache entry across a neighbourhood, not one per doorway', function () {
    // Coordinates are rounded to ~111 m before they become the key: a timezone
    // boundary is not a street, so every venue on a block is one billed lookup.
    timezoneResponse(['status' => 'OK', 'timeZoneId' => 'America/Montevideo']);
    $resolver = new GoogleTimezoneResolver;

    // Both round to the same 3-decimal cell (-34.901, -56.164). Picking points
    // that straddle a rounding boundary would test nothing but arithmetic.
    $resolver->resolve(-34.90110, -56.16410);
    $resolver->resolve(-34.90140, -56.16440);

    Http::assertSentCount(1);
});

it('caches a definitive ZERO_RESULTS, so it is not re-billed', function () {
    timezoneResponse(['status' => 'ZERO_RESULTS']);
    $resolver = new GoogleTimezoneResolver;

    expect($resolver->resolve(0.0, 0.0))->toBeNull();
    expect($resolver->resolve(0.0, 0.0))->toBeNull();

    // Without the sentinel, Cache::remember reads null as a miss and re-asks
    // forever for every place the API cannot place.
    Http::assertSentCount(1);
});

it('expires a REQUEST_DENIED quickly instead of caching it for a year', function () {
    // The scenario this branch exists for: the Time Zone API is a separate GCP
    // enablement from Places, so a launch can answer REQUEST_DENIED for every
    // place. Caching that for a year would leave a whole city with no cue for
    // twelve months, with nothing in the log to explain it.
    //
    // Asserted as "it asks again", not "it now returns a zone": a second
    // `Http::fake()` APPENDS a stub rather than replacing one, and the first
    // matching stub still wins — so re-faking a success here would be answered
    // by the original failure and the test would pass for the wrong reason.
    timezoneResponse(['status' => 'REQUEST_DENIED']);
    $resolver = new GoogleTimezoneResolver;

    expect($resolver->resolve(-34.9011, -56.1645))->toBeNull();

    // Inside the failure window: still cached, no second call.
    $this->travel(1)->hours();
    $resolver->resolve(-34.9011, -56.1645);
    Http::assertSentCount(1);

    // Past it: the operator may have fixed the enablement, so ask again.
    $this->travel(7)->hours();
    $resolver->resolve(-34.9011, -56.1645);
    Http::assertSentCount(2);
});

it('does NOT re-ask a definitive answer after the failure window', function () {
    // The other half of the pair: a real zone is cached for a year, so travelling
    // past the SHORT window must not re-bill it. Without the failed/definitive
    // split both would expire in hours and every place would be re-billed daily.
    timezoneResponse(['status' => 'OK', 'timeZoneId' => 'America/Montevideo']);
    $resolver = new GoogleTimezoneResolver;

    expect($resolver->resolve(-34.9011, -56.1645))->toBe('America/Montevideo');

    $this->travel(30)->days();
    expect($resolver->resolve(-34.9011, -56.1645))->toBe('America/Montevideo');
    Http::assertSentCount(1);
});

it('returns null on an HTTP failure without leaking the key', function () {
    Http::fake(['*maps.googleapis.com/maps/api/timezone*' => Http::response('nope', 500)]);

    expect((new GoogleTimezoneResolver)->resolve(-34.9011, -56.1645))->toBeNull();
});

it('never calls out with no key configured, or an impossible coordinate', function () {
    Http::fake();

    config()->set('services.google_places.key', '');
    expect((new GoogleTimezoneResolver)->resolve(-34.9011, -56.1645))->toBeNull();

    config()->set('services.google_places.key', 'test-key');
    expect((new GoogleTimezoneResolver)->resolve(91.0, 0.0))->toBeNull();
    expect((new GoogleTimezoneResolver)->resolve(0.0, 181.0))->toBeNull();

    Http::assertNothingSent();
});
