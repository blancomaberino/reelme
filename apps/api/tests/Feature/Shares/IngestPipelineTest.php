<?php

use App\Adapters\AdapterRegistry;
use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\FetchSourcePost;
use App\Jobs\IngestShare;
use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeInstagramAdapter;

function useFakeInstagram(): void
{
    config(['ingestion.chains.instagram' => [FakeInstagramAdapter::class]]);
    app()->forgetInstance(AdapterRegistry::class);
}

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

it('runs the full pipeline skeleton to analyzing (sync queue + fake adapter)', function () {
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

    // QUEUE_CONNECTION=sync in tests, so the whole chain runs during the request.
    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/FULL/'])
        ->assertStatus(202);

    $share = Share::latest('id')->first();
    expect($share->status)->toBe(ShareStatus::Analyzing)
        ->and($share->stageMetrics()->pluck('stage')->all())->toContain('ingest', 'fetch', 'extract');
});
