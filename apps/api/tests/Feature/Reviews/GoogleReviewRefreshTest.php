<?php

use App\Models\Place;
use App\Services\Geo\FakeGeocoder;
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

    expect($stale->fresh()->google_reviews_json)->toBeNull()
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
