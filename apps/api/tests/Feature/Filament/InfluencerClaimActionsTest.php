<?php

use App\Enums\ClaimStatus;
use App\Filament\Resources\InfluencerClaims\Pages\ListInfluencerClaims;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use App\Notifications\InfluencerClaimRejected;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('lets an admin reject a pending claim and notifies the claimant', function () {
    Notification::fake();
    $this->actingAs(User::factory()->admin()->create());

    $claimant = User::factory()->create();
    $claim = InfluencerClaim::factory()->create(['user_id' => $claimant->id]);

    Livewire::test(ListInfluencerClaims::class)
        ->callTableAction('reject', $claim);

    expect($claim->fresh()->status)->toBe(ClaimStatus::Rejected)
        ->and($claim->fresh()->reason)->toBe('rejected_by_admin');
    Notification::assertSentTo($claimant, InfluencerClaimRejected::class);
});

it('lets an admin override a claimed identity, moving the link to the new claimant', function () {
    $this->actingAs(User::factory()->admin()->create());

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
        // Previous owner is demoted (they hold no other identity) and their claim rejected.
        ->and($previous->fresh()->is_influencer)->toBeFalse()
        ->and(InfluencerClaim::where('user_id', $previous->id)->first()->status)->toBe(ClaimStatus::Rejected)
        ->and($pending->fresh()->status)->toBe(ClaimStatus::Verified);
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

it('forbids a non-admin from the claims panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin/influencer-claims')->assertForbidden();
});
