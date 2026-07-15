<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A partially-published multi-place share (T-013/T-071): one venue published,
 * one still in review_meta_json.pending[]. The share is `published`, so the
 * whole-share review route can't touch the pending venue — these endpoints can.
 */
function partiallyPublishedShare(User $user, Place $candidate, int $pendingIndex = 1): Share
{
    $share = Share::factory()->for($user)->published()->create();

    $primary = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Resolved']);
    $source = PlaceSource::factory()->create([
        'share_id' => $share->id,
        'place_id' => $primary->id,
        'source_post_id' => $share->source_post_id,
        'is_primary' => true,
        'published_at' => now(),
    ]);

    $share->published_place_source_id = $source->id;
    $share->review_meta_json = ['pending' => [[
        'index' => $pendingIndex,
        'name' => $candidate->name,
        'reason' => 'ambiguous_place',
        'candidates' => [[
            'place_id' => $candidate->id,
            'name' => $candidate->name,
            'distance_m' => 40.0,
            'similarity' => 0.92,
        ]],
    ]]];
    $share->save();

    return $share;
}

it('exposes pending venues with their candidates on the share', function () {
    $user = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create(['name' => 'Chiado']);
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $this->getJson("/api/v1/shares/{$share->id}")->assertOk()
        ->assertJsonPath('data.pending_place_count', 1)
        ->assertJsonPath('data.pending_places.0.index', 1)
        ->assertJsonPath('data.pending_places.0.name', 'Chiado')
        ->assertJsonPath('data.pending_places.0.candidates.0.place_id', (string) $candidate->id);
});

it('resolves a pending venue — publishes the picked candidate and drops the entry', function () {
    $user = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create(['name' => 'Chiado']);
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $res = $this->postJson("/api/v1/shares/{$share->id}/pending/1/resolve", ['place_id' => $candidate->id])
        ->assertOk()
        ->assertJsonPath('data.pending_place_count', 0);

    // A published place_source now links the share to the candidate.
    $this->assertDatabaseHas('place_sources', [
        'share_id' => $share->id,
        'place_id' => $candidate->id,
    ]);
    $source = PlaceSource::where('share_id', $share->id)->where('place_id', $candidate->id)->first();
    expect($source->published_at)->not->toBeNull();

    // It appears among the share's published places now.
    $names = collect($res->json('data.places'))->pluck('name');
    expect($names)->toContain('Chiado');
});

it('rejects a candidate that the pending venue did not offer (422)', function () {
    $user = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create(['name' => 'Chiado']);
    $stranger = Place::factory()->active()->atPoint(51.52, -0.15)->create(['name' => 'Elsewhere']);
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/pending/1/resolve", ['place_id' => $stranger->id])
        ->assertStatus(422)
        ->assertJsonPath('error.details.place_id.0', 'The selected place is not among this venue’s candidates.');

    $this->assertDatabaseMissing('place_sources', ['share_id' => $share->id, 'place_id' => $stranger->id]);
});

it('404s an unknown pending index', function () {
    $user = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create();
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/pending/99/resolve", ['place_id' => $candidate->id])
        ->assertStatus(404);
});

it('dismisses a pending venue without publishing it', function () {
    $user = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create(['name' => 'Chiado']);
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $this->deleteJson("/api/v1/shares/{$share->id}/pending/1")->assertOk()
        ->assertJsonPath('data.pending_place_count', 0);

    $this->assertDatabaseMissing('place_sources', ['share_id' => $share->id, 'place_id' => $candidate->id]);
});

it('forbids resolving another user’s share (403)', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $candidate = Place::factory()->active()->atPoint(51.51, -0.14)->create();
    $share = partiallyPublishedShare($owner, $candidate);

    Sanctum::actingAs($intruder);
    $this->postJson("/api/v1/shares/{$share->id}/pending/1/resolve", ['place_id' => $candidate->id])
        ->assertStatus(403);
});

it('activates a still-pending candidate once it gains a second published source', function () {
    $user = User::factory()->create();
    // Candidate already has ONE published source from someone else → pending.
    $candidate = Place::factory()->atPoint(51.51, -0.14)->create(['name' => 'Chiado', 'status' => PlaceStatus::Pending]);
    PlaceSource::factory()->create([
        'place_id' => $candidate->id,
        'source_post_id' => Share::factory()->published()->create()->source_post_id,
        'share_id' => Share::factory()->published()->create()->id,
        'published_at' => now(),
    ]);
    $share = partiallyPublishedShare($user, $candidate);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/shares/{$share->id}/pending/1/resolve", ['place_id' => $candidate->id])->assertOk();

    // Second independent published source → the place activates (04 §6.4).
    expect($candidate->fresh()->status)->toBe(PlaceStatus::Active);
});
