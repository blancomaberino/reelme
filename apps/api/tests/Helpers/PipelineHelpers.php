<?php

/*
|--------------------------------------------------------------------------
| Shared pipeline test helpers
|--------------------------------------------------------------------------
| Used by the ResolvePlace / PublishShare / ShareReview suites. Loaded from
| Pest.php so they exist in every parallel worker regardless of which test
| files a process happens to compile (defining them inside one test file
| made sibling suites fail under `artisan test --parallel`).
*/

use App\Adapters\AdapterRegistry;
use App\Enums\AnalysisEngine;
use App\Enums\AnalysisStatus;
use App\Enums\ShareStatus;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\Share;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use Tests\Support\FakeInstagramAdapter;

/**
 * @param  list<array<string, mixed>>  $reviews
 */
function geoResult(string $gpid, float $lat, float $lng, float $score = 0.9, string $name = 'Lanzhou Beef Noodle House', ?float $rating = null, ?int $ratingCount = null, array $reviews = []): GeocodeResult
{
    return new GeocodeResult(
        googlePlaceId: $gpid,
        canonicalName: $name,
        formattedAddress: "{$name}, London, UK",
        addressComponents: [
            ['long_name' => 'United Kingdom', 'short_name' => 'GB', 'types' => ['country', 'political']],
            ['long_name' => 'London', 'short_name' => 'London', 'types' => ['locality', 'political']],
        ],
        lat: $lat,
        lng: $lng,
        types: ['restaurant'],
        score: $score,
        rating: $rating,
        ratingCount: $ratingCount,
        reviews: $reviews,
    );
}

function analyzingShare(string $placeName = 'Lanzhou Beef Noodle House', float $confidence = 0.9): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Analyzing]);
    $run = AnalysisRun::create([
        'share_id' => $share->id,
        'engine' => AnalysisEngine::Local,
        'model' => 'test-model',
        'status' => AnalysisStatus::Succeeded,
        'overall_confidence' => $confidence,
        'result_json' => [
            'place' => [
                'name' => $placeName,
                'address' => ['street' => '45 Gerrard St', 'city' => 'London', 'region' => 'England', 'postal_code' => null, 'country' => 'GB'],
                'geo' => null,
                'cuisines' => ['chinese'],
                'price_range' => 2,
                'phone' => null,
                'website' => null,
            ],
            'post' => ['language' => 'en'],
            'confidence' => ['overall' => $confidence],
        ],
        'started_at' => now(),
        'finished_at' => now(),
    ]);
    $share->analysis_run_id = $run->id;
    $share->save();

    return $share;
}

function bindGeocoder(Geocoder $geocoder): void
{
    app()->instance(Geocoder::class, $geocoder);
}

function useFakeInstagram(): void
{
    config(['ingestion.chains.instagram' => [FakeInstagramAdapter::class]]);
    app()->forgetInstance(AdapterRegistry::class);
}

/** Publish a share end-to-end (resolve → publish) with the standard fixture snapshot. */
function publishTaggedShare(float $confidence = 0.9): Place
{
    bindGeocoder((new FakeGeocoder)->seed('Lanzhou Beef Noodle House', geoResult('ChIJtags', 51.5, -0.13)));
    $share = analyzingShare(confidence: $confidence);
    (new ResolvePlace($share->id))->handle();
    (new PublishShare($share->id))->handle();

    return Place::sole();
}
