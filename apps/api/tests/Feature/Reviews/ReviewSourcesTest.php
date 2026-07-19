<?php

use App\Models\ExternalPlaceReview;
use App\Models\Place;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\Drivers\NativeReviewSource;
use App\Services\Reviews\ReviewSource;
use App\Services\Reviews\ReviewSourceRegistry;
use App\Services\Reviews\ReviewSourceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Enable the Trustpilot driver so the cached-row path is exercised end to end. */
function enableTrustpilot(): void
{
    config([
        'reviews.sources.trustpilot.enabled' => true,
        'reviews.sources.trustpilot.api_key' => 'test-key',
    ]);
    // The registry is a singleton built from config at first resolve — drop any
    // instance a prior line resolved so it rebinds with Trustpilot included.
    app()->forgetInstance(ReviewSourceRegistry::class);
}

it('aggregates native + google + trustpilot into review_sources[] in order', function () {
    enableTrustpilot();

    $place = Place::factory()->active()->atPoint(51.5, -0.13)
        ->withGooglePlaceId('ChIJagg')
        ->create([
            'website' => 'https://example.com',
            'google_rating' => 4.5,
            'google_rating_count' => 320,
            'google_reviews_json' => [
                ['author' => 'Gina', 'rating' => 5, 'text' => 'Superb', 'relative_time' => 'a week ago', 'profile_photo_url' => 'https://x/y.jpg'],
            ],
            'google_reviews_synced_at' => now()->subDay(),
        ]);
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 4]);
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 5]);
    ExternalPlaceReview::factory()->for($place)->create([
        'rating' => 3.8,
        'review_count' => 1200,
        'url' => 'https://www.trustpilot.com/review/example.com',
        'snippets_json' => [['author' => 'Ravi', 'rating' => 4, 'text' => 'Reliable', 'relative_time' => null, 'profile_photo_url' => null]],
    ]);

    $res = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    $sources = $res->json('data.review_sources');
    expect(array_column($sources, 'source'))->toBe(['native', 'google', 'trustpilot']);

    // Native: intrinsic — no url, no snippets, averaged rating.
    expect($sources[0])->toMatchArray(['source' => 'native', 'rating' => 4.5, 'count' => 2, 'url' => null, 'synced_at' => null])
        ->and($sources[0]['snippets'])->toBe([]);

    // Google: deep link + normalized snippet + synced_at.
    expect($sources[1]['source'])->toBe('google')
        ->and($sources[1]['rating'])->toBe(4.5)
        ->and($sources[1]['count'])->toBe(320)
        ->and($sources[1]['url'])->toBe('https://search.google.com/local/reviews?placeid=ChIJagg')
        ->and($sources[1]['synced_at'])->not->toBeNull()
        ->and($sources[1]['snippets'][0])->toMatchArray(['author' => 'Gina', 'rating' => 5.0, 'text' => 'Superb']);

    // Trustpilot: from the cached external row.
    expect($sources[2]['source'])->toBe('trustpilot')
        ->and($sources[2]['rating'])->toBe(3.8)
        ->and($sources[2]['count'])->toBe(1200)
        ->and($sources[2]['url'])->toBe('https://www.trustpilot.com/review/example.com')
        ->and($sources[2]['snippets'][0]['author'])->toBe('Ravi');
});

it('keeps the back-compat rating/google_reviews keys alongside review_sources', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)
        ->withGooglePlaceId('ChIJcompat')
        ->create([
            'google_rating' => 4.1,
            'google_rating_count' => 90,
            'google_reviews_json' => [['author' => 'Sam', 'rating' => 4, 'text' => 'Nice']],
            'google_reviews_synced_at' => now()->subDay(),
        ]);

    $res = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    $res->assertJsonPath('data.rating.google.value', 4.1)
        ->assertJsonPath('data.rating.app.count', 0)
        ->assertJsonPath('data.google_reviews.0.author', 'Sam');
    expect($res->json('data.review_sources.0.source'))->toBe('google');
});

it('omits google when there is no place id, and native when there are no reviews', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create([
        'google_place_id' => null,
        'google_rating' => 4.9, // present but unusable without a place id
    ]);

    $res = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    expect($res->json('data.review_sources'))->toBe([]);
});

it('omits google when the place id exists but the cached signal was dropped', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)
        ->withGooglePlaceId('ChIJdropped')
        ->create(['google_rating' => null, 'google_rating_count' => null, 'google_reviews_json' => null]);

    $res = $this->getJson("/api/v1/places/{$place->slug}")->assertOk();

    expect($res->json('data.review_sources'))->toBe([]);
});

it('omits trustpilot when there is no cached row', function () {
    enableTrustpilot();
    $place = Place::factory()->active()->atPoint(51.5, -0.13)
        ->withGooglePlaceId('ChIJnotp')
        ->create([
            'website' => 'https://example.com',
            'google_rating' => 4.0, 'google_rating_count' => 5,
            'google_reviews_json' => [['author' => 'A', 'rating' => 4, 'text' => 'ok']],
        ]);

    $sources = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data.review_sources');

    expect(array_column($sources, 'source'))->toBe(['google']); // no trustpilot row → omitted
});

it('omits a disabled source entirely (trustpilot off → registry excludes it)', function () {
    config(['reviews.sources.trustpilot.enabled' => false]);
    app()->forgetInstance(ReviewSourceRegistry::class);

    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    ExternalPlaceReview::factory()->for($place)->create(); // a cached row exists…

    $sources = $this->getJson("/api/v1/places/{$place->slug}")->assertOk()->json('data.review_sources');

    expect($sources)->toBe([]); // …but the disabled driver never reads it
});

it('isolates a throwing driver so the others still aggregate', function () {
    $throwing = new class implements ReviewSource
    {
        public function id(): string
        {
            return 'boom';
        }

        public function summary(Place $place): ?ReviewSourceSummary
        {
            throw new RuntimeException('provider exploded');
        }
    };
    $native = new NativeReviewSource;

    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 5]);
    // The controller loads the aggregates; here summarize() reads them off the model.
    $place->reviews_count = 1;
    $place->reviews_avg_rating = 5.0;

    $registry = new ReviewSourceRegistry([$throwing, $native]);
    $summaries = $registry->summarize($place);

    expect($summaries)->toHaveCount(1)
        ->and($summaries[0]->source)->toBe('native')
        ->and($summaries[0]->rating)->toBe(5.0);
});
