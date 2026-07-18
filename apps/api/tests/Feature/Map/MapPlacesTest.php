<?php

use App\Models\Follow;
use App\Models\HiddenPlace;
use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

// A small viewport over central London for all cases.
const BBOX = '-0.20,51.45,-0.05,51.55';

function activePlace(float $lat, float $lng, array $attrs = []): Place
{
    return Place::factory()->active()->atPoint($lat, $lng)->create($attrs);
}

it('returns raw pins (no clusters) at high zoom', function () {
    activePlace(51.5117, -0.1300, ['name' => 'Alpha', 'shares_count' => 3]);
    activePlace(51.5000, -0.1000, ['name' => 'Beta']);

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16')->assertOk();

    $res->assertJsonPath('meta.clustered', false)
        ->assertJsonPath('meta.total_in_bbox', 2)
        ->assertJsonCount(0, 'data.clusters')
        ->assertJsonCount(2, 'data.pins');

    $pin = collect($res->json('data.pins'))->firstWhere('name', 'Alpha');
    expect($pin)->toMatchArray(['type' => 'place', 'source_count' => 3, 'has_active_offer' => false, 'status' => 'active'])
        ->and(round($pin['lat'], 4))->toBe(51.5117)
        ->and(round($pin['lng'], 4))->toBe(-0.13);
});

it('grid-clusters co-located places at low zoom and leaves distant ones as pins', function () {
    // Two places in the same grid cell → one cluster.
    activePlace(51.5117, -0.1300, ['name' => 'C1']);
    activePlace(51.5118, -0.1301, ['name' => 'C2']);
    // A far-apart place in the same bbox → a singleton pin.
    activePlace(51.4700, -0.0700, ['name' => 'Lonely']);

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=12')->assertOk();

    $res->assertJsonPath('meta.clustered', true)
        ->assertJsonPath('meta.total_in_bbox', 3)
        ->assertJsonCount(1, 'data.clusters')
        ->assertJsonCount(1, 'data.pins');

    $cluster = $res->json('data.clusters.0');
    expect($cluster['type'])->toBe('cluster')
        ->and($cluster['count'])->toBe(2)
        ->and($cluster['cluster_id'])->toStartWith('12:')
        ->and($cluster['expand']['bbox'])->toHaveCount(4);
    expect($res->json('data.pins.0.name'))->toBe('Lonely');
});

it('applies cuisine and price_range filters and keeps total_in_bbox consistent', function () {
    activePlace(51.5117, -0.1300, ['name' => 'Thai Spot', 'cuisine_primary' => 'thai', 'price_range' => 2]);
    activePlace(51.5000, -0.1000, ['name' => 'Pizza Spot', 'cuisine_primary' => 'pizza', 'price_range' => 3]);

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&cuisine=thai')->assertOk();
    expect(collect($res->json('data.pins'))->pluck('name'))->toContain('Thai Spot')->not->toContain('Pizza Spot');
    expect($res->json('meta.total_in_bbox'))->toBe(1);

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&price_range=3')->assertOk();
    expect(collect($res->json('data.pins'))->pluck('name'))->toContain('Pizza Spot')->not->toContain('Thai Spot');
});

it('total_in_bbox equals pins + sum of cluster counts (clustered path)', function () {
    activePlace(51.5117, -0.1300);
    activePlace(51.5118, -0.1301); // same cell → cluster of 2
    activePlace(51.4700, -0.0700); // distinct cell → pin

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=12')->assertOk();
    $pins = count($res->json('data.pins'));
    $clustered = collect($res->json('data.clusters'))->sum('count');

    expect($pins + $clustered)->toBe($res->json('meta.total_in_bbox'));
});

it('excludes merged and pending-elsewhere places but includes pending in-view', function () {
    activePlace(51.5117, -0.1300, ['name' => 'Active']);
    Place::factory()->atPoint(51.5100, -0.1200)->create(['name' => 'PendingInView']); // pending default
    Place::factory()->active()->atPoint(51.5100, -0.1200)->create(['name' => 'Merged', 'status' => 'merged']);
    activePlace(60.0, 10.0, ['name' => 'OutOfView']);

    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16')->assertOk()->json('data.pins'))
        ->pluck('name');

    expect($names)->toContain('Active', 'PendingInView')
        ->not->toContain('Merged', 'OutOfView');
});

