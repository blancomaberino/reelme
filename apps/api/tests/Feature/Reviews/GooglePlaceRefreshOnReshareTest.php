<?php

use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Places\GooglePlaceRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| T-080 — refreshing a known place's stale Google data on the re-share path,
| plus the shared GooglePlaceRefresher the daily sweep and this path both use.
*/

function reshareGeo(string $gpid, float $rating, array $reviews): GeocodeResult
{
    return geoResult($gpid, 51.5, -0.13, rating: $rating, ratingCount: 300, reviews: $reviews);
}

it('refreshes a known place\'s stale Google reviews when it is re-shared', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', reshareGeo(
        'ChIJreshare', 4.6, [['author' => 'Fresh', 'rating' => 5, 'text' => 'brand new']],
    )));

    // First share creates the place with a google_place_id.
    (new ResolvePlace(analyzingShare()->id))->handle();
    $place = Place::sole();

    // Age its cached Google content past the ToS window.
    $place->forceFill([
        'google_rating' => '3.0',
        'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'stale']],
        'google_reviews_synced_at' => now()->subDays(45),
    ])->save();

    // Re-share the same venue → attaches to the existing place AND refreshes it.
    (new ResolvePlace(analyzingShare()->id))->handle();

    $place->refresh();
    expect(Place::count())->toBe(1)
        ->and($place->google_reviews_json[0]['author'])->toBe('Fresh')
        ->and((float) $place->google_rating)->toBe(4.6)
        ->and($place->google_reviews_synced_at->isAfter(now()->subMinute()))->toBeTrue();
});

it('leaves a freshly-synced place untouched on re-share (no extra fetch, no re-stamp)', function () {
    $geocoder = (new FakeGeocoder)->seed('Lanzhou Beef Noodle House', reshareGeo(
        'ChIJfresh', 4.6, [['author' => 'Fresh', 'rating' => 5, 'text' => 'new']],
    ));
    bindGeocoder($geocoder);

    (new ResolvePlace(analyzingShare()->id))->handle();
    $place = Place::sole();
    $syncedAt = $place->google_reviews_synced_at;

    // Re-share while still fresh: attaches, but the Google signal is not restamped.
    (new ResolvePlace(analyzingShare()->id))->handle();

    $place->refresh();
    expect($place->google_reviews_synced_at->equalTo($syncedAt))->toBeTrue()
        // One geocode per resolve (dedup) — the refresh reuses that result, it
        // never makes a second, separate Google call.
        ->and($geocoder->calls)->toHaveCount(2);
});

it('drops a known place\'s stale reviews on re-share when the re-match carries no snippets', function () {
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', reshareGeo(
        'ChIJdrop', 4.6, [['author' => 'Fresh', 'rating' => 5, 'text' => 'new']],
    )));
    (new ResolvePlace(analyzingShare()->id))->handle();
    $place = Place::sole();
    $place->forceFill([
        'google_rating' => '3.0',
        'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'stale']],
        'google_reviews_synced_at' => now()->subDays(45),
    ])->save();

    // Re-match resolves the SAME Google place but carries no fresh snippets (e.g.
    // keyless Nominatim dev) — a refresh is impossible, so the stale cache drops.
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', reshareGeo('ChIJdrop', 4.6, [])));
    (new ResolvePlace(analyzingShare()->id))->handle();

    $place->refresh();
    expect($place->google_reviews_json)->toBeNull()
        ->and($place->google_rating)->toBeNull()
        ->and($place->google_reviews_synced_at)->toBeNull();
});

it('does not restamp a rating-only place (no snippets) on re-share', function () {
    // Resolver stores NULL google_reviews_json when Google returned a rating but
    // no snippets — that place is out of the refresh sweep's scope and must stay.
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', reshareGeo(
        'ChIJratingonly', 4.4, [],
    )));
    (new ResolvePlace(analyzingShare()->id))->handle();
    $place = Place::sole();
    expect($place->google_reviews_json)->toBeNull()
        ->and((float) $place->google_rating)->toBe(4.4);

    (new ResolvePlace(analyzingShare()->id))->handle();

    expect((float) $place->fresh()->google_rating)->toBe(4.4);
});

