<?php

use App\Enums\PlaceStatus;
use App\Enums\ShareStatus;
use App\Jobs\PublishShare;
use App\Jobs\ResolvePlace;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use App\Services\Places\PlacePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A share parked in `review` for the given reason, owned by $user. */
function bestGuessReview(User $user, string $reason, ?array $meta = null): Share
{
    return Share::factory()->for($user)->review()->create([
        'review_reason' => $reason,
        'review_meta_json' => $meta,
    ]);
}

it('publishes the best guess for a low-confidence review and flags it uncertain', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $share = bestGuessReview($user, 'low_confidence');

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertOk();

    $share->refresh();
    expect($share->status)->toBe(ShareStatus::Analyzing)
        ->and($share->flagged_uncertain)->toBeTrue()
        ->and($share->user_confirmed)->toBeFalse();
    // resumes the resolve→publish chain rather than dead-ending in review
    Bus::assertChained([ResolvePlace::class, PublishShare::class]);
});

it('attaches an ambiguous review to the strongest candidate', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $existing = Place::factory()->create(['status' => PlaceStatus::Active]);
    $share = bestGuessReview($user, 'ambiguous_place', [
        'candidates' => [
            ['place_id' => $existing->id, 'name' => 'Strong', 'similarity' => 0.9],
            ['place_id' => 999, 'name' => 'Weak', 'similarity' => 0.4],
        ],
    ]);

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertOk();

    // the highest-similarity candidate is stashed for ResolvePlace to attach to
    expect($share->refresh()->review_meta_json['picked_place_id'])->toBe($existing->id);
});

it('refuses to best-guess a geocode_failed review (no location to publish)', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $share = bestGuessReview($user, 'geocode_failed');

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertStatus(409);

    expect($share->refresh()->status)->toBe(ShareStatus::Review)
        ->and($share->flagged_uncertain)->toBeFalse();
    Bus::assertNothingDispatched();
});

it('refuses to best-guess an ambiguous review with no candidates', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $share = bestGuessReview($user, 'ambiguous_place', ['candidates' => []]);

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertStatus(409);
    expect($share->refresh()->flagged_uncertain)->toBeFalse();
});

it('refuses to best-guess a multi-place ambiguous review (the pick would be ignored and loop)', function () {
    Bus::fake();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $existing = Place::factory()->create(['status' => PlaceStatus::Active]);
    // two unresolved venues → PlaceResolver won't apply picked_place_id (single-place
    // only), so this must NOT be best-guessable (else it re-parks and loops forever).
    $share = bestGuessReview($user, 'ambiguous_place', [
        'pending' => [
            ['index' => 0, 'name' => 'A', 'candidates' => [['place_id' => $existing->id, 'similarity' => 0.9]]],
            ['index' => 1, 'name' => 'B', 'candidates' => []],
        ],
        'candidates' => [['place_id' => $existing->id, 'similarity' => 0.9]],
    ]);

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertStatus(409);
    $this->getJson("/api/v1/shares/{$share->id}")
        ->assertOk()->assertJsonPath('data.can_publish_best_guess', false);
    Bus::assertNothingDispatched();
});

it('forbids publishing another user’s share as-is', function () {
    Bus::fake();
    $owner = User::factory()->create();
    Sanctum::actingAs(User::factory()->create());
    $share = bestGuessReview($owner, 'low_confidence');

    $this->postJson("/api/v1/shares/{$share->id}/publish-best-guess")->assertStatus(403);
    expect($share->refresh()->flagged_uncertain)->toBeFalse();
});

it('exposes can_publish_best_guess on the share resource', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $lowConf = bestGuessReview($user, 'low_confidence');
    $this->getJson("/api/v1/shares/{$lowConf->id}")
        ->assertOk()->assertJsonPath('data.can_publish_best_guess', true);

    $geocode = bestGuessReview($user, 'geocode_failed');
    $this->getJson("/api/v1/shares/{$geocode->id}")
        ->assertOk()->assertJsonPath('data.can_publish_best_guess', false);
});

it('flags the place needs_admin_review for a best-guess publish, and clears it on confirm', function () {
    $place = Place::factory()->create(['status' => PlaceStatus::Pending, 'needs_admin_review' => false]);

    // a plain confident publish (neither flagged nor confirmed) leaves it false
    $confidentShare = Share::factory()->create(['flagged_uncertain' => false, 'user_confirmed' => false]);
    $confidentSource = PlaceSource::factory()->create([
        'place_id' => $place->id, 'share_id' => $confidentShare->id, 'published_at' => now(),
    ]);
    app(PlacePublisher::class)->recompute($place->fresh(), $confidentShare, $confidentSource);
    expect($place->fresh()->needs_admin_review)->toBeFalse();

    // best-guess (skip/abandon) → flagged
    $guessShare = Share::factory()->create(['flagged_uncertain' => true, 'user_confirmed' => false]);
    $guessSource = PlaceSource::factory()->create([
        'place_id' => $place->id, 'share_id' => $guessShare->id, 'published_at' => now(),
    ]);
    app(PlacePublisher::class)->recompute($place->fresh(), $guessShare, $guessSource);
    expect($place->fresh()->needs_admin_review)->toBeTrue();

    // a later user-confirmed source resolves it → flag cleared
    $confirmShare = Share::factory()->create(['flagged_uncertain' => false, 'user_confirmed' => true]);
    $confirmSource = PlaceSource::factory()->create([
        'place_id' => $place->id, 'share_id' => $confirmShare->id, 'published_at' => now(),
    ]);
    app(PlacePublisher::class)->recompute($place->fresh(), $confirmShare, $confirmSource);
    expect($place->fresh()->needs_admin_review)->toBeFalse();
});

it('sweeps abandoned low-confidence and single-place ambiguous reviews, leaving fresh ones and non-placeable reasons', function () {
    Bus::fake();
    $user = User::factory()->create();
    $existing = Place::factory()->create(['status' => PlaceStatus::Active]);

    $lowConf = bestGuessReview($user, 'low_confidence');
    $lowConf->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

    $ambiguous = bestGuessReview($user, 'ambiguous_place', [
        'candidates' => [['place_id' => $existing->id, 'similarity' => 0.9]],
    ]);
    $ambiguous->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

    $fresh = bestGuessReview($user, 'low_confidence'); // updated_at = now → not idle
    $geocode = bestGuessReview($user, 'geocode_failed');
    $geocode->forceFill(['updated_at' => now()->subMinutes(30)])->saveQuietly();

    $this->artisan('reelmap:reviews:publish-abandoned')->assertSuccessful();

    // both placeable idle reviews published; the ambiguous one attached to its candidate
    expect($lowConf->refresh()->status)->toBe(ShareStatus::Analyzing)
        ->and($lowConf->flagged_uncertain)->toBeTrue()
        ->and($ambiguous->refresh()->status)->toBe(ShareStatus::Analyzing)
        ->and($ambiguous->review_meta_json['picked_place_id'])->toBe($existing->id)
        ->and($fresh->refresh()->status)->toBe(ShareStatus::Review)
        ->and($geocode->refresh()->status)->toBe(ShareStatus::Review);
});
