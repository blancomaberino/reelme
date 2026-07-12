<?php

use App\Models\Follow;
use App\Models\Influencer;
use App\Models\User;
use App\Notifications\NewFollower;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('follows a user: edge + counters + notification, morph alias in the DB', function () {
    Notification::fake();
    $me = User::factory()->create();
    $target = User::factory()->create(['is_public' => true]);

    Sanctum::actingAs($me);
    $res = $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])
        ->assertStatus(201);

    expect($res->json('data.id'))->not->toBeNull();
    $this->assertDatabaseHas('follows', [
        'follower_user_id' => $me->id,
        'followee_type' => 'user', // alias, never FQCN
        'followee_id' => $target->id,
    ]);
    expect($me->fresh()->following_count)->toBe(1)
        ->and($target->fresh()->followers_count)->toBe(1);
    Notification::assertSentTo($target, NewFollower::class);
});

it('follows an influencer and notifies the claimer only when claimed', function () {
    Notification::fake();
    $me = User::factory()->create();
    $claimer = User::factory()->create();
    $claimed = Influencer::factory()->create();
    $claimed->forceFill(['claimed_by_user_id' => $claimer->id])->save();
    $unclaimed = Influencer::factory()->create();

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/follows', ['followable_type' => 'influencer', 'followable_id' => $claimed->id])->assertStatus(201);
    $this->postJson('/api/v1/follows', ['followable_type' => 'influencer', 'followable_id' => $unclaimed->id])->assertStatus(201);

    expect($claimed->fresh()->followers_count)->toBe(1)
        ->and($unclaimed->fresh()->followers_count)->toBe(1)
        ->and($me->fresh()->following_count)->toBe(2);
    Notification::assertSentTo($claimer, NewFollower::class);
    Notification::assertCount(1); // the unclaimed influencer notified no one
});

it('writes a real database notification (unfaked)', function () {
    $me = User::factory()->create(['username' => 'thefollower']);
    $target = User::factory()->create(['is_public' => true]);

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])->assertStatus(201);

    $row = $target->notifications()->first();
    expect($row)->not->toBeNull()
        ->and($row->data['type'])->toBe('social.follow')
        ->and($row->data['follower_username'])->toBe('thefollower');
});

it('rejects self-follow, directly and via own claimed influencer', function () {
    $me = User::factory()->create(['is_public' => true]);
    $myInfluencer = Influencer::factory()->create();
    $myInfluencer->forceFill(['claimed_by_user_id' => $me->id])->save();

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $me->id])->assertStatus(422);
    $this->postJson('/api/v1/follows', ['followable_type' => 'influencer', 'followable_id' => $myInfluencer->id])->assertStatus(422);
});

it('409s a duplicate follow with the existing id, without double-counting', function () {
    $me = User::factory()->create();
    $target = User::factory()->create(['is_public' => true]);

    Sanctum::actingAs($me);
    $first = $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])->assertStatus(201);
    $dup = $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])->assertStatus(409);

    expect($dup->json('data.id'))->toBe($first->json('data.id'))
        ->and($target->fresh()->followers_count)->toBe(1)
        ->and(Follow::count())->toBe(1);
});

it('404s unknown and private targets; 422s unknown types; 401 unauthenticated', function () {
    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => 1])->assertStatus(401);

    Sanctum::actingAs(User::factory()->create());
    $private = User::factory()->create(['is_public' => false]);

    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => 999999])->assertStatus(404);
    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $private->id])->assertStatus(404);
    $this->postJson('/api/v1/follows', ['followable_type' => 'place', 'followable_id' => 1])->assertStatus(422);
});

it('unfollows own edge (counters roll back); denies someone else’s with 403', function () {
    $me = User::factory()->create();
    $target = User::factory()->create(['is_public' => true]);

    Sanctum::actingAs($me);
    $id = $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])->json('data.id');

    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger);
    $this->deleteJson("/api/v1/follows/{$id}")->assertStatus(403);

    Sanctum::actingAs($me);
    $this->deleteJson("/api/v1/follows/{$id}")->assertOk();

    expect(Follow::count())->toBe(0)
        ->and($me->fresh()->following_count)->toBe(0)
        ->and($target->fresh()->followers_count)->toBe(0);
});

it('lists who I follow, cursor-paginated with typed followees', function () {
    $me = User::factory()->create();
    $u = User::factory()->create(['is_public' => true, 'username' => 'followee-user']);
    $i = Influencer::factory()->create();

    Sanctum::actingAs($me);
    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $u->id])->assertStatus(201);
    $this->postJson('/api/v1/follows', ['followable_type' => 'influencer', 'followable_id' => $i->id])->assertStatus(201);

    $page1 = $this->getJson('/api/v1/me/follows?limit=1')->assertOk();
    $page2 = $this->getJson('/api/v1/me/follows?limit=1&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();

    $all = collect([...$page1->json('data'), ...$page2->json('data')]);
    expect($all)->toHaveCount(2)
        ->and($all->pluck('followable_type')->all())->toEqualCanonicalizing(['user', 'influencer'])
        ->and($all->firstWhere('followable_type', 'user')['followee']['username'])->toBe('followee-user')
        ->and($all->firstWhere('followable_type', 'influencer')['followee']['handle'])->toBe($i->handle)
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();
});

it('reflects follow state in profile meta.viewer and live counters', function () {
    $me = User::factory()->create();
    $target = User::factory()->create(['is_public' => true, 'username' => 'startlet']);

    Sanctum::actingAs($me);
    $before = $this->getJson('/api/v1/users/startlet')->assertOk();
    expect($before->json('meta.viewer.following'))->toBeFalse();

    $this->postJson('/api/v1/follows', ['followable_type' => 'user', 'followable_id' => $target->id])->assertStatus(201);

    $after = $this->getJson('/api/v1/users/startlet')->assertOk();
    expect($after->json('meta.viewer.following'))->toBeTrue()
        ->and($after->json('meta.viewer.follow_id'))->not->toBeNull()
        ->and($after->json('data.profile.counters.followers'))->toBe(1);
});
