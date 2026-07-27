<?php

use App\Adapters\AdapterRegistry;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;
use App\Adapters\InstagramAdapter;
use App\Adapters\InstagramGraphAdapter;
use App\Enums\FetchStatus;
use App\Enums\PlaceStatus;
use App\Enums\Platform;
use App\Enums\ShareStatus;
use App\Jobs\FetchSourcePost;
use App\Jobs\IngestShare;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\PlatformAccount;
use App\Models\Share;
use App\Models\User;
use App\Services\Geo\FakeGeocoder;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeInstagramAdapter;
use Tests\Support\ThrowingAdapter;

/**
 * Bind a single-adapter chain whose adapter always throws $error, so
 * FetchSourcePost's failure branches (release / advance) can be driven with a
 * real class-string the registry resolves — no network. When $then is given the
 * chain is [ThrowingAdapter, FakeInstagramAdapter], so an advance lands on a
 * success.
 */
function fakeThrowingInstagramChain(Throwable $error, bool $then = false): void
{
    app()->instance(ThrowingAdapter::class, new ThrowingAdapter($error));

    $chain = [ThrowingAdapter::class];
    if ($then) {
        $chain[] = FakeInstagramAdapter::class; // a later adapter the chain can advance to
    }
    config(['ingestion.chains.instagram' => $chain]);
    app()->forgetInstance(AdapterRegistry::class);
}

/** A share sitting at `fetching` with its source_post ready to fetch. */
function fetchingInstagramShare(string $url = 'https://www.instagram.com/reel/OK/'): Share
{
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);
    $share->sourcePost->update([
        'platform' => Platform::Instagram,
        'url' => $url,
        // Null the factory's *optional* (sometimes-null) caption so a
        // "no metadata was persisted" assertion is deterministic — persist()
        // would overwrite this on a successful fetch.
        'caption' => null,
        'fetch_status' => FetchStatus::Pending,
        'fetched_at' => null,
    ]);

    return $share;
}

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

    $share = fetchingInstagramShare('https://www.instagram.com/reel/OK/');

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

    $share = fetchingInstagramShare('https://www.instagram.com/reel/NOPAY/');

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    expect($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->failure_reason)->toBe('fetch_unavailable');
});

it('parks a private post as fetch_auth_required when the sharer has no linked account (T-015)', function () {
    // oEmbed → 401 (private), Graph strategy present but no linked token → the
    // chain exhausts to manual, and the review reason reflects "link needed".
    config(['ingestion.chains.instagram' => [
        InstagramAdapter::class,
        InstagramGraphAdapter::class,
    ]]);
    app()->forgetInstance(AdapterRegistry::class);
    Http::fake(['*instagram.com/api/v1/oembed*' => Http::response('', 401)]);

    $share = fetchingInstagramShare('https://www.instagram.com/reel/PRIVX/');

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    expect($share->fresh()->status)->toBe(ShareStatus::Review)
        ->and($share->fresh()->failure_reason)->toBe('fetch_auth_required');
});

