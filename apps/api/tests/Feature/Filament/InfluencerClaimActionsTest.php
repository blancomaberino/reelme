<?php

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Events\InfluencerClaimed;
use App\Filament\Resources\InfluencerClaims\Pages\ListInfluencerClaims;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use App\Notifications\InfluencerClaimRejected;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('lets an admin reject a pending claim and notifies the claimant', function () {
    Notification::fake();
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $claimant = User::factory()->create();
    $claim = InfluencerClaim::factory()->create(['user_id' => $claimant->id]);

    Livewire::test(ListInfluencerClaims::class)
        ->callTableAction('reject', $claim);

    expect($claim->fresh()->status)->toBe(ClaimStatus::Rejected)
        ->and($claim->fresh()->reason)->toBe('rejected_by_admin')
        ->and($claim->fresh()->reviewed_by_user_id)->toBe($admin->id);
    Notification::assertSentTo($claimant, InfluencerClaimRejected::class, function (InfluencerClaimRejected $n) use ($claim) {
        $payload = $n->toDatabase($claim->user);

        return $payload['type'] === 'influencer.claim_rejected'
            && $payload['influencer_handle'] === $claim->influencer->handle
            && $payload['url'] === '/influencers/'.$claim->influencer_id;
    });
});

it('lets an admin override a claimed identity, moving the link to the new claimant', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $previous = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $previous->id, 'claimed_at' => now()])->save();
    User::whereKey($previous->id)->update(['is_influencer' => true]);
    InfluencerClaim::factory()->verified()->create(['influencer_id' => $influencer->id, 'user_id' => $previous->id]);

    $newClaimant = User::factory()->create();
    $pending = InfluencerClaim::factory()->create(['influencer_id' => $influencer->id, 'user_id' => $newClaimant->id]);

    Livewire::test(ListInfluencerClaims::class)
        ->callTableAction('approve', $pending);

    expect($influencer->fresh()->claimed_by_user_id)->toBe($newClaimant->id)
        ->and($newClaimant->fresh()->is_influencer)->toBeTrue()
        ->and($pending->fresh()->reviewed_by_user_id)->toBe($admin->id)
        // Previous owner is demoted (they hold no other identity) and their claim rejected.
        ->and($previous->fresh()->is_influencer)->toBeFalse()
        ->and(InfluencerClaim::where('user_id', $previous->id)->first()->status)->toBe(ClaimStatus::Rejected)
        ->and($pending->fresh()->status)->toBe(ClaimStatus::Verified);
});

it('hides approve and reject actions for an already-resolved claim', function () {
    $this->actingAs(User::factory()->admin()->create());

    $verified = InfluencerClaim::factory()->verified()->create();

    Livewire::test(ListInfluencerClaims::class)
        ->assertTableActionHidden('approve', $verified)
        ->assertTableActionHidden('reject', $verified);
});

it('keeps the previous owner an influencer if they still hold another identity', function () {
    $this->actingAs(User::factory()->admin()->create());

    $previous = User::factory()->create();
    $disputed = Influencer::factory()->create();
    $disputed->forceFill(['claimed_by_user_id' => $previous->id, 'claimed_at' => now()])->save();
    $other = Influencer::factory()->create();
    $other->forceFill(['claimed_by_user_id' => $previous->id, 'claimed_at' => now()])->save();
    User::whereKey($previous->id)->update(['is_influencer' => true]);

    $newClaimant = User::factory()->create();
    $pending = InfluencerClaim::factory()->create(['influencer_id' => $disputed->id, 'user_id' => $newClaimant->id]);

    Livewire::test(ListInfluencerClaims::class)->callTableAction('approve', $pending);

    // They lost the disputed identity but kept $other → still an influencer.
    expect($previous->fresh()->is_influencer)->toBeTrue()
        ->and($other->fresh()->claimed_by_user_id)->toBe($previous->id);
});

it('surfaces only disputed identities under the disputed filter', function () {
    $this->actingAs(User::factory()->admin()->create());

    $disputed = Influencer::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $claimA = InfluencerClaim::factory()->create(['influencer_id' => $disputed->id, 'user_id' => $userA->id]);
    $claimB = InfluencerClaim::factory()->create(['influencer_id' => $disputed->id, 'user_id' => $userB->id]);

    $solo = InfluencerClaim::factory()->create();

    Livewire::test(ListInfluencerClaims::class)
        ->filterTable('disputed')
        ->assertCanSeeTableRecords([$claimA, $claimB])
        ->assertCanNotSeeTableRecords([$solo]);
});

it('lets an admin manually assign an unclaimed identity to a user', function () {
    Event::fake([InfluencerClaimed::class]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $influencer = Influencer::factory()->create();
    $user = User::factory()->create();

    Livewire::test(ListInfluencerClaims::class)
        ->callAction('assign', data: [
            'influencer_id' => $influencer->id,
            'user_id' => $user->id,
        ])
        ->assertHasNoActionErrors();

    $claim = InfluencerClaim::where('influencer_id', $influencer->id)->where('user_id', $user->id)->firstOrFail();
    expect($influencer->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($user->fresh()->is_influencer)->toBeTrue()
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and($claim->method)->toBe(ClaimMethod::Admin)
        ->and($claim->reviewed_by_user_id)->toBe($admin->id);
    Event::assertDispatched(InfluencerClaimed::class, fn ($e) => $e->influencer->id === $influencer->id && $e->user->id === $user->id);
});

it('reassigns via the assign action when the identity is already claimed', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $previous = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $previous->id, 'claimed_at' => now()])->save();
    User::whereKey($previous->id)->update(['is_influencer' => true]);
    InfluencerClaim::factory()->verified()->create(['influencer_id' => $influencer->id, 'user_id' => $previous->id]);

    $newOwner = User::factory()->create();

    Livewire::test(ListInfluencerClaims::class)
        ->callAction('assign', data: ['influencer_id' => $influencer->id, 'user_id' => $newOwner->id]);

    expect($influencer->fresh()->claimed_by_user_id)->toBe($newOwner->id)
        ->and($newOwner->fresh()->is_influencer)->toBeTrue()
        ->and($previous->fresh()->is_influencer)->toBeFalse()
        ->and(InfluencerClaim::where('user_id', $previous->id)->first()->status)->toBe(ClaimStatus::Rejected);
});

it('is idempotent when assigning an identity to its current owner (no re-fire)', function () {
    Event::fake([InfluencerClaimed::class]);
    $this->actingAs(User::factory()->admin()->create());

    $owner = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();

    Livewire::test(ListInfluencerClaims::class)
        ->callAction('assign', data: ['influencer_id' => $influencer->id, 'user_id' => $owner->id]);

    expect($influencer->fresh()->claimed_by_user_id)->toBe($owner->id);
    // Ownership didn't move, so the M4 escrow event must not re-fire.
    Event::assertNotDispatched(InfluencerClaimed::class);
});

it('forbids a non-admin from the claims panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin/influencer-claims')->assertForbidden();
});
