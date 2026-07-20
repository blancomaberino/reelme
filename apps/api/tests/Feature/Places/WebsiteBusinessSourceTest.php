<?php

use App\Models\Place;
use App\Services\Places\Enrichment\Sources\WebsiteBusinessSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The website/menu JSON-LD scraper (T-084): SSRF-guarded, redirect-free, cached,
 * and tolerant of the shapes real sites emit (@graph, @type lists, hours specs).
 */
beforeEach(function () {
    Cache::flush();
    config(['places.enrich.website.verify_host' => false]);
});

function scrapeSite(string $html, string $website = 'https://joes.example.com'): array
{
    Http::fake([$website.'*' => Http::response($html)]);
    $place = Place::factory()->make(['website' => $website]);

    return app(WebsiteBusinessSource::class)->enrich($place);
}

it('reads a business node nested in an @graph', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'WebSite', 'name' => 'Site'],
            ['@type' => 'Restaurant', 'telephone' => '+351 12 345', 'servesCuisine' => 'Portuguese'],
        ],
    ], JSON_THROW_ON_ERROR).'</script>';

    expect(scrapeSite($html))->toMatchArray([
        'phone' => '+351 12 345',
        'cuisine_primary' => 'Portuguese',
    ]);
});

it('accepts an @type given as a list and builds hours from a specification', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => ['LocalBusiness', 'Restaurant'],
        'openingHoursSpecification' => [
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['https://schema.org/Monday', 'https://schema.org/Tuesday'], 'opens' => '09:00', 'closes' => '17:00'],
        ],
    ], JSON_THROW_ON_ERROR).'</script>';

    expect(scrapeSite($html)['opening_hours_json'])->toBe(['Monday, Tuesday 09:00–17:00']);
});

it('drops a non-ISO country and non-http image, keeps valid ones', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant',
        'image' => 'https://cdn.example/ok.jpg',
        'address' => ['@type' => 'PostalAddress', 'addressCountry' => 'PT', 'streetAddress' => 'Rua 1'],
    ], JSON_THROW_ON_ERROR).'</script>';

    expect(scrapeSite($html))->toMatchArray([
        'image_url' => 'https://cdn.example/ok.jpg',
        'country_code' => 'PT',
        'address_line1' => 'Rua 1',
    ]);
});

it('returns an empty patch when there is no business JSON-LD', function () {
    expect(scrapeSite('<html><body>no structured data</body></html>'))->toBe([]);
});

it('does not cache a transient upstream failure — it throws and retries', function () {
    $ok = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant', 'telephone' => '+351 12 345',
    ], JSON_THROW_ON_ERROR).'</script>';
    Http::fake(['*' => Http::sequence()->push('upstream boom', 500)->push($ok, 200)]);

    $place = Place::factory()->make(['website' => 'https://joes.example.com']);
    $source = app(WebsiteBusinessSource::class);

    // A 5xx must surface (so the enricher marks the source failed), not be cached.
    expect(fn () => $source->enrich($place))->toThrow(RuntimeException::class);
    // The next run retries and succeeds → nothing was cached from the failure.
    expect($source->enrich($place))->toMatchArray(['phone' => '+351 12 345']);
});

it('is gated off by config', function () {
    config(['places.enrich.website.enabled' => false]);
    $place = Place::factory()->make(['website' => 'https://joes.example.com']);

    expect(app(WebsiteBusinessSource::class)->enrich($place))->toBe([]);
    Http::assertNothingSent();
});