it('fetches a private post via the linked account when oEmbed is blocked (T-015)', function () {
    config(['ingestion.chains.instagram' => [
        InstagramAdapter::class,
        InstagramGraphAdapter::class,
    ]]);
    app()->forgetInstance(AdapterRegistry::class);

    Http::fake([
        '*instagram.com/api/v1/oembed*' => Http::response('', 401), // public path blocked
        '*graph.instagram.com/me/media*' => Http::response(['data' => [[
            'id' => '1', 'caption' => 'Reel privado: hamburguesa secreta',
            'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example.test/p.mp4',
            'permalink' => 'https://www.instagram.com/reel/PRIVX/', 'timestamp' => '2026-05-01T00:00:00+0000',
        ]]]),
    ]);

    $share = fetchingInstagramShare('https://www.instagram.com/reel/PRIVX/');
    // The sharer linked their Instagram account — its token authorizes the fetch.
    PlatformAccount::factory()->create([
        'user_id' => $share->user_id,
        'platform' => Platform::Instagram,
        'handle' => 'lagranburgerok',
        'access_token' => 'tok_live',
    ]);

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    $post = $share->sourcePost->fresh();
    expect($post->fetch_status)->toBe(FetchStatus::Fetched)
        ->and($post->caption)->toBe('Reel privado: hamburguesa secreta')
        ->and($post->influencer->handle)->toBe('lagranburgerok');
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

    // T-093: every stage that ran through the worker closed its metric
    // (running → completed) with a measured duration — not a bare "running".
    $metrics = $share->stageMetrics()->get();
    expect($metrics->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($metrics->every(fn ($m) => $m->duration_ms !== null))->toBeTrue();

    $place = Place::sole();
    expect($place->google_place_id)->toBe('ChIJpipeline')
        ->and($place->status)->toBe(PlaceStatus::Pending) // single unverified source stays pending
        ->and(PlaceSource::where('share_id', $share->id)->where('place_id', $place->id)->exists())->toBeTrue();
});

// --- FetchSourcePost off-nominal paths (T-056) ---

it('releases the job (rate-limit back-off) without changing state on a Retry-After', function () {
    // A 429/rate-limited adapter throws FetchFailed with a Retry-After; the job
    // must release itself for that many seconds and NOT touch the share/post.
    fakeThrowingInstagramChain(new FetchFailed('rate limited', retryAfter: 42));
    $share = fetchingInstagramShare('https://www.instagram.com/reel/RL/');

    $job = (new FetchSourcePost($share->id))->withFakeQueueInteractions();
    $job->handle(app(AdapterRegistry::class));

    $job->assertReleased(delay: 42);

    // No state change: the post is still unfetched (persist() would have set
    // fetch_status/fetched_at/caption) and the share is still fetching.
    $post = $share->sourcePost->fresh();
    expect($post->fetch_status)->toBe(FetchStatus::Pending)
        ->and($post->fetched_at)->toBeNull()
        ->and($post->caption)->toBeNull()
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching)
        ->and($share->fresh()->failure_reason)->toBeNull();
});

it('advances to the next adapter when a FetchFailed carries no Retry-After', function () {
    // First adapter fails transiently (no Retry-After) → the chain advances and
    // the second (fake Instagram) succeeds, persisting its metadata.
    fakeThrowingInstagramChain(new FetchFailed('temporary blip'), then: true);
    $share = fetchingInstagramShare('https://www.instagram.com/reel/ADV/');

    $job = (new FetchSourcePost($share->id))->withFakeQueueInteractions();
    $job->handle(app(AdapterRegistry::class));

    $job->assertNotReleased();
    $post = $share->sourcePost->fresh();
    expect($post->fetch_status)->toBe(FetchStatus::Fetched)
        ->and($post->caption)->toBe('best noodles in lisbon')
        ->and($post->influencer->handle)->toBe('noodle.hunter');
});

it('advances past a PostUnavailable to a later adapter that succeeds', function () {
    // A permanent-unavailable adapter (deleted/public-blocked, no auth needed)
    // advances the chain rather than parking; a later adapter still resolves it.
    fakeThrowingInstagramChain(new PostUnavailable('deleted'), then: true);
    $share = fetchingInstagramShare('https://www.instagram.com/reel/GONE/');

    (new FetchSourcePost($share->id))->handle(app(AdapterRegistry::class));

    expect($share->sourcePost->fresh()->fetch_status)->toBe(FetchStatus::Fetched)
        ->and($share->fresh()->status)->toBe(ShareStatus::Fetching); // untouched — DownloadMedia runs next
});

// --- FailsShareOnError terminal hook (T-056) ---

it('fails the share with a taxonomy reason when the job permanently fails', function () {
    $share = Share::factory()->create(['status' => ShareStatus::Fetching]);

    (new FetchSourcePost($share->id))->failed(new Exception('boom'));

    $share = $share->fresh();
    expect($share->status)->toBe(ShareStatus::Failed)
        ->and($share->failure_reason)->toBe('fetch_unavailable');
});

it('is a no-op in failed() when the share is already terminal', function () {
    // A published share can no longer transition to failed; failed() must not
    // throw and must leave the share untouched.
    $share = Share::factory()->create(['status' => ShareStatus::Published]);

    (new FetchSourcePost($share->id))->failed(new Exception('late failure'));

    $share = $share->fresh();
    expect($share->status)->toBe(ShareStatus::Published)
        ->and($share->failure_reason)->toBeNull();
});
