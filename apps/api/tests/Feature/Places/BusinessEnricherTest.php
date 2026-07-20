<?php

use App\Models\Place;
use App\Models\PlaceEdit;
use App\Services\Geo\BusinessDetailProvider;
use App\Services\Geo\BusinessDetails;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;
use App\Services\Places\Enrichment\BusinessEnricher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The "enrich as business" pipeline (T-084): Google (contact/hours) + the
 * business website (image/cuisine/address) + a review-cache refresh, merged and
 * applied through PlaceEditor. Never throws; a manual override always survives.
 */
beforeEach(function () {
    Cache::flush();
    // No network for the SSRF host check; refreshing reviews stays out of the way.
    config([
        'places.enrich.website.verify_host' => false,
        'reviews.sources.google.enabled' => false,
        'reviews.sources.trustpilot.enabled' => false,
    ]);
});

function restaurantJsonLd(): string
{
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Restaurant',
        'name' => "Joe's",
        'servesCuisine' => ['Italian', 'Pizza'],
        'image' => 'https://cdn.example.com/joes.jpg',
        'telephone' => '+351 99 999 9999',
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => 'Rua 1',
            'addressLocality' => 'Lisboa',
            'addressRegion' => 'Lisboa',
            'postalCode' => '1100-000',
            'addressCountry' => 'PT',
        ],
        'openingHours' => ['Mo-Su 12:00-23:00'],
    ], JSON_THROW_ON_ERROR);

    return '<html><head><script type="application/ld+json">'.$ld.'</script></head><body>Joe\'s</body></html>';
}

it('populates curated fields from Google and the website, respecting source priority', function () {
    $place = Place::factory()->withGooglePlaceId('gp_1')->create([
        'website' => 'https://joes.example.com',
        'phone' => null,
        'cuisine_primary' => null,
        'image_url' => null,
        'address_line1' => null,
    ]);

    $geo = (new FakeGeocoder)->seedBusinessDetails('gp_1', new BusinessDetails(
        phone: '+351 21 000 0000',
        openingHours: ['Mo-Fr 09:00–17:00'],
    ));
    bindGeocoder($geo);
    Http::fake(['*' => Http::response(restaurantJsonLd())]);

    $result = app(BusinessEnricher::class)->enrich($place);
    $place->refresh();

    // Google wins for contact/hours; the website fills the gaps it doesn't carry.
    expect($place->phone)->toBe('+351 21 000 0000')
        ->and($place->opening_hours_json)->toBe(['Mo-Fr 09:00–17:00'])
        ->and($place->cuisine_primary)->toBe('Italian')
        ->and($place->image_url)->toBe('https://cdn.example.com/joes.jpg')
        ->and($place->address_line1)->toBe('Rua 1')
        ->and($place->city)->toBe('Lisboa')
        ->and($place->country_code)->toBe('PT')
        ->and($place->enriched_at)->not->toBeNull();

    expect($result->changedFields())->toContain('phone', 'cuisine_primary', 'image_url');
    expect(PlaceEdit::query()->where('origin', PlaceEdit::ORIGIN_ENRICHMENT)->count())->toBe(1);
});

it('never throws when a source fails, and reports the failure', function () {
    $place = Place::factory()->withGooglePlaceId('gp_x')->create(['website' => null]);

    // A Google provider that blows up mid-fetch.
    bindGeocoder(new class implements BusinessDetailProvider, Geocoder
    {
        public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
        {
            return null;
        }

        public function businessDetails(string $googlePlaceId): ?BusinessDetails
        {
            throw new RuntimeException('google exploded');
        }
    });

    $result = app(BusinessEnricher::class)->enrich($place);

    expect($result->anyFailed())->toBeTrue()
        ->and($place->refresh()->enriched_at)->not->toBeNull(); // the run is still recorded
    $google = collect($result->sources)->firstWhere('source', 'google');
    expect($google['status'])->toBe('failed');
});

it('keeps a hand-locked field when enrichment would change it', function () {
    $place = Place::factory()->withGooglePlaceId('gp_2')->create(['phone' => '+34 600 000 000']);
    $place->lockFields(['phone']);
    $place->save();

    bindGeocoder((new FakeGeocoder)->seedBusinessDetails('gp_2', new BusinessDetails(phone: '+1 555 9999')));
    Http::fake(['*' => Http::response('<html></html>')]);

    app(BusinessEnricher::class)->enrich($place);

    expect($place->refresh()->phone)->toBe('+34 600 000 000'); // manual override wins
});

it('treats a private/SSRF website as a failed source without throwing', function () {
    config(['places.enrich.website.verify_host' => true]); // force the host check on
    Http::fake(); // record (nothing should be sent)
    $place = Place::factory()->create(['website' => 'http://169.254.169.254/latest/meta-data/']);

    $result = app(BusinessEnricher::class)->enrich($place);

    $website = collect($result->sources)->firstWhere('source', 'website');
    expect($website['status'])->toBe('failed');
    Http::assertNothingSent(); // never left the SSRF guard
});
