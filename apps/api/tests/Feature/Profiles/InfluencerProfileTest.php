<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

/** Wire a place → published share → post credited to $influencer. */
function influencerPlace(Influencer $influencer, Place $place, string $shareStatus = 'published'): void
{
    $post = SourcePost::factory()->create(['influencer_id' => $influencer->id]);
    $share = Share::factory()->create([
        'source_post_id' => $post->id,
        'status' => $shareStatus,
        'published_at' => $shareStatus === 'published' ? now() : null,
    ]);
    $source = PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
    ]);
    if ($shareStatus === 'published') {
        $share->published_place_source_id = $source->id;
        $share->save();
    }
}

it('returns the influencer profile with claim status and promoted place count', function () {
    $claimer = User::factory()->create(['is_public' => true, 'username' => 'the-real-one']);
    $influencer = Influencer::factory()->create(['follower_count_cached' => 12345]);
    $influencer->forceFill(['claimed_by_user_id' => $claimer->id])->save();

    influencerPlace($influencer, Place::factory()->active()->atPoint(51.5, -0.13)->create());
    influencerPlace($influencer, Place::factory()->active()->atPoint(51.6, -0.14)->create());
    // Review-status share must not count.
    influencerPlace($influencer, Place::factory()->active()->atPoint(51.7, -0.15)->create(), shareStatus: 'review');

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}")->assertOk();

    $data = $res->json('data');
    expect($data['handle'])->toBe($influencer->handle)
        ->and($data['claimed'])->toBeTrue()
        ->and($data['claimed_by'])->toBe('the-real-one')
        ->and($data['follower_count'])->toBe(12345)
        ->and($data['counters']['promoted_places'])->toBe(2);

    expect($data)->not->toHaveKey('claimed_by_user_id');
    expect(ApiSchema::errors(ApiSchema::validate($data, 'influencer-profile')))->toBe([]);
});

it('withholds a private claimer and reports unclaimed correctly', function () {
    $private = User::factory()->create(['is_public' => false]);
    $claimed = Influencer::factory()->create();
    $claimed->forceFill(['claimed_by_user_id' => $private->id])->save();
    $unclaimed = Influencer::factory()->create();

    $this->getJson("/api/v1/influencers/{$claimed->id}")
        ->assertOk()
        ->assertJsonPath('data.claimed', true)
        ->assertJsonPath('data.claimed_by', null);

    $this->getJson("/api/v1/influencers/{$unclaimed->id}")
        ->assertOk()
        ->assertJsonPath('data.claimed', false)
        ->assertJsonPath('data.claimed_by', null);
});

it('serves the influencer map with only their published-share places', function () {
    $influencer = Influencer::factory()->create();
    $other = Influencer::factory()->create();

    $promoted = Place::factory()->active()->atPoint(51.5117, -0.1300)->create(['name' => 'Promoted']);
    $unrelated = Place::factory()->active()->atPoint(51.5000, -0.1000)->create(['name' => 'Unrelated']);
    $reviewOnly = Place::factory()->active()->atPoint(51.5200, -0.1200)->create(['name' => 'ReviewOnly']);

    influencerPlace($influencer, $promoted);
    influencerPlace($other, $unrelated);
    influencerPlace($influencer, $reviewOnly, shareStatus: 'review');

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16")->assertOk();

    $names = collect($res->json('data.pins'))->pluck('name');
    expect($names)->toContain('Promoted')->not->toContain('Unrelated', 'ReviewOnly');
    $res->assertJsonPath('meta.total_in_bbox', 1);
});

it('404s an unknown influencer and exposes rate-limit headers', function () {
    $this->getJson('/api/v1/influencers/999999')->assertStatus(404);

    $influencer = Influencer::factory()->create();
    $this->getJson("/api/v1/influencers/{$influencer->id}")
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});
