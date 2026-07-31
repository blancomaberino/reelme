<?php

use App\Models\Follow;
use App\Models\Place;
use App\Models\User;
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

/**
 * Contract tests (T-102): GET /feed rows must validate against
 * packages/contracts/schemas/feed-item.json — the file `FeedItem` is generated
 * from. publishedShare() lives in tests/Helpers/PipelineHelpers.php.
 */
function assertFeedContract(): array
{
    $rows = test()->getJson('/api/v1/feed')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        assertMatchesContract($row, 'feed-item');
    }

    return $rows;
}

it('validates a fully-attributed feed row against feed-item.json', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Feed Cafe']);
    publishedShare($place, User::factory()->create(['is_public' => true]));

    $row = assertFeedContract()[0];

    expect($row['sharer']['username'])->not->toBeNull()
        ->and($row['influencer']['handle'])->not->toBeNull()
        ->and($row['place']['name'])->toBe('Feed Cafe');
});

it('validates a row whose sharer is private — attribution nulled, not omitted', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    publishedShare($place, User::factory()->create(['is_public' => false]));

    $row = assertFeedContract()[0];

    expect($row)->toHaveKey('sharer')
        ->and($row['sharer'])->toBeNull();
});

it('validates a row with no crediting influencer', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    $share = publishedShare($place, User::factory()->create(['is_public' => true]));
    $share->sourcePost->update(['influencer_id' => null]);

    $row = assertFeedContract()[0];

    expect($row['influencer'])->toBeNull();
});

it('validates a row whose source post has no caption or thumbnail', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    $share = publishedShare($place, User::factory()->create(['is_public' => true]));
    $share->sourcePost->update(['caption' => null]);

    $row = assertFeedContract()[0];

    expect($row['source_post']['caption'])->toBeNull()
        ->and($row['source_post']['thumbnail_url'])->toBeNull()
        // `url` is NOT NULL on source_posts, so the contract types it as a
        // plain string — a row must never carry a null here.
        ->and($row['source_post']['url'])->toBeString();
});

it('validates a row with an over-long caption (card-truncated)', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    $share = publishedShare($place, User::factory()->create(['is_public' => true]));
    $share->sourcePost->update(['caption' => str_repeat('noodles ', 60)]);

    $row = assertFeedContract()[0];
    $caption = (string) $row['source_post']['caption'];

    // Str::limit(200) keeps 200 chars and appends an ellipsis.
    expect(mb_strlen($caption))->toBeLessThanOrEqual(203)
        ->and($caption)->toEndWith('...');
});

it('keeps the place block a valid place-summary card', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Feed Cafe']);
    publishedShare($place, User::factory()->create(['is_public' => true]));

    $row = assertFeedContract()[0];

    assertMatchesContract($row['place'], 'place-summary');
});

it('validates rows for the following scope too', function () {
    $viewer = User::factory()->create();
    $sharer = User::factory()->create(['is_public' => true]);
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    publishedShare($place, $sharer);
    Follow::create(['follower_user_id' => $viewer->id, 'followee_type' => 'user', 'followee_id' => $sharer->id]);

    Sanctum::actingAs($viewer);
    $rows = $this->getJson('/api/v1/feed?scope=following')->assertOk()->json('data');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        assertMatchesContract($row, 'feed-item');
    }
});

it('validates the shares on a public profile — the same resource, a second endpoint', function () {
    $sharer = User::factory()->create(['is_public' => true]);
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create();
    publishedShare($place, $sharer);

    $rows = $this->getJson("/api/v1/users/{$sharer->username}")->assertOk()->json('data.shares');
    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        assertMatchesContract($row, 'feed-item');
    }
});
