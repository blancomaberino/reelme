<?php

use App\Enums\ClaimMethod;
use App\Enums\ClaimStatus;
use App\Enums\Platform;
use App\Events\InfluencerClaimed;
use App\Filament\Resources\Influencers\Pages\ListInfluencers;
use App\Models\Influencer;
use App\Models\InfluencerClaim;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

it('lists influencers and filters by claim status and platform', function () {
    $this->actingAs(User::factory()->admin()->create());

    $owner = User::factory()->create();
    $claimed = Influencer::factory()->create(['platform' => Platform::Instagram]);
    $claimed->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    $unclaimedIg = Influencer::factory()->create(['platform' => Platform::Instagram]);
    $tiktok = Influencer::factory()->create(['platform' => Platform::Tiktok]);

    Livewire::test(ListInfluencers::class)
        ->assertCanSeeTableRecords([$claimed, $unclaimedIg, $tiktok])
        // Claim-status ternary: unclaimed only.
        ->filterTable('claimed', false)
        ->assertCanSeeTableRecords([$unclaimedIg, $tiktok])
        ->assertCanNotSeeTableRecords([$claimed])
        // Platform facet.
        ->resetTableFilters()
        ->filterTable('platform', Platform::Tiktok->value)
        ->assertCanSeeTableRecords([$tiktok])
        ->assertCanNotSeeTableRecords([$claimed, $unclaimedIg]);
});

it('assigns an unclaimed identity to a user from the influencer row', function () {
    Event::fake([InfluencerClaimed::class]);
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $influencer = Influencer::factory()->create();
    $user = User::factory()->create();

    Livewire::test(ListInfluencers::class)
        ->callTableAction('assign', $influencer, data: ['user_id' => $user->id])
        ->assertHasNoTableActionErrors();

    $claim = InfluencerClaim::where('influencer_id', $influencer->id)->where('user_id', $user->id)->firstOrFail();
    expect($influencer->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($user->fresh()->is_influencer)->toBeTrue()
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and($claim->method)->toBe(ClaimMethod::Admin)
        ->and($claim->reviewed_by_user_id)->toBe($admin->id);
    Event::assertDispatched(InfluencerClaimed::class, fn ($e) => $e->influencer->id === $influencer->id && $e->user->id === $user->id);
});

it('reassigns a claimed identity from the row, demoting the previous owner', function () {
    Event::fake([InfluencerClaimed::class]);
    $this->actingAs(User::factory()->admin()->create());

    $previous = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $previous->id, 'claimed_at' => now()])->save();
    User::whereKey($previous->id)->update(['is_influencer' => true]);
    InfluencerClaim::factory()->verified()->create(['influencer_id' => $influencer->id, 'user_id' => $previous->id]);

    $newOwner = User::factory()->create();

    Livewire::test(ListInfluencers::class)
        ->callTableAction('assign', $influencer, data: ['user_id' => $newOwner->id]);

    $newClaim = InfluencerClaim::where('influencer_id', $influencer->id)->where('user_id', $newOwner->id)->firstOrFail();
    expect($influencer->fresh()->claimed_by_user_id)->toBe($newOwner->id)
        ->and($newOwner->fresh()->is_influencer)->toBeTrue()
        ->and($newClaim->method)->toBe(ClaimMethod::Admin)
        ->and($previous->fresh()->is_influencer)->toBeFalse()
        ->and(InfluencerClaim::where('user_id', $previous->id)->first()->status)->toBe(ClaimStatus::Rejected);
    Event::assertDispatched(InfluencerClaimed::class, fn ($e) => $e->user->id === $newOwner->id);
});

it('preserves the original verification method (no re-fire) when re-assigning the current owner', function () {
    Event::fake([InfluencerClaimed::class]);
    $this->actingAs(User::factory()->admin()->create());

    $owner = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    $existing = InfluencerClaim::factory()->verified()->create([
        'influencer_id' => $influencer->id,
        'user_id' => $owner->id,
        'method' => ClaimMethod::Oauth,
    ]);

    Livewire::test(ListInfluencers::class)
        ->callTableAction('assign', $influencer, data: ['user_id' => $owner->id]);

    expect($existing->fresh()->method)->toBe(ClaimMethod::Oauth) // genuine audit method preserved
        ->and($existing->fresh()->status)->toBe(ClaimStatus::Verified);
    Event::assertNotDispatched(InfluencerClaimed::class); // ownership didn't move
});

it('overrides a prior sticky rejection when an admin assigns from the row', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $user = User::factory()->create();
    $influencer = Influencer::factory()->create();
    InfluencerClaim::factory()->create([
        'influencer_id' => $influencer->id,
        'user_id' => $user->id,
        'status' => ClaimStatus::Rejected,
        'reason' => 'rejected_by_admin',
        'reviewed_by_user_id' => User::factory()->admin()->create()->id,
        'token' => null,
    ]);

    Livewire::test(ListInfluencers::class)
        ->callTableAction('assign', $influencer, data: ['user_id' => $user->id]);

    $claim = InfluencerClaim::where('influencer_id', $influencer->id)->where('user_id', $user->id)->firstOrFail();
    expect($influencer->fresh()->claimed_by_user_id)->toBe($user->id)
        ->and($user->fresh()->is_influencer)->toBeTrue()
        ->and($claim->status)->toBe(ClaimStatus::Verified)
        ->and($claim->reviewed_by_user_id)->toBe($admin->id);
});

it('releases a claimed identity from the row and demotes the orphaned owner', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $owner = User::factory()->create();
    $influencer = Influencer::factory()->create();
    $influencer->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    User::whereKey($owner->id)->update(['is_influencer' => true]);
    $claim = InfluencerClaim::factory()->verified()->create(['influencer_id' => $influencer->id, 'user_id' => $owner->id]);

    Livewire::test(ListInfluencers::class)
        ->callTableAction('release', $influencer);

    expect($influencer->fresh()->claimed_by_user_id)->toBeNull()
        ->and($influencer->fresh()->claimed_at)->toBeNull()
        ->and($owner->fresh()->is_influencer)->toBeFalse()
        ->and($claim->fresh()->status)->toBe(ClaimStatus::Rejected)
        ->and($claim->fresh()->reason)->toBe('released_by_admin');
});

it('keeps the owner an influencer on release when they hold another identity', function () {
    $this->actingAs(User::factory()->admin()->create());

    $owner = User::factory()->create();
    $released = Influencer::factory()->create();
    $released->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    $kept = Influencer::factory()->create();
    $kept->forceFill(['claimed_by_user_id' => $owner->id, 'claimed_at' => now()])->save();
    User::whereKey($owner->id)->update(['is_influencer' => true]);

    Livewire::test(ListInfluencers::class)
        ->callTableAction('release', $released);

    expect($released->fresh()->claimed_by_user_id)->toBeNull()
        ->and($owner->fresh()->is_influencer)->toBeTrue(); // still owns $kept
});

it('hides the release action for an unclaimed identity', function () {
    $this->actingAs(User::factory()->admin()->create());

    $unclaimed = Influencer::factory()->create();

    Livewire::test(ListInfluencers::class)
        ->assertTableActionHidden('release', $unclaimed);
});

it('forbids a non-admin from the influencers panel', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin/influencers')->assertForbidden();
});
