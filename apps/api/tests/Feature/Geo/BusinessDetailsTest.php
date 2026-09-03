<?php

use App\Services\Geo\BusinessDetails;
use App\Services\Geo\GooglePlacesGeocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The opt-in, wider Google Place Details fetch behind the "enrich as business"
 * action (T-084). It uses a SEPARATE, more-billable field mask than the pipeline
 * geocode and is cached per place id.
 */
beforeEach(function () {
    config()->set('services.google_places.key', 'test-key');
    Cache::flush();
    Http::preventStrayRequests();
});

it('maps the wider Place Details response into BusinessDetails', function () {
    Http::fake([
        '*/details/json*' => Http::response([
            'status' => 'OK',
            'result' => [
                'international_phone_number' => '+351 21 000 0000',
                'formatted_phone_number' => '21 000 0000',
                'website' => 'https://joes.example.com/',
                'opening_hours' => ['weekday_text' => ['Monday: 9–17', 'Tuesday: 9–17']],
                'rating' => 4.5,
                'user_ratings_total' => 321,
            ],
        ]),
    ]);

    $details = (new GooglePlacesGeocoder)->businessDetails('gp_1');

    expect($details)->toBeInstanceOf(BusinessDetails::class)
        ->and($details->phone)->toBe('+351 21 000 0000') // international preferred
        ->and($details->website)->toBe('https://joes.example.com/')
        ->and($details->openingHours)->toBe(['Monday: 9–17', 'Tuesday: 9–17'])
        ->and($details->rating)->toBe(4.5)
        ->and($details->ratingCount)->toBe(321);

    // The patch only carries the writable curated fields (not rating/count).
    expect($details->toPlacePatch())->toBe([
        'phone' => '+351 21 000 0000',
        'website' => 'https://joes.example.com/',
        'opening_hours_json' => ['Monday: 9–17', 'Tuesday: 9–17'],
    ]);

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'international_phone_number')
        && str_contains($r->url(), 'opening_hours'));
});

it('carries the structured periods through the CACHE, not just the first fetch', function () {
    // The seam that shipped twice. Google's `periods[]` is normalized on the way
    // in, and `toArray()` caches THAT — our shape, not Google's. Rehydrating it
    // with the provider parser reads every entry as malformed and yields null, so
    // `toPlacePatch()` drops the key and the column is never written for any
    // place, with enrichment reporting success the whole time.
    //
    // `Cache::remember` returns the closure's value on a MISS too, so the second
    // call below is the one that goes through `fromArray()` — which is why a test
    // that only fetches once cannot see this.
    Http::fake([
        '*/details/json*' => Http::response([
            'status' => 'OK',
            'result' => [
                'opening_hours' => [
                    'weekday_text' => ['Monday: 9–17'],
                    'periods' => [
                        ['open' => ['day' => 1, 'time' => '0900'], 'close' => ['day' => 1, 'time' => '1700']],
                    ],
                ],
            ],
        ]),
    ]);

    $expected = [['open_day' => 1, 'open_time' => '09:00', 'close_day' => 1, 'close_time' => '17:00']];

    $first = (new GooglePlacesGeocoder)->businessDetails('gp_periods');
    expect($first->openingHoursPeriods)->toBe($expected);

    // Second call: served from cache, so this one exercises fromArray().
    $cached = (new GooglePlacesGeocoder)->businessDetails('gp_periods');
    expect($cached->openingHoursPeriods)->toBe($expected);
    expect($cached->toPlacePatch())->toHaveKey('opening_hours_periods_json');

    Http::assertSentCount(1);
});

it('drops a cached period list that has been corrupted, rather than truncating it', function () {
    // All-or-nothing on the rehydrate: a shorter-but-non-empty week would still
    // win BusinessEnricher's first-non-empty merge and silently delete a service.
    $details = BusinessDetails::fromArray(['opening_hours_periods' => [
        ['open_day' => 1, 'open_time' => '09:00', 'close_day' => 1, 'close_time' => '17:00'],
        ['open_day' => 'nope', 'open_time' => '09:00', 'close_day' => 1, 'close_time' => '17:00'],
    ]]);

    expect($details->openingHoursPeriods)->toBeNull();
});

it('returns null without an API key', function () {
    config()->set('services.google_places.key', '');

    expect((new GooglePlacesGeocoder)->businessDetails('gp_1'))->toBeNull();
    Http::assertNothingSent();
});

it('caches by place id so a second call makes no request', function () {
    Http::fake(['*/details/json*' => Http::response(['status' => 'OK', 'result' => ['website' => 'https://x.example']])]);

    $geocoder = new GooglePlacesGeocoder;
    $geocoder->businessDetails('gp_cache');
    $geocoder->businessDetails('gp_cache');

    Http::assertSentCount(1);
});

it('returns null on a NOT_FOUND place', function () {
    Http::fake(['*/details/json*' => Http::response(['status' => 'NOT_FOUND'])]);

    expect((new GooglePlacesGeocoder)->businessDetails('gp_missing'))->toBeNull();
});

it('resolves Google photos into a key-free gallery with attribution (T-099)', function () {
    // Photos ride the same Details call; each photo_reference is resolved to its
    // 302 redirect target (a key-free googleusercontent URL) — we read the
    // Location header, never the image bytes. html_attributions → plain text.
    Http::fake([
        '*/details/json*' => Http::response(['status' => 'OK', 'result' => [
            'website' => 'https://joes.example.com/',
            'photos' => [
                ['photo_reference' => 'ref1', 'html_attributions' => ['<a href="https://x/y">Joe&#39;s Diner</a>']],
                ['photo_reference' => 'ref2', 'html_attributions' => []],
            ],
        ]]),
        '*/place/photo*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://lh3.googleusercontent.com/p1'])
            ->push('', 302, ['Location' => 'https://lh3.googleusercontent.com/p2']),
    ]);

    $details = (new GooglePlacesGeocoder)->businessDetails('gp_photos');

    expect($details->images)->toBe([
        ['url' => 'https://lh3.googleusercontent.com/p1', 'attribution' => "Joe's Diner"],
        ['url' => 'https://lh3.googleusercontent.com/p2', 'attribution' => null],
    ]);

    // The wider mask now requests photos; the request never carries a stray key.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'details/json') && str_contains($r->url(), 'photos'));
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/place/photo') && str_contains($r->url(), 'photo_reference=ref1'));
});

