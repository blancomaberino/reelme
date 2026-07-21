<?php

use App\Enums\ShareStatus;
use App\Jobs\ExtractPlaceData;
use App\Jobs\FetchSourcePost;
use App\Jobs\IngestShare;
use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\ShareStageMetric;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('creates a pending share and dispatches the pipeline (202)', function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/ABC123/'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.platform', 'instagram')
        ->assertJsonPath('data.requires_manual_input', false)
        ->assertJsonPath('meta.poll_interval_ms', 2000);

    Bus::assertDispatched(IngestShare::class);
    $this->assertDatabaseHas('shares', ['status' => 'pending']);
});

it('rejects a share from a disabled source at launch (Instagram-only)', function (string $url, string $platformLabel) {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['url' => $url])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.url.0', "Sharing from {$platformLabel} isn't available yet — only Instagram is supported right now.");

    Bus::assertNotDispatched(IngestShare::class);
    $this->assertDatabaseCount('shares', 0);
})->with([
    'tiktok' => ['https://www.tiktok.com/@u/video/123', 'TikTok'],
    'youtube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'YouTube'],
    'x' => ['https://x.com/u/status/1790000000000000001', 'X'],
]);

it('accepts a source once its launch switch is flipped on', function () {
    config(['ingestion.platforms.tiktok.enabled' => true]);
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['url' => 'https://www.tiktok.com/@u/video/123'])
        ->assertStatus(202)
        ->assertJsonPath('data.platform', 'tiktok');

    Bus::assertDispatched(IngestShare::class);
});

it('returns the existing share on a duplicate URL (idempotent, no 2nd row)', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $url = 'https://www.instagram.com/reel/DUP123/';

    $first = $this->postJson('/api/v1/shares', ['url' => $url])->json('data.id');
    $this->postJson('/api/v1/shares', ['url' => $url])
        ->assertStatus(202)
        ->assertJsonPath('data.id', $first)
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(Share::count())->toBe(1);
});

it('re-sharing a soft-hidden post clears the per-place hide so it returns to my map (T-071 re-add)', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $url = 'https://www.instagram.com/p/DaY-y1fiTs7/';

    $shareId = (int) $this->postJson('/api/v1/shares', ['url' => $url])->json('data.id');
    // Wire a published place to the share, then the owner soft-hides that pin.
    $place = Place::factory()->active()->create();
    PlaceSource::factory()->create([
        'share_id' => $shareId, 'place_id' => $place->id,
        'source_post_id' => Share::find($shareId)->source_post_id, 'published_at' => now(),
    ]);
    HiddenPlace::create(['user_id' => $user->id, 'place_id' => $place->id]);
    expect(HiddenPlace::where('place_id', $place->id)->count())->toBe(1);

    // Re-sharing the same post is the natural "re-add" — the hide is cleared.
    $this->postJson('/api/v1/shares', ['url' => $url])
        ->assertStatus(202)
        ->assertJsonPath('data.id', (string) $shareId)
        ->assertJsonPath('meta.idempotent_replay', true);

    expect(HiddenPlace::where('place_id', $place->id)->count())->toBe(0)
        ->and(Share::count())->toBe(1);
});

it('extracts a URL from shared_text', function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['shared_text' => 'check this https://www.instagram.com/reel/ABC123/ 🔥'])
        ->assertStatus(202)
        ->assertJsonPath('data.platform', 'instagram');
});

it('shows a share to its owner and 403s other users', function () {
    $owner = User::factory()->create();
    $share = Share::factory()->create(['user_id' => $owner->id]);

    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/shares/{$share->id}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $share->id)
        ->assertJsonPath('data.status_history.0.status', 'pending');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/v1/shares/{$share->id}")->assertStatus(403);
});

