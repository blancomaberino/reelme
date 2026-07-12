<?php

use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('drops stale cached Google reviews when no refresh is possible', function () {
    $stale = Place::factory()->active()->atPoint(51.5, -0.13)->create([
        'google_rating' => 4.2,
        'google_reviews_json' => [['author' => 'Old', 'rating' => 4, 'text' => 'stale']],
        'google_reviews_synced_at' => now()->subDays(45),
    ]);
    $fresh = Place::factory()->active()->atPoint(51.6, -0.14)->create([
        'google_reviews_json' => [['author' => 'New', 'rating' => 5, 'text' => 'fresh']],
        'google_reviews_synced_at' => now()->subDays(2),
    ]);

    bindGeocoder(new FakeGeocoder); // nothing seeded → refresh impossible

    $this->artisan('reelmap:google:refresh-stale')->assertSuccessful();

    // The rating is Places content too — the whole cached signal drops.
    $stale->refresh();
    expect($stale->google_reviews_json)->toBeNull()
        ->and($stale->google_rating)->toBeNull()
        ->and($fresh->fresh()->google_reviews_json)->not->toBeNull();
});

it('refreshes stale content when the geocoder returns the same Google place', function () {
    $place = Place::factory()->active()->atPoint(51.5117, -0.13)
        ->withGooglePlaceId('ChIJrefresh')
        ->create([
            'name' => 'Lanzhou Beef Noodle House',
            'city' => 'London',
            'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'stale']],
            'google_reviews_synced_at' => now()->subDays(45),
        ]);

    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJrefresh', 51.5117, -0.13,
        rating: 4.6, ratingCount: 200,
        reviews: [['author' => 'Fresh', 'rating' => 5, 'text' => 'new content']],
    )));

    $this->artisan('reelmap:google:refresh-stale')->assertSuccessful();

    $place->refresh();
    expect($place->google_reviews_json[0]['author'])->toBe('Fresh')
        ->and((float) $place->google_rating)->toBe(4.6)
        ->and($place->google_reviews_synced_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('drops content when the geocoder resolves to a DIFFERENT Google place', function () {
    $place = Place::factory()->active()->atPoint(51.5117, -0.13)
        ->withGooglePlaceId('ChIJoriginal')
        ->create([
            'name' => 'Lanzhou Beef Noodle House',
            'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'stale']],
            'google_reviews_synced_at' => now()->subDays(45),
        ]);

    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJsomewhere-else', 51.5117, -0.13,
        rating: 4.0, ratingCount: 10,
        reviews: [['author' => 'Wrong place', 'rating' => 4, 'text' => 'not ours']],
    )));

    $this->artisan('reelmap:google:refresh-stale')->assertSuccessful();

    expect($place->fresh()->google_reviews_json)->toBeNull();
});

it('isolates per-row failures — one throwing place does not stop the sweep', function () {
    $bad = Place::factory()->active()->atPoint(51.5, -0.13)->create([
        'name' => 'Explodes',
        'google_reviews_json' => [['author' => 'Old', 'rating' => 4, 'text' => 'stale']],
        'google_reviews_synced_at' => now()->subDays(45),
    ]);
    $alsoStale = Place::factory()->active()->atPoint(51.6, -0.14)->create([
        'google_reviews_json' => [['author' => 'Old2', 'rating' => 3, 'text' => 'stale']],
        'google_reviews_synced_at' => now()->subDays(45),
    ]);

    bindGeocoder(new class implements Geocoder
    {
        public function findPlace(string $name, GeoHints $hints): ?GeocodeResult
        {
            if ($name === 'Explodes') {
                throw new RuntimeException('quota exceeded');
            }

            return null;
        }
    });

    $this->artisan('reelmap:google:refresh-stale')->assertSuccessful();

    expect($alsoStale->fresh()->google_reviews_json)->toBeNull() // later row still swept
        ->and($bad->fresh()->google_reviews_json)->not->toBeNull(); // failed row left intact
});

it('honours --days for the staleness cutoff', function () {
    $twoDaysOld = Place::factory()->active()->atPoint(51.7, -0.15)->create([
        'google_reviews_json' => [['author' => 'A', 'rating' => 4, 'text' => 'x']],
        'google_reviews_synced_at' => now()->subDays(2),
    ]);
    bindGeocoder(new FakeGeocoder);

    $this->artisan('reelmap:google:refresh-stale', ['--days' => 1])->assertSuccessful();
    expect($twoDaysOld->fresh()->google_reviews_json)->toBeNull();
});

it('never marks a rating-only place (no snippets) as stale cached content', function () {
    // Resolver stores NULL (not []) when Google returned a rating but no
    // review snippets — the sweep must leave such places alone.
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult(
        'ChIJratingonly', 51.5, -0.13, rating: 4.4, ratingCount: 50, reviews: [],
    )));
    $share = analyzingShare();
    (new ResolvePlace($share->id))->handle();

    $place = Place::sole();
    expect($place->google_reviews_json)->toBeNull()
        ->and((float) $place->google_rating)->toBe(4.4);

    bindGeocoder(new FakeGeocoder);
    $this->artisan('reelmap:google:refresh-stale')->assertSuccessful();

    expect((float) $place->fresh()->google_rating)->toBe(4.4); // rating survives
});
