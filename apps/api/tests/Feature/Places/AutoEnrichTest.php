<?php

use App\Jobs\EnrichPlace;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Services\Geo\FakeGeocoder;
use App\Services\Places\Enrichment\BusinessEnricher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Auto-enrichment on first publish (T-099 follow-up): a shared place gets its
 * business data + photo gallery without a manual admin action, via a queued
 * EnrichPlace job — idempotent so a re-share never re-bills external sources.
 */
beforeEach(function () {
    Cache::flush();
    config([
        'places.enrich.website.verify_host' => false,
        'reviews.sources.google.enabled' => false,
        'reviews.sources.trustpilot.enabled' => false,
    ]);
});

function galleryJsonLd(): string
{
    $ld = json_encode([
        '@type' => 'Restaurant',
        'image' => ['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg'],
    ], JSON_THROW_ON_ERROR);

    return '<script type="application/ld+json">'.$ld.'</script>';
}

// --- The job ---

it('runs enrichment and stamps enriched_at', function () {
    $place = Place::factory()->create(['website' => 'https://joes.example.com', 'image_url' => null, 'enriched_at' => null]);
    Http::fake(['*' => Http::response(galleryJsonLd())]);

    (new EnrichPlace($place->id))->handle(app(BusinessEnricher::class));
    $place->refresh();

    expect($place->enriched_at)->not->toBeNull()
        ->and($place->gallery_json)->toHaveCount(2)
        ->and($place->image_url)->toBe('https://cdn.example.com/1.jpg');
});

it('skips a place that is already enriched (idempotent — no re-billing)', function () {
    $place = Place::factory()->create([
        'website' => 'https://joes.example.com',
        'enriched_at' => now()->subDay(),
        'gallery_json' => [],
    ]);
    Http::fake(); // nothing should leave the job

    (new EnrichPlace($place->id))->handle(app(BusinessEnricher::class));

    Http::assertNothingSent();
    expect($place->refresh()->gallery_json)->toBe([]);
});

it('re-enriches when forced even if already enriched', function () {
    $place = Place::factory()->create(['website' => 'https://joes.example.com', 'enriched_at' => now()->subDay()]);
    Http::fake(['*' => Http::response(galleryJsonLd())]);

    (new EnrichPlace($place->id, force: true))->handle(app(BusinessEnricher::class));

    expect($place->refresh()->gallery_json)->toHaveCount(2);
});

it('no-ops when the place was deleted before the job ran', function () {
    Http::fake();

    (new EnrichPlace(999999))->handle(app(BusinessEnricher::class));

    Http::assertNothingSent();
});

// --- Auto-dispatch on publish ---

it('auto-dispatches EnrichPlace when a place is first published', function () {
    config(['places.enrich.auto' => true]);
    Bus::fake([EnrichPlace::class]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJauto', 51.5, -0.13)));

    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();
    (new PublishShare($share->id))->handle();

    $place = Place::sole();
    Bus::assertDispatched(EnrichPlace::class, fn (EnrichPlace $job): bool => $job->placeId === $place->id && $job->force === false);
});

it('does not re-dispatch enrichment when the resolved place is already enriched', function () {
    config(['places.enrich.auto' => true]);
    Bus::fake([EnrichPlace::class]);

    // An existing, already-enriched place that the share will dedup onto (exact
    // google_place_id match is the resolver's primary dedup key).
    $existing = Place::factory()->active()->atPoint(51.5, -0.13)->create([
        'name' => 'Lanzhou Beef Noodle House',
        'google_place_id' => 'ChIJexisting',
        'enriched_at' => now()->subDay(),
    ]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJexisting', 51.5, -0.13)));

    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();
    (new PublishShare($share->id))->handle();

    // The new source attached to the existing enriched place → no re-enrich.
    expect(Place::whereKey($existing->id)->value('enriched_at'))->not->toBeNull();
    Bus::assertNotDispatched(EnrichPlace::class);
});

it('does not auto-dispatch enrichment when the feature is disabled', function () {
    config(['places.enrich.auto' => false]);
    Bus::fake([EnrichPlace::class]);
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJoff', 51.5, -0.13)));

    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();
    (new PublishShare($share->id))->handle();

    Bus::assertNotDispatched(EnrichPlace::class);
});