it('lists only the caller’s shares', function () {
    $user = User::factory()->create();
    Share::factory()->count(2)->create(['user_id' => $user->id]);
    Share::factory()->create(); // someone else's

    Sanctum::actingAs($user);
    $this->getJson('/api/v1/shares')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lists multi-place shares without a per-place coordinate query (T-086 N+1)', function () {
    $user = User::factory()->create();

    // Two published shares, each fanning out to two published places (a
    // multi-place post). The pre-fix path fired Place::coordinates() — an
    // unconditional single-row point SELECT — once per place per share.
    $seed = [
        ['primary' => [51.50, -0.12], 'secondary' => [48.85, 2.35]],
        ['primary' => [40.41, -3.70], 'secondary' => [41.39, 2.16]],
    ];
    $lastPrimary = null;
    $lastSecondary = null;
    foreach ($seed as $points) {
        $share = Share::factory()->for($user)->published()->create();
        $lastPrimary = Place::factory()->active()->atPoint(...$points['primary'])->create();
        $lastSecondary = Place::factory()->active()->atPoint(...$points['secondary'])->create();

        $primarySource = PlaceSource::factory()->create([
            'share_id' => $share->id, 'place_id' => $lastPrimary->id,
            'source_post_id' => $share->source_post_id,
            'is_primary' => true, 'published_at' => now(),
        ]);
        PlaceSource::factory()->create([
            'share_id' => $share->id, 'place_id' => $lastSecondary->id,
            'source_post_id' => $share->source_post_id,
            'is_primary' => false, 'published_at' => now(),
        ]);
        $share->forceFill(['published_place_source_id' => $primarySource->id])->save();
    }

    Sanctum::actingAs($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $res = $this->getJson('/api/v1/shares')->assertOk()->assertJsonCount(2, 'data');
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // The N+1 signature: coordinates() reads a single row by id. After the fix
    // the eager-load selects lat/lng inline (WHERE places.id IN (...)), so not a
    // single per-place point read remains.
    $pointReads = collect($log)->filter(
        fn (array $q): bool => str_contains($q['query'], 'FROM places WHERE id = ?')
    );
    expect($pointReads)->toHaveCount(0);

    // Coordinates are byte-identical to the single-place point read they replace,
    // and the primary still sorts first in `places[]`. `data.0` is the most-recent
    // share (orderByDesc id) → the last seeded pair.
    $data = $res->json('data.0');
    expect($data['place']['lat'])->toBe($lastPrimary->coordinates()['lat'])
        ->and($data['place']['lng'])->toBe($lastPrimary->coordinates()['lng'])
        ->and($data['places'])->toHaveCount(2)
        ->and($data['places'][0]['lat'])->toBe($lastPrimary->coordinates()['lat'])
        ->and($data['places'][1]['lat'])->toBe($lastSecondary->coordinates()['lat']);
});

it('retries a failed share from its failed stage', function () {
    Bus::fake();
    $user = User::factory()->create();
    $share = Share::factory()->failed()->create(['user_id' => $user->id]);
    ShareStageMetric::create(['share_id' => $share->id, 'stage' => 'fetch', 'status' => 'failed', 'started_at' => now()]);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/retry")->assertOk();

    Bus::assertDispatched(FetchSourcePost::class); // chain head = failed stage
    expect($share->fresh()->status)->toBe(ShareStatus::Fetching);
});

it('retries from the extract stage back into fetching (the fetching→analyzing boundary)', function () {
    // Guards the Pipeline::entryStatus/ExtractPlaceData::expectedStatus invariant:
    // extract must re-enter at `fetching`, or ExtractPlaceData no-ops and the share
    // publishes without extraction.
    Bus::fake();
    $user = User::factory()->create();
    $share = Share::factory()->failed()->create(['user_id' => $user->id]);
    ShareStageMetric::create(['share_id' => $share->id, 'stage' => 'extract', 'status' => 'failed', 'started_at' => now()]);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/retry")->assertOk();

    Bus::assertDispatched(ExtractPlaceData::class); // chain head = extract stage
    expect($share->fresh()->status)->toBe(ShareStatus::Fetching);
});

it('retries a review/fetch_unavailable share back into fetching', function () {
    Bus::fake();
    $user = User::factory()->create();
    $share = Share::factory()->create([
        'user_id' => $user->id,
        'status' => ShareStatus::Review,
        'failure_reason' => 'fetch_unavailable',
    ]);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/retry")->assertOk();

    Bus::assertDispatched(FetchSourcePost::class);
    expect($share->fresh()->status)->toBe(ShareStatus::Fetching);
});

it('rejects an over-long URL extracted from shared_text with 422', function () {
    Sanctum::actingAs(User::factory()->create());
    $longUrl = 'https://example.com/'.str_repeat('a', 2100); // > 2048, only reachable via shared_text

    $this->postJson('/api/v1/shares', ['shared_text' => "check this {$longUrl}"])
        ->assertStatus(422);

    expect(Share::count())->toBe(0);
});

it('409s a retry from a non-retryable state', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create(['user_id' => $user->id, 'status' => ShareStatus::Analyzing]);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('discards an in-flight share from any non-terminal state', function (ShareStatus $status) {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $share = Share::factory()->create(['user_id' => $user->id, 'status' => $status]);

    $this->deleteJson("/api/v1/shares/{$share->id}")
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    expect($share->fresh()->status)->toBe(ShareStatus::Rejected);
})->with([
    'pending' => [ShareStatus::Pending],
    'fetching' => [ShareStatus::Fetching],
    'analyzing' => [ShareStatus::Analyzing],
    'review' => [ShareStatus::Review],
    'failed' => [ShareStatus::Failed],
]);

it('409s discarding an already-published share', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $published = Share::factory()->published()->create(['user_id' => $user->id]);
    $this->deleteJson("/api/v1/shares/{$published->id}")->assertStatus(409);

    expect($published->fresh()->status)->toBe(ShareStatus::Published);
});

// NOTE: the concurrent create-race catch path (two requests inserting the same
// (user, source_post) at once → the loser catches UniqueConstraintViolationException
// and returns the idempotent replay) is NOT unit-tested here. Reproducing it needs a
// second committed connection referencing this test's user/post, which RefreshDatabase's
// single wrapping transaction hides (the row would FK-violate). The behaviour is
// covered by: the unique(user_id, source_post_id) constraint, the savepoint that keeps
// the recovery SELECT alive on Postgres, and the fast-path idempotency test above.

it('applies rate-limit headers to POST /shares', function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/RL/'])
        ->assertHeader('X-RateLimit-Limit');
});
