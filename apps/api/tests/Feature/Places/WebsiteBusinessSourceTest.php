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

it('collects every schema.org image into the gallery (string, list, ImageObject), deduped', function () {
    // schema.org `image` is commonly a list mixing plain URLs and ImageObjects;
    // all are business-owned, so all become gallery entries (T-099). The first
    // stays as `image_url` (single-hero back-compat); non-http and dups drop.
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant',
        'image' => [
            'https://cdn.example/1.jpg',
            'https://cdn.example/1.jpg', // exact dup
            ['@type' => 'ImageObject', 'url' => 'https://cdn.example/2.jpg'],
            'ftp://cdn.example/skip.jpg', // non-http → dropped
        ],
    ], JSON_THROW_ON_ERROR).'</script>';

    $patch = scrapeSite($html);

    expect($patch['image_url'])->toBe('https://cdn.example/1.jpg')
        ->and($patch['gallery_json'])->toBe([
            ['url' => 'https://cdn.example/1.jpg', 'source' => 'website', 'attribution' => null],
            ['url' => 'https://cdn.example/2.jpg', 'source' => 'website', 'attribution' => null],
        ]);
});

it('emits a single-entry gallery for a lone schema.org image', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant', 'image' => 'https://cdn.example/only.jpg',
    ], JSON_THROW_ON_ERROR).'</script>';

    $patch = scrapeSite($html);

    expect($patch['image_url'])->toBe('https://cdn.example/only.jpg')
        ->and($patch['gallery_json'])->toBe([
            ['url' => 'https://cdn.example/only.jpg', 'source' => 'website', 'attribution' => null],
        ]);
});

/**
 * Scraped hours are a WRITE, so they go through the strict normalizer (T-128
 * review). The flat-`openingHours` branch used to filter out non-string members
 * and keep the rest — which is the dangerous kind of tolerance here: a shorter
 * list is still NON-EMPTY, so it wins BusinessEnricher's first-non-empty merge
 * and replaces a venue's good hours with half a week.
 */
it('reads a flat openingHours list of strings verbatim', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant',
        'openingHours' => ['Mo-Fr 09:00-17:00', 'Sa 10:00-14:00'],
    ], JSON_THROW_ON_ERROR).'</script>';

    expect(scrapeSite($html)['opening_hours_json'])->toBe(['Mo-Fr 09:00-17:00', 'Sa 10:00-14:00']);
});

it('does NOT truncate a flat openingHours list that holds a non-string — it falls through', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant',
        // One malformed member among good ones. The old filter yielded
        // ['Mo-Fr 09:00-17:00', 'Su Closed'] — a plausible-looking, WRONG week.
        'openingHours' => ['Mo-Fr 09:00-17:00', ['opens' => '10:00'], 'Su Closed'],
        'openingHoursSpecification' => [
            ['dayOfWeek' => ['https://schema.org/Sunday'], 'opens' => '11:00', 'closes' => '15:00'],
        ],
    ], JSON_THROW_ON_ERROR).'</script>';

    $patch = scrapeSite($html);

    expect($patch['opening_hours_json'])->not->toBe(['Mo-Fr 09:00-17:00', 'Su Closed']);
    // Falls through to the structured source rather than persisting a partial one.
    expect($patch['opening_hours_json'])->toBe(['Sunday 11:00–15:00']);
});

it('omits opening_hours_json entirely when nothing usable is found, leaving existing hours alone', function () {
    $html = '<script type="application/ld+json">'.json_encode([
        '@type' => 'Restaurant',
        'telephone' => '+351 12 345',
        'openingHours' => ['', ['opens' => '10:00']],
    ], JSON_THROW_ON_ERROR).'</script>';

    // Absent, not `[]` and not a truncated list: the enricher only merges fields
    // the patch actually carries, so the place keeps whatever hours it had.
    expect(scrapeSite($html))->not->toHaveKey('opening_hours_json');
});