it('surfaces the primary source influencer as top_influencer', function () {
    $place = activePlace(51.5117, -0.1300, ['name' => 'WithInfluencer']);
    $share = Share::factory()->create();
    $share->sourcePost->influencer()->associate(Influencer::factory()->create(['handle' => 'noodle.hunter', 'display_name' => 'Noodle Hunter']));
    $share->sourcePost->save();
    PlaceSource::factory()->primary()->create([
        'place_id' => $place->id,
        'share_id' => $share->id,
        'source_post_id' => $share->source_post_id,
    ]);

    $pin = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16')->json('data.pins'))
        ->firstWhere('name', 'WithInfluencer');

    expect($pin['top_influencer'])->toMatchArray(['handle' => 'noodle.hunter', 'display_name' => 'Noodle Hunter']);
});

it('exposes the primary source poster as thumbnail_url, and null when there is none', function () {
    // A place whose primary source post carries an oEmbed poster.
    $withPhoto = activePlace(51.5117, -0.1300, ['name' => 'WithPhoto']);
    $share = Share::factory()->create();
    $share->sourcePost->update(['oembed_json' => ['thumbnail_url' => 'https://cdn.example/reel.jpg']]);
    PlaceSource::factory()->primary()->create([
        'place_id' => $withPhoto->id,
        'share_id' => $share->id,
        'source_post_id' => $share->source_post_id,
    ]);

    // A place with no source at all → no imagery.
    activePlace(51.5000, -0.1000, ['name' => 'NoPhoto']);

    $pins = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16')->json('data.pins'));

    expect($pins->firstWhere('name', 'WithPhoto')['thumbnail_url'])->toBe('https://cdn.example/reel.jpg')
        ->and($pins->firstWhere('name', 'NoPhoto')['thumbnail_url'])->toBeNull();
});

it('filters to the caller’s own places with filter=mine — shared ∪ saved (T-071)', function () {
    $user = User::factory()->create();
    $mine = activePlace(51.5117, -0.1300, ['name' => 'Mine']);
    $saved = activePlace(51.5050, -0.1200, ['name' => 'Saved']);
    activePlace(51.5000, -0.1000, ['name' => 'Someone else']);

    // Shared: a PUBLISHED share of mine resolving to the place.
    $share = Share::factory()->for($user)->published()->create();
    PlaceSource::factory()->create(['place_id' => $mine->id, 'share_id' => $share->id, 'source_post_id' => $share->source_post_id]);

    // Saved: the place sits in one of my lists (no share of mine).
    $list = PlaceList::factory()->for($user)->create();
    $list->items()->create(['place_id' => $saved->id, 'position' => 1]);

    Sanctum::actingAs($user);
    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=mine')->assertOk()->json('data.pins'))
        ->pluck('name');

    expect($names)->toContain('Mine')->toContain('Saved')->not->toContain('Someone else');
});

it('excludes a place I soft-hid from filter=mine (per-place hide, T-071)', function () {
    $user = User::factory()->create();
    $hidden = activePlace(51.5117, -0.1300, ['name' => 'Hidden']);

    $share = Share::factory()->for($user)->published()->create();
    PlaceSource::factory()->create(['place_id' => $hidden->id, 'share_id' => $share->id, 'source_post_id' => $share->source_post_id]);
    // "Remove from my collection" (T-071) is a per-place hide.
    HiddenPlace::create(['user_id' => $user->id, 'place_id' => $hidden->id]);

    Sanctum::actingAs($user);
    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=mine')->assertOk()->json('data.pins'))
        ->pluck('name');

    expect($names)->not->toContain('Hidden');
});

it('does not count a non-published share toward filter=mine (T-071)', function () {
    $user = User::factory()->create();
    $pending = activePlace(51.5117, -0.1300, ['name' => 'PendingShare']);

    // A share still in review — no published contribution yet.
    $share = Share::factory()->for($user)->review()->create();
    PlaceSource::factory()->create(['place_id' => $pending->id, 'share_id' => $share->id, 'source_post_id' => $share->source_post_id]);

    Sanctum::actingAs($user);
    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=mine')->assertOk()->json('data.pins'))
        ->pluck('name');

    expect($names)->not->toContain('PendingShare');
});

it('requires auth for filter=mine and filter=following', function () {
    $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=mine')->assertStatus(401);
    $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=following')->assertStatus(401);
});