it('drops a Google photo whose redirect target carries a key or is not a redirect', function () {
    // Defence-in-depth: an API key must never reach a stored/served URL, and a
    // non-3xx photo response has no clean target — both are dropped, best-effort.
    Http::fake([
        '*/details/json*' => Http::response(['status' => 'OK', 'result' => ['photos' => [
            ['photo_reference' => 'ref1'],
            ['photo_reference' => 'ref2'],
        ]]]),
        '*/place/photo*' => Http::sequence()
            ->push('', 302, ['Location' => 'https://evil.example/i?key=SECRET']) // still keyed → drop
            ->push('', 200), // not a redirect → drop
    ]);

    $details = (new GooglePlacesGeocoder)->businessDetails('gp_badphotos');

    expect($details->images)->toBe([]);
});

/**
 * THE TWO GEO CALL SITES (T-128 review). The fixture above is a clean list of
 * strings, which the old `array_values($weekdayText)` produced identically — so
 * it passes with the coercion reverted and proves nothing about it. These do
 * not: each one hands the call site something the contract's `string[]` forbids.
 */
it('voids Google weekday_text entirely when one line is not a string — never a truncated week', function () {
    // Google is a third party; `weekday_text` is not schema-checked on the wire.
    // ALL-OR-NOTHING is the whole point: a filtered `['Monday: 9–17']` would be
    // non-empty, so it would win BusinessEnricher's first-non-empty merge and
    // overwrite a place's good hours with one day of the week.
    Http::fake([
        '*/details/json*' => Http::response(['status' => 'OK', 'result' => [
            'opening_hours' => ['weekday_text' => ['Monday: 9–17', ['open' => '09:00'], 'Wednesday: 9–17']],
        ]]),
    ]);

    $details = (new GooglePlacesGeocoder)->businessDetails('gp_partial');

    expect($details->openingHours)->toBeNull();
    expect($details->openingHours)->not->toBe(['Monday: 9–17', 'Wednesday: 9–17']);
    // …and with nothing to say, the patch carries no hours at all, so the
    // enricher leaves whatever the place already had alone.
    expect($details->toPlacePatch())->not->toHaveKey('opening_hours_json');
});

it('drops Google’s `{periods, weekday_text}` object when it arrives whole', function () {
    // The shape the contract forbids and the mobile client used to look for.
    // Stored raw, this serves a JSON object where `string[]` is promised.
    Http::fake([
        '*/details/json*' => Http::response(['status' => 'OK', 'result' => [
            'opening_hours' => ['periods' => [['open' => ['day' => 1, 'time' => '0900']]], 'weekday_text' => 'Monday: 9–17'],
        ]]),
    ]);

    // `weekday_text` is a bare STRING here, not a list — a non-array voids it.
    expect((new GooglePlacesGeocoder)->businessDetails('gp_object')->openingHours)->toBeNull();
});

/**
 * `fromArray()` rehydrates a CACHED payload — the ONE path whose entire
 * justification is a value written by an older, laxer writer, and the one
 * nothing exercised. The cache lives 14 days, so a regression here is invisible
 * until a stale entry misbehaves in production; this is the only evidence it
 * normalizes at all.
 */
it('normalizes a legacy CACHED payload on rehydrate, not just a fresh fetch', function () {
    // A list shape no current writer produces, but a 14-day-old cache entry can.
    expect(BusinessDetails::fromArray(['opening_hours' => ['Monday: 9–17', 42]])->openingHours)
        ->toBeNull();
    expect(BusinessDetails::fromArray(['opening_hours' => ['monday' => ['9', '17']]])->openingHours)
        ->toBeNull();
    expect(BusinessDetails::fromArray(['opening_hours' => 'Monday: 9–17'])->openingHours)
        ->toBeNull();
    expect(BusinessDetails::fromArray(['opening_hours' => []])->openingHours)->toBeNull();
    expect(BusinessDetails::fromArray([])->openingHours)->toBeNull();

    // A good cached value still round-trips untouched (trimmed).
    expect(BusinessDetails::fromArray(['opening_hours' => [' Monday: 9–17 ', 'Tuesday: Closed']])->openingHours)
        ->toBe(['Monday: 9–17', 'Tuesday: Closed']);
});

it('rehydrates a cached payload through fromArray on the SECOND businessDetails call', function () {
    // Proves the rehydrate path is the one the cache actually uses: the second
    // call makes no request and still comes back normalized.
    Http::fake([
        '*/details/json*' => Http::response(['status' => 'OK', 'result' => [
            'opening_hours' => ['weekday_text' => ['Monday: 9–17', 'Tuesday: Closed']],
        ]]),
    ]);

    $geocoder = new GooglePlacesGeocoder;
    expect($geocoder->businessDetails('gp_rehydrate')->openingHours)->toBe(['Monday: 9–17', 'Tuesday: Closed']);
    expect($geocoder->businessDetails('gp_rehydrate')->openingHours)->toBe(['Monday: 9–17', 'Tuesday: Closed']);
    Http::assertSentCount(1);
});