describe('GooglePlaceRefresher', function () {
    it('only treats aged snippet content as stale', function () {
        $refresher = app(GooglePlaceRefresher::class);

        $aged = Place::factory()->active()->make([
            'google_reviews_json' => [['author' => 'A', 'rating' => 4, 'text' => 'x']],
            'google_reviews_synced_at' => now()->subDays(45),
        ]);
        $fresh = Place::factory()->active()->make([
            'google_reviews_json' => [['author' => 'A', 'rating' => 4, 'text' => 'x']],
            'google_reviews_synced_at' => now()->subDays(2),
        ]);
        $ratingOnly = Place::factory()->active()->make([
            'google_rating' => '4.4',
            'google_reviews_json' => null,
            'google_reviews_synced_at' => null,
        ]);

        expect($refresher->isStale($aged))->toBeTrue()
            ->and($refresher->isStale($fresh))->toBeFalse()
            ->and($refresher->isStale($ratingOnly))->toBeFalse();
    });

    it('honours a window override for staleness', function () {
        $refresher = app(GooglePlaceRefresher::class);
        $place = Place::factory()->active()->make([
            'google_reviews_json' => [['author' => 'A', 'rating' => 4, 'text' => 'x']],
            'google_reviews_synced_at' => now()->subDays(2),
        ]);

        expect($refresher->isStale($place, 30))->toBeFalse()
            ->and($refresher->isStale($place, 1))->toBeTrue();
    });

    it('refresh() fetches and reports refreshed / dropped / unchanged', function () {
        $withStaleSnippets = fn (string $name, ?string $gpid = null) => Place::factory()->active()
            ->when($gpid !== null, fn ($f) => $f->withGooglePlaceId($gpid))
            ->create([
                'name' => $name,
                'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'stale']],
                'google_reviews_synced_at' => now()->subDays(45),
            ]);

        // refreshed — same place comes back with fresh snippets.
        $refreshed = new GooglePlaceRefresher((new FakeGeocoder)->seed('A', reshareGeo('ChIJa', 4.5, [['author' => 'New', 'rating' => 5, 'text' => 'n']])));
        expect($refreshed->refresh($withStaleSnippets('A', 'ChIJa')))->toBe('refreshed');

        // dropped — geocoder returns nothing, so stale cache is purged.
        $dropped = new GooglePlaceRefresher(new FakeGeocoder);
        expect($dropped->refresh($withStaleSnippets('B')))->toBe('dropped');

        // unchanged — nothing cached and nothing to fetch → no write.
        $empty = new GooglePlaceRefresher(new FakeGeocoder);
        expect($empty->refresh(Place::factory()->active()->create(['name' => 'C'])))->toBe('unchanged');
    });

    it('applies a trustworthy geocode and drops an untrustworthy one', function () {
        $refresher = app(GooglePlaceRefresher::class);
        $base = fn () => Place::factory()->active()->withGooglePlaceId('ChIJx')->make([
            'google_rating' => '3.0',
            'google_reviews_json' => [['author' => 'Old', 'rating' => 3, 'text' => 'old']],
            'google_reviews_synced_at' => now()->subDays(45),
        ]);

        // Same place + rating + reviews → refresh.
        $good = $base();
        expect($refresher->applyGeocode($good, reshareGeo('ChIJx', 4.8, [['author' => 'New', 'rating' => 5, 'text' => 'new']])))->toBeTrue();
        expect((float) $good->google_rating)->toBe(4.8)
            ->and($good->google_reviews_json[0]['author'])->toBe('New');

        // Different Google place → drop (never overwrite with another listing).
        $wrong = $base();
        expect($refresher->applyGeocode($wrong, reshareGeo('ChIJother', 4.0, [['author' => 'X', 'rating' => 4, 'text' => 'x']])))->toBeTrue();
        expect($wrong->google_rating)->toBeNull()
            ->and($wrong->google_reviews_json)->toBeNull();

        // Nothing back → drop.
        $none = $base();
        expect($refresher->applyGeocode($none, null))->toBeTrue();
        expect($none->google_reviews_json)->toBeNull();
    });
});