it('filters to followed accounts with filter=following (T-037)', function () {
    $me = User::factory()->create();
    $followedUser = User::factory()->create(['is_public' => true]);
    $followedInfluencer = Influencer::factory()->create();

    // Place A: shared (and PUBLISHED — attribution requires it) by a followed user.
    $a = activePlace(51.5117, -0.1300, ['name' => 'ByFollowedUser']);
    $shareA = Share::factory()->for($followedUser)->create(['status' => 'published', 'published_at' => now()]);
    PlaceSource::factory()->create(['place_id' => $a->id, 'share_id' => $shareA->id, 'source_post_id' => $shareA->source_post_id]);

    // Place B: credited to a followed influencer (shared by a stranger).
    $b = activePlace(51.5000, -0.1000, ['name' => 'ByFollowedInfluencer']);
    $shareB = Share::factory()->create(['status' => 'published', 'published_at' => now()]);
    $shareB->sourcePost->influencer()->associate($followedInfluencer);
    $shareB->sourcePost->save();
    PlaceSource::factory()->create(['place_id' => $b->id, 'share_id' => $shareB->id, 'source_post_id' => $shareB->source_post_id]);

    // Place C: unrelated.
    activePlace(51.5200, -0.1100, ['name' => 'Unrelated']);

    Follow::create(['follower_user_id' => $me->id, 'followee_type' => 'user', 'followee_id' => $followedUser->id]);
    Follow::create(['follower_user_id' => $me->id, 'followee_type' => 'influencer', 'followee_id' => $followedInfluencer->id]);

    Sanctum::actingAs($me);
    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&filter=following')->assertOk()->json('data.pins'))
        ->pluck('name');

    expect($names)->toContain('ByFollowedUser', 'ByFollowedInfluencer')
        ->not->toContain('Unrelated');
});

it('rejects a bad bbox with a validation_failed envelope', function () {
    $this->getJson('/api/v1/map/places?bbox=-0.05,51.45,-0.20,51.55&zoom=12') // maxLng < minLng
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');

    $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=99')
        ->assertStatus(422);

    // A globe-spanning bbox would make an invalid geography envelope → guarded.
    $this->getJson('/api/v1/map/places?bbox=-180,-90,180,90&zoom=3')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('exposes rate-limit headers', function () {
    $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});

it('filters map pins by tags[] via the pivot (live since T-031)', function () {
    $tagged = activePlace(51.5117, -0.1300, ['name' => 'Tagged']);
    activePlace(51.5000, -0.1000, ['name' => 'Untagged']);
    $tag = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $tagged->tags()->attach($tag->id, ['source' => 'extraction']);

    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&tags[]=ramen')->assertOk();

    $names = collect($res->json('data.pins'))->pluck('name');
    expect($names)->toContain('Tagged')->not->toContain('Untagged');
    $res->assertJsonPath('meta.total_in_bbox', 1);

    // The pin payload itself now carries the tag slugs (was [] until T-031).
    $pin = collect($res->json('data.pins'))->firstWhere('name', 'Tagged');
    expect($pin['tags'])->toBe(['ramen']);
});

it('AND-filters map pins when multiple tags[] are given', function () {
    $both = activePlace(51.5117, -0.1300, ['name' => 'Both']);
    $ramenOnly = activePlace(51.5100, -0.1200, ['name' => 'RamenOnly']);
    $ramen = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $sushi = Tag::factory()->create(['slug' => 'sushi', 'name' => 'Sushi']);
    $both->tags()->attach([$ramen->id => ['source' => 'extraction'], $sushi->id => ['source' => 'extraction']]);
    $ramenOnly->tags()->attach($ramen->id, ['source' => 'extraction']);

    // Selecting two tags narrows to pins carrying BOTH — not either one.
    $res = $this->getJson('/api/v1/map/places?bbox='.BBOX.'&zoom=16&tags[]=ramen&tags[]=sushi')->assertOk();

    $names = collect($res->json('data.pins'))->pluck('name');
    expect($names)->toContain('Both')->not->toContain('RamenOnly');
    $res->assertJsonPath('meta.total_in_bbox', 1);
});

it('restricts the map to an owned list when ?list is given', function () {
    $user = User::factory()->create();
    $inList = activePlace(51.50, -0.12, ['name' => 'In List']);
    activePlace(51.51, -0.13, ['name' => 'Not In List']);
    $list = PlaceList::factory()->for($user)->create();
    $list->items()->create(['place_id' => $inList->id, 'position' => 1]);

    Sanctum::actingAs($user);
    $names = collect($this->getJson('/api/v1/map/places?bbox='.BBOX."&zoom=16&list={$list->id}")->assertOk()->json('data.pins'))
        ->pluck('name');
    expect($names->all())->toEqual(['In List']);
});

it('requires auth for the list filter and 404s a list you do not own', function () {
    $list = PlaceList::factory()->create(); // someone else's
    $this->getJson('/api/v1/map/places?bbox='.BBOX."&zoom=16&list={$list->id}")->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/map/places?bbox='.BBOX."&zoom=16&list={$list->id}")->assertNotFound();
});
