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
