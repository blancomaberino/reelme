<?php

use App\Http\Controllers\Api\V1\InfluencerController;
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

it('lists the influencer’s places, the same set the map draws', function () {
    $influencer = Influencer::factory()->create();
    $other = Influencer::factory()->create();

    $promoted = Place::factory()->active()->atPoint(51.5117, -0.1300)->create(['name' => 'Promoted']);
    $unrelated = Place::factory()->active()->atPoint(51.5000, -0.1000)->create(['name' => 'Unrelated']);
    $reviewOnly = Place::factory()->active()->atPoint(51.5200, -0.1200)->create(['name' => 'ReviewOnly']);

    influencerPlace($influencer, $promoted);
    influencerPlace($other, $unrelated);
    influencerPlace($influencer, $reviewOnly, shareStatus: 'review');

    // The LIST endpoint the profile and its map screen read. A viewport cannot
    // express "everywhere this creator has posted", and the attempt to send one
    // as a whole-globe bbox is what left the map permanently empty.
    $names = collect($this->getJson("/api/v1/influencers/{$influencer->id}/places")->assertOk()->json('data'))
        ->pluck('name');

    expect($names)->toContain('Promoted')->not->toContain('Unrelated', 'ReviewOnly');
});

it('makes the counter, the list and the map agree by construction', function () {
    $influencer = Influencer::factory()->create();

    // Two places in ONE bbox so the map can be compared directly, plus a
    // review-status share that none of the three may count.
    $a = Place::factory()->active()->atPoint(51.5117, -0.1300)->create(['name' => 'A']);
    $b = Place::factory()->active()->atPoint(51.5150, -0.1250)->create(['name' => 'B']);
    $excluded = Place::factory()->active()->atPoint(51.5180, -0.1220)->create(['name' => 'Excluded']);

    influencerPlace($influencer, $a);
    influencerPlace($influencer, $b);
    influencerPlace($influencer, $excluded, shareStatus: 'review');

    $counter = $this->getJson("/api/v1/influencers/{$influencer->id}")->assertOk()
        ->json('data.counters.promoted_places');
    $list = $this->getJson("/api/v1/influencers/{$influencer->id}/places")->assertOk()->json('data');
    $map = $this->getJson("/api/v1/influencers/{$influencer->id}/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16")
        ->assertOk()->json('data.pins');

    // THE regression. These were three hand-rolled queries — a counter join, a
    // map whereExists, and nothing at all for a list — agreeing only by
    // coincidence. The profile ended up claiming "2 Lugares" one tap above a
    // map that said the creator had none. They now share
    // PlaceQueryBuilder::promotedBy(), so disagreeing requires editing it.
    expect($counter)->toBe(2)
        ->and($list)->toHaveCount(2)
        ->and($map)->toHaveCount(2)
        ->and(collect($list)->pluck('name')->sort()->values()->all())
        ->toBe(collect($map)->pluck('name')->sort()->values()->all());
});

it('caps the counter at the same ceiling the list can reach, and says so', function () {
    $influencer = Influencer::factory()->create();

    // One over the cap. Capping the CLIENT alone — which the first cut did —
    // does not remove the counter-vs-list contradiction, it moves it: the
    // profile would read "201 Lugares" over a list that stops at 200.
    foreach (range(1, InfluencerController::PLACES_CAP + 1) as $n) {
        influencerPlace($influencer, Place::factory()->active()->create(['name' => "P{$n}"]));
    }

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}")->assertOk();

    expect($res->json('data.counters.promoted_places'))->toBe(InfluencerController::PLACES_CAP)
        // Published, not merely applied: the client pages this list itself and
        // has to know where to stop, and a UI showing the ceiling should be
        // able to render "200+" rather than a flat, wrong 200.
        ->and($res->json('meta.places_cap'))->toBe(InfluencerController::PLACES_CAP)
        ->and($res->json('meta.places_capped'))->toBeTrue();
});

it('is not "capped" at exactly the cap', function () {
    $influencer = Influencer::factory()->create();

    foreach (range(1, InfluencerController::PLACES_CAP) as $n) {
        influencerPlace($influencer, Place::factory()->active()->create(['name' => "P{$n}"]));
    }

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}")->assertOk();

    // THE boundary. `> CAP` and `>= CAP` both pass the CAP+1 and the 1-place
    // tests; only exactly-CAP tells them apart. At the ceiling the count is
    // complete, so a "+" would be a lie about data we have in full.
    expect($res->json('data.counters.promoted_places'))->toBe(InfluencerController::PLACES_CAP)
        ->and($res->json('meta.places_capped'))->toBeFalse();
});

it('does not claim it is capped when it is not', function () {
    $influencer = Influencer::factory()->create();
    influencerPlace($influencer, Place::factory()->active()->create());

    $res = $this->getJson("/api/v1/influencers/{$influencer->id}")->assertOk();

    // The flag drives a "+" in the UI; a permanently-true one would put it on
    // every creator with a single place.
    expect($res->json('data.counters.promoted_places'))->toBe(1)
        ->and($res->json('meta.places_capped'))->toBeFalse();
});

it('404s an unknown influencer and exposes rate-limit headers', function () {
    $this->getJson('/api/v1/influencers/999999')->assertStatus(404);

    $influencer = Influencer::factory()->create();
    $this->getJson("/api/v1/influencers/{$influencer->id}")
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});
