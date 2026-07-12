<?php

use App\Models\Place;
use App\Models\Share;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

it('returns the public profile with counters and only PUBLISHED shares', function () {
    $user = User::factory()->create(['is_public' => true, 'username' => 'foodie', 'bio' => 'I eat.']);
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();

    $published = publishedShare($place, sharer: $user);
    // One share in every non-published status — none may appear.
    foreach (['pending', 'fetching', 'analyzing', 'review', 'failed', 'rejected'] as $status) {
        Share::factory()->create(['user_id' => $user->id, 'status' => $status]);
    }

    $res = $this->getJson('/api/v1/users/foodie')->assertOk();

    $profile = $res->json('data.profile');
    expect($profile['username'])->toBe('foodie')
        ->and($profile['bio'])->toBe('I eat.')
        ->and($profile['counters']['published_shares'])->toBe(1)
        ->and($profile['counters'])->toHaveKeys(['followers', 'following']);

    // Never leak private fields.
    expect($profile)->not->toHaveKeys(['email', 'is_admin', 'is_restaurant_owner', 'preferred_analysis_model']);

    $shares = $res->json('data.shares');
    expect($shares)->toHaveCount(1)
        ->and($shares[0]['id'])->toBe((string) $published->id)
        ->and($shares[0]['place']['name'])->toBe($place->name);

    // Contract: profile validates against user-profile.json.
    expect(ApiSchema::errors(ApiSchema::validate($profile, 'user-profile')))->toBe([]);
});

it('404s private profiles for strangers and guests but not the owner', function () {
    $private = User::factory()->create(['is_public' => false, 'username' => 'hermit']);

    $this->getJson('/api/v1/users/hermit')->assertStatus(404);
    $this->getJson('/api/v1/users/hermit/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16')->assertStatus(404);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/users/hermit')->assertStatus(404);
    $this->getJson('/api/v1/users/hermit/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16')->assertStatus(404);

    Sanctum::actingAs($private);
    $this->getJson('/api/v1/users/hermit')->assertOk();
    $this->getJson('/api/v1/users/hermit/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16')->assertOk();
});

it('never leaks private-account existence through validation errors (404, not 422)', function () {
    User::factory()->create(['is_public' => false, 'username' => 'hermit2']);

    // Invalid params would 422 BEFORE the controller runs — the privacy gate
    // must fire first so private and nonexistent are indistinguishable.
    $this->getJson('/api/v1/users/hermit2?limit=0')->assertStatus(404);
    $this->getJson('/api/v1/users/never-there?limit=0')->assertStatus(404);
    $this->getJson('/api/v1/users/hermit2/map?bbox=bad&zoom=99')->assertStatus(404);
    $this->getJson('/api/v1/users/never-there/map?bbox=bad&zoom=99')->assertStatus(404);
    $this->getJson('/api/v1/users/hermit2/map')->assertStatus(404);
});

it('rejects a feed-tagged cursor on the profile share list', function () {
    User::factory()->create(['is_public' => true, 'username' => 'walker']);

    $crafted = rtrim(strtr(base64_encode((string) json_encode(['s' => 'feed', 'k' => ['2026-07-11 10:00:00.000000', 1]])), '+/', '-_'), '=');
    $this->getJson('/api/v1/users/walker?cursor='.urlencode($crafted))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('404s soft-deleted (banned) users and unknown usernames', function () {
    $banned = User::factory()->create(['is_public' => true, 'username' => 'gone']);
    $banned->delete();

    $this->getJson('/api/v1/users/gone')->assertStatus(404);
    $this->getJson('/api/v1/users/never-existed')->assertStatus(404);
});

it('binds usernames case-insensitively (citext)', function () {
    User::factory()->create(['is_public' => true, 'username' => 'CamelCase']);

    $this->getJson('/api/v1/users/camelcase')->assertOk();
});

it('paginates the share list by cursor', function () {
    $user = User::factory()->create(['is_public' => true, 'username' => 'poster']);
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    foreach (range(1, 3) as $i) {
        publishedShare($place, sharer: $user, publishedAt: now()->subMinutes($i)->toDateTimeString());
    }

    $page1 = $this->getJson('/api/v1/users/poster?limit=2')->assertOk();
    expect($page1->json('data.shares'))->toHaveCount(2);

    $page2 = $this->getJson('/api/v1/users/poster?limit=2&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();
    expect($page2->json('data.shares'))->toHaveCount(1)
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();

    $ids = collect([...$page1->json('data.shares'), ...$page2->json('data.shares')])->pluck('id');
    expect($ids->unique())->toHaveCount(3);
});

it('serves the user map with only their published places, in the map shape', function () {
    $user = User::factory()->create(['is_public' => true, 'username' => 'mapper']);
    $mine = Place::factory()->active()->atPoint(51.5117, -0.1300)->create(['name' => 'Mine']);
    $theirs = Place::factory()->active()->atPoint(51.5000, -0.1000)->create(['name' => 'Theirs']);

    publishedShare($mine, sharer: $user);
    publishedShare($theirs); // someone else's share

    $res = $this->getJson('/api/v1/users/mapper/map?bbox=-0.20,51.45,-0.05,51.55&zoom=16')->assertOk();

    $names = collect($res->json('data.pins'))->pluck('name');
    expect($names)->toContain('Mine')->not->toContain('Theirs');
    $res->assertJsonPath('meta.total_in_bbox', 1)
        ->assertJsonPath('meta.clustered', false);
});

it('exposes rate-limit headers', function () {
    User::factory()->create(['is_public' => true, 'username' => 'limited']);

    $this->getJson('/api/v1/users/limited')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});
