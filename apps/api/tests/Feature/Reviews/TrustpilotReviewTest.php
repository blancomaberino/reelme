<?php

use App\Models\ExternalPlaceReview;
use App\Models\Place;
use App\Services\Reviews\Drivers\TrustpilotReviewSource;
use App\Services\Reviews\Trustpilot\TrustpilotReviewRefresher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'reviews.sources.trustpilot.enabled' => true,
        'reviews.sources.trustpilot.api_key' => 'test-key',
        'reviews.sources.trustpilot.base_url' => 'https://api.trustpilot.com/v1',
        'reviews.sources.trustpilot.refresh_after_days' => 7,
    ]);
});

/** Fake a resolvable Trustpilot business + its reviews. */
function fakeTrustpilot(): void
{
    Http::fake([
        'api.trustpilot.com/v1/business-units/find*' => Http::response([
            'id' => 'unit-123',
            'score' => ['trustScore' => 4.3, 'stars' => 4.5],
            'numberOfReviews' => ['total' => 512],
        ]),
        'api.trustpilot.com/v1/business-units/*/reviews*' => Http::response([
            'reviews' => [
                ['consumer' => ['displayName' => 'Ana'], 'stars' => 5, 'text' => 'Great service'],
                ['consumer' => ['displayName' => 'Bo'], 'stars' => 4, 'text' => 'Solid'],
            ],
        ]),
    ]);
}

it('fetches and caches a Trustpilot summary keyed on the website domain', function () {
    fakeTrustpilot();
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://www.joes.com/menu']);

    $outcome = app(TrustpilotReviewRefresher::class)->refresh($place);

    expect($outcome)->toBe('refreshed');
    $row = ExternalPlaceReview::where('place_id', $place->id)->where('source', 'trustpilot')->sole();
    expect((float) $row->rating)->toBe(4.3)
        ->and($row->review_count)->toBe(512)
        ->and($row->url)->toBe('https://www.trustpilot.com/review/joes.com') // www. + path stripped
        ->and($row->snippets_json)->toHaveCount(2)
        ->and($row->snippets_json[0]['author'])->toBe('Ana');

    // Authenticated with the configured api key, and queried by the bare domain.
    Http::assertSent(fn ($req) => $req->hasHeader('apikey', 'test-key')
        && str_contains($req->url(), 'business-units/find')
        && str_contains($req->url(), 'name=joes.com'));
});

it('skips a place with no resolvable website domain (no row, no fetch)', function () {
    Http::fake();
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => null]);

    expect(app(TrustpilotReviewRefresher::class)->isStale($place))->toBeFalse()
        ->and(app(TrustpilotReviewRefresher::class)->refresh($place))->toBe('unchanged');
    Http::assertNothingSent();
    expect(ExternalPlaceReview::count())->toBe(0);
});

it('treats an IP / localhost website as unresolvable (SSRF-shaped input)', function () {
    Http::fake();
    foreach (['http://127.0.0.1/', 'https://169.254.169.254/latest', 'http://localhost'] as $bad) {
        $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => $bad]);
        expect(app(TrustpilotReviewRefresher::class)->domainFor($place))->toBeNull();
    }
    Http::assertNothingSent();
});

it('does not re-fetch a cached row that is still within the refresh window', function () {
    Http::fake();
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($place)->syncedDaysAgo(2)->create(); // window is 7 days

    expect(app(TrustpilotReviewRefresher::class)->isStale($place))->toBeFalse();
    Http::assertNothingSent();
});

it('re-fetches and upserts a cached row past the refresh window', function () {
    fakeTrustpilot();
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($place)->syncedDaysAgo(30)->create(['rating' => 2.0, 'review_count' => 3]);

    expect(app(TrustpilotReviewRefresher::class)->isStale($place))->toBeTrue();
    app(TrustpilotReviewRefresher::class)->refresh($place);

    $row = ExternalPlaceReview::where('place_id', $place->id)->sole(); // upsert, not a 2nd row
    expect((float) $row->rating)->toBe(4.3)->and($row->review_count)->toBe(512);
});

it('drops a stale cached row when the API confirms no business resolves', function () {
    // A clean 200 that resolves nothing → the business is genuinely gone.
    Http::fake(['api.trustpilot.com/v1/business-units/find*' => Http::response([], 200)]);
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($place)->syncedDaysAgo(30)->create();

    expect(app(TrustpilotReviewRefresher::class)->refresh($place))->toBe('dropped');
    expect(ExternalPlaceReview::count())->toBe(0);
});

it('keeps a cached row on a transient failure instead of blanking it', function () {
    Http::fake(['api.trustpilot.com/*' => Http::response([], 500)]); // outage / non-2xx
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($place)->syncedDaysAgo(30)->create(['rating' => 4.1]);

    expect(app(TrustpilotReviewRefresher::class)->refresh($place))->toBe('unchanged');
    // The recent-enough summary survives the blip — self-heals next good sweep.
    expect((float) ExternalPlaceReview::sole()->rating)->toBe(4.1);
});

it('sweeps only stale places and isolates per-row failures', function () {
    fakeTrustpilot();
    $fresh = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($fresh)->syncedDaysAgo(1)->create(['rating' => 2.0]);
    $stale = Place::factory()->active()->atPoint(51.6, -0.14)->create(['website' => 'https://kates.com']);

    $this->artisan('reelmap:trustpilot:refresh-stale')->assertSuccessful();

    // Fresh row untouched; stale place gained a cached summary.
    expect((float) $fresh->externalReviews()->sole()->rating)->toBe(2.0)
        ->and($stale->externalReviews()->where('source', 'trustpilot')->exists())->toBeTrue();
});

it('is a no-op when the Trustpilot source is disabled or unkeyed', function () {
    config(['reviews.sources.trustpilot.api_key' => null]);
    Http::fake();
    Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);

    $this->artisan('reelmap:trustpilot:refresh-stale')->assertSuccessful();
    Http::assertNothingSent();
    expect(ExternalPlaceReview::count())->toBe(0);
});

it('reads the cached row through the TrustpilotReviewSource driver', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['website' => 'https://joes.com']);
    ExternalPlaceReview::factory()->for($place)->create([
        'rating' => 4.6, 'review_count' => 88, 'url' => 'https://www.trustpilot.com/review/joes.com',
    ]);

    $summary = (new TrustpilotReviewSource)->summary($place->fresh());

    expect($summary)->not->toBeNull()
        ->and($summary->source)->toBe('trustpilot')
        ->and($summary->rating)->toBe(4.6)
        ->and($summary->count)->toBe(88);
});
