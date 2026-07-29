<?php

use App\Enums\ClaimStatus;
use App\Enums\Platform;
use App\Events\InfluencerClaimed;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\PlatformAccount;
use App\Models\User;
use App\Services\Influencers\ProfileBioFetcher;
use App\Support\Contracts\ApiSchema;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

// Swap the profile-bio scraper for a fake before any request runs (a container
// instance bound mid-test, after the kernel's first request, wouldn't take).
// The bio it returns is read from config at call time, so `fakeBio()` can set it
// between the issue and verify requests.
beforeEach(function () {
    $mock = Mockery::mock(ProfileBioFetcher::class);
    $mock->shouldReceive('fetchProfileBio')->andReturnUsing(fn () => config('test.bio'));
    app()->instance(ProfileBioFetcher::class, $mock);
});

/** Set the bio the fake fetcher returns on the next verify (null = unreadable). */
function fakeBio(?string $bio): void
{
    config(['test.bio' => $bio]);
}

/** An unclaimed Instagram influencer + a caller. */
function claimSetup(string $handle = 'chef.tester'): array
{
    $influencer = Influencer::factory()->create(['platform' => Platform::Instagram, 'handle' => $handle]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    return [$influencer, $user];
}

it('verifies instantly via OAuth when a linked handle matches', function () {
    Event::fake([InfluencerClaimed::class]);
    [$influencer, $user] = claimSetup('chef.tester');
    PlatformAccount::factory()->for($user)->create([
        'platform' => Platform::Instagram,
        // Casing differs — citext must still match, proving no manual lower().
        'handle' => 'Chef.Tester',
    ]);

    $res = $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    expect(ApiSchema::errors(ApiSchema::validate($res->json('data'), 'influencer-claim')))->toBe([]);

    expect($influencer->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($user->fresh()->is_influencer)->toBeTrue();
    Event::assertDispatched(InfluencerClaimed::class, fn ($e) => $e->influencer->id === $influencer->id && $e->user->id === $user->id);
});

it('rejects an OAuth claim with no matching linked account and leaves the identity untouched', function () {
    [$influencer, $user] = claimSetup('chef.tester');
    PlatformAccount::factory()->for($user)->create(['platform' => Platform::Instagram, 'handle' => 'someone.else']);

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.reason', 'handle_mismatch');

    expect($influencer->fresh()->claimed_by_user_id)->toBeNull()
        ->and($user->fresh()->is_influencer)->toBeFalse();
});

it('issues a bio code then verifies it against the fetched profile bio', function () {
    Event::fake([InfluencerClaimed::class]);
    [$influencer, $user] = claimSetup();

    $issue = $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
    $token = $issue->json('data.token');
    expect($token)->toStartWith('reelmap-verify-')
        ->and($issue->json('meta.instructions'))->toContain('Instagram');

    // Bio now contains the code → verify succeeds.
    fakeBio("Food lover 🍔 verify: {$token} — DM for collabs");

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
        ->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.token', null);

    expect($influencer->fresh()->claimed_by_user_id)->toBe($user->id);
    Event::assertDispatched(InfluencerClaimed::class);
});

it('keeps the claim pending with token_not_found when the code is absent from the bio', function () {
    [$influencer, $user] = claimSetup();
    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code'])->assertOk();

    fakeBio('A bio with no verification code at all');

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.reason', 'token_not_found');

    expect($influencer->fresh()->claimed_by_user_id)->toBeNull();
    expect(InfluencerClaim::first()->status)->toBe(ClaimStatus::Pending);
});

it('reports profile_unavailable (not token_not_found) on a transient fetch failure', function () {
    [$influencer] = claimSetup();
    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code'])->assertOk();

    fakeBio(null); // couldn't read the profile

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.reason', 'profile_unavailable');

    // Token must NOT be burned — still pending for a retry.
    expect(InfluencerClaim::first()->status)->toBe(ClaimStatus::Pending);
});

it('rejects an expired bio code with token_expired', function () {
    [$influencer, $user] = claimSetup();
    InfluencerClaim::factory()->expired()->create(['influencer_id' => $influencer->id, 'user_id' => $user->id]);

    fakeBio('bio (should not even be fetched)');

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.reason', 'token_expired');
});

it('409s when the influencer is already claimed by another user and never overwrites', function () {
    [$influencer, $user] = claimSetup('chef.tester');
    $owner = User::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    PlatformAccount::factory()->for($user)->create(['platform' => Platform::Instagram, 'handle' => 'chef.tester']);

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict')
        ->assertJsonPath('error.details.reason', 'claimed_by_other');

    expect($influencer->fresh()->claimed_by_user_id)->toBe($owner->id);
});

it('is idempotent when re-claiming an influencer you already own', function () {
    Event::fake([InfluencerClaimed::class]);
    [$influencer, $user] = claimSetup();
    $influencer->forceFill(['claimed_by_user_id' => $user->id, 'claimed_at' => now()])->save();

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');

    // No fresh claim event — the identity was already theirs.
    Event::assertNotDispatched(InfluencerClaimed::class);
});

it('auto-rejects competing pending claims when someone wins the identity', function () {
    [$influencer, $winner] = claimSetup('chef.tester');
    $loser = User::factory()->create();
    $losing = InfluencerClaim::factory()->create(['influencer_id' => $influencer->id, 'user_id' => $loser->id]);
    PlatformAccount::factory()->for($winner)->create(['platform' => Platform::Instagram, 'handle' => 'chef.tester']);

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])->assertOk();

    expect($losing->fresh()->status)->toBe(ClaimStatus::Rejected)
        ->and($losing->fresh()->reason)->toBe('claimed_by_other');
});

it('resumes an in-progress claim via GET', function () {
    [$influencer, $user] = claimSetup();
    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code'])->assertOk();

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}/claim")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');
    expect($res->json('data.token'))->toStartWith('reelmap-verify-');
    expect(ApiSchema::errors(ApiSchema::validate($res->json('data'), 'influencer-claim')))->toBe([]);
});

it('returns null claim state when the caller has never claimed', function () {
    [$influencer] = claimSetup();

    $this->getJson("/api/v1/influencers/{$influencer->id}/claim")
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('rate-limits bio-verify attempts to bound profile-fetch cost', function () {
    [$influencer] = claimSetup();
    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code'])->assertOk();
    fakeBio('no code here');

    // 5 attempts allowed, the 6th is throttled.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
            ->assertStatus(422);
    }
    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'bio_code', 'action' => 'verify'])
        ->assertStatus(429);
});

it('requires authentication', function () {
    $influencer = Influencer::factory()->create();

    $this->postJson("/api/v1/influencers/{$influencer->id}/claim", ['method' => 'oauth'])
        ->assertStatus(401);
});
