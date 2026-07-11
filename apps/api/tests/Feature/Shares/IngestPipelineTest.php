<?php

use App\Adapters\AdapterRegistry;
use App\Enums\FetchStatus;
use App\Enums\PlaceStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\FetchSourcePost;
use App\Jobs\IngestShare;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

// useFakeInstagram() lives in tests/Helpers/PipelineHelpers.php (loaded via
// Pest.php) so sibling suites can use it under --parallel.

it('IngestShare moves pending → fetching and dispatches the chain', function () {
    Bus::fake();
    $share = Share::factory()->create(['status' => ShareStatus::Pending]);

    (new IngestShare($share->id))->handle();

    expect($share->fresh()->status)->toBe(ShareStatus::Fetching);
    Bus::assertDispatched(FetchSourcePost::class);
});

it('FetchSourcePost persists metadata from the adapter and advances', function () {
    useFakeInstagram();

    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/OK/',
        'fetch_status' => FetchStatus::Pending,
    ]);

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    $post = $share->sourcePost->fresh();
    expect($post->fetch_status)->toBe(FetchStatus::Fetched)
        ->and($post->caption)->toBe('best noodles in lisbon')
        ->and($post->influencer->handle)->toBe('noodle.hunter');
});

it('parks the share in review when the chain needs manual fallback', function () {
    // No real adapter; only ManualUpload (default), which — with no payload —
    // throws NeedsManualFallback.
    config(['ingestion.chains.instagram' => []]);
    app()->forgetInstance(AdapterRegistry::class);

    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => 'https://www.instagram.com/reel/NOPAY/',
        'fetch_status' => FetchStatus::Pending,
    ]);

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    expect($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->failure_reason)->toBe('fetch_unavailable');
});

it('runs the full pipeline to a resolved place (sync queue + fakes)', function () {
    useFakeInstagram();
    Sanctum::actingAs(User::factory()->create());

    // The fake adapter yields no media, so extraction runs text-only; fake Ollama
    // so ExtractPlaceData produces a clean high-confidence result and continues.
    Http::fake([
        '*/api/tags' => Http::response(['models' => []]),
        '*/api/chat' => Http::response([
            'message' => ['content' => (string) file_get_contents(base_path('tests/Fixtures/extraction/valid.json'))],
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]),
    ]);
    // Fake the geocoder so ResolvePlace pins the extracted place (name from valid.json).
    app()->instance(Geocoder::class, (new FakeGeocoder)->seed(
        'Lanzhou Beef Noodle House',
        new GeocodeResult('ChIJpipeline', 'Lanzhou Beef Noodle House', '45 Gerrard St, London', [
            ['long_name' => 'United Kingdom', 'short_name' => 'GB', 'types' => ['country', 'political']],
        ], 51.5117, -0.1300, ['restaurant'], 0.92),
    ));

    // QUEUE_CONNECTION=sync in tests, so the whole chain runs during the request.
    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/FULL/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Published) // T-024: publish is now a real terminal stage
        ->and($share->published_place_source_id)->not->toBeNull()
        ->and($share->stageMetrics()->pluck('stage')->all())->toContain('ingest', 'fetch', 'extract', 'resolve', 'publish');

    $place = Place::sole();
    expect($place->google_place_id)->toBe('ChIJpipeline')
        ->and($place->status)->toBe(PlaceStatus::Pending) // single unverified source stays pending
        ->and(PlaceSource::where('share_id', $share->id)->where('place_id', $place->id)->exists())->toBeTrue();
});
