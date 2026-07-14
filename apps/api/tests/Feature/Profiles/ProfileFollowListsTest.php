<?php

use App\Models\Follow;
use App\Models\Influencer;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists a public profile\'s followers', function () {
    $owner = User::factory()->create(['username' => 'owner', 'is_public' => true]);
    $a = User::factory()->create(['username' => 'alice']);
    $b = User::factory()->create(['username' => 'bob']);
    Follow::create(['follower_user_id' => $a->id, 'followee_type' => 'user', 'followee_id' => $owner->id]);
    Follow::create(['follower_user_id' => $b->id, 'followee_type' => 'user', 'followee_id' => $owner->id]);

    $res = $this->getJson('/api/v1/users/owner/followers')->assertOk()->assertJsonCount(2, 'data');
    expect(collect($res->json('data'))->pluck('user.username')->sort()->values()->all())
        ->toEqual(['alice', 'bob']);
});

it('lists who a profile follows, including influencers', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $u = User::factory()->create(['username' => 'carol']);
    $inf = Influencer::factory()->create(['handle' => 'chefdan']);
    Follow::create(['follower_user_id' => $owner->id, 'followee_type' => 'user', 'followee_id' => $u->id]);
    Follow::create(['follower_user_id' => $owner->id, 'followee_type' => 'influencer', 'followee_id' => $inf->id]);

    $res = $this->getJson('/api/v1/users/owner/following')->assertOk()->assertJsonCount(2, 'data');
    $types = collect($res->json('data'))->pluck('followable_type')->sort()->values()->all();
    expect($types)->toEqual(['influencer', 'user']);
});

it('withholds a follower who has since gone private (edge stays, identity nulled)', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $priv = User::factory()->create(['username' => 'secret', 'is_public' => false]);
    Follow::create(['follower_user_id' => $priv->id, 'followee_type' => 'user', 'followee_id' => $owner->id]);

    $this->getJson('/api/v1/users/owner/followers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user', null);
});

it('404s the follower/following lists of a private profile for a stranger', function () {
    $owner = User::factory()->create(['username' => 'owner', 'is_public' => false]);
    $stranger = User::factory()->create();

    Sanctum::actingAs($stranger);
    $this->getJson('/api/v1/users/owner/followers')->assertNotFound();
    $this->getJson('/api/v1/users/owner/following')->assertNotFound();

    // The owner can see their own.
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/users/owner/followers')->assertOk();
});

it('paginates followers with a keyset cursor', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    foreach (range(1, 3) as $i) {
        $f = User::factory()->create();
        Follow::create(['follower_user_id' => $f->id, 'followee_type' => 'user', 'followee_id' => $owner->id]);
    }

    $first = $this->getJson('/api/v1/users/owner/followers?limit=2')->assertOk()->assertJsonCount(2, 'data');
    $cursor = $first->json('meta.pagination.next_cursor');
    expect($cursor)->toBeString();

    $this->getJson('/api/v1/users/owner/followers?limit=2&cursor='.urlencode($cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
