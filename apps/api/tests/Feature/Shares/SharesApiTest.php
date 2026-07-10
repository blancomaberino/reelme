<?php

use App\Enums\ShareStatus;
use App\Jobs\FetchSourcePost;
use App\Jobs\IngestShare;
use App\Models\Share;
use App\Models\ShareStageMetric;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
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

it('extracts a URL from shared_text', function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['shared_text' => 'check this https://www.tiktok.com/@u/video/123 🔥'])
        ->assertStatus(202)
        ->assertJsonPath('data.platform', 'tiktok');
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

it('409s a retry from a non-retryable state', function () {
    $user = User::factory()->create();
    $share = Share::factory()->create(['user_id' => $user->id, 'status' => ShareStatus::Analyzing]);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('discards an unpublished share and 409s a published one', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $review = Share::factory()->create(['user_id' => $user->id, 'status' => ShareStatus::Review]);
    $this->deleteJson("/api/v1/shares/{$review->id}")->assertOk();
    expect($review->fresh()->status)->toBe(ShareStatus::Rejected);

    $published = Share::factory()->published()->create(['user_id' => $user->id]);
    $this->deleteJson("/api/v1/shares/{$published->id}")->assertStatus(409);
});

it('applies rate-limit headers to POST /shares', function () {
    Bus::fake();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/shares', ['url' => 'https://www.instagram.com/reel/RL/'])
        ->assertHeader('X-RateLimit-Limit');
});
