<?php

use App\Enums\MediaKind;
use App\Models\Influencer;
use App\Models\MediaAsset;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

/**
 * Wire a full attribution chain (influencer → post → share/user → place_source).
 *
 * @param  array<string, mixed>  $snapshot
 */
function makeAttributedSource(Place $place, User $sharer, array $snapshot = [], bool $primary = false): PlaceSource
{
    $influencer = Influencer::factory()->create();
    $post = SourcePost::factory()->create([
        'influencer_id' => $influencer->id,
        'caption' => 'Best hand-pulled noodles in town, hidden gem!',
        'posted_at' => now()->subDay(),
    ]);
    $share = Share::factory()->create(['source_post_id' => $post->id, 'user_id' => $sharer->id]);

    return PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => $snapshot,
        'is_primary' => $primary,
    ]);
}

it('lists every source with post link-out, influencer and public sharer attribution', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $sharer = User::factory()->create(['is_public' => true]);

    $source = makeAttributedSource($place, $sharer, [
        'cuisines' => ['chinese'],
        'vibe_tags' => ['casual'],
        'dishes' => [['name' => 'Beef Noodle Soup', 'shown_in_video' => true]],
    ], primary: true);

    $res = $this->getJson("/api/v1/places/{$place->slug}/sources")
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');

    $row = $res->json('data.0');
    expect($row['id'])->toBe((string) $source->id)
        ->and($row['is_primary'])->toBeTrue()
        ->and($row['source_post']['url'])->toBe($source->sourcePost->url)
        ->and($row['source_post']['platform'])->toBe($source->sourcePost->platform->value)
        ->and($row['source_post']['caption'])->toContain('hand-pulled noodles')
        ->and($row['source_post']['posted_at'])->not->toBeNull()
        ->and($row['influencer']['handle'])->toBe($source->sourcePost->influencer->handle)
        ->and($row['sharer']['username'])->toBe($sharer->username)
        ->and($row['highlights']['dishes'])->toBe(['Beef Noodle Soup'])
        ->and($row['highlights']['tags'])->toEqualCanonicalizing(['chinese', 'casual']);
});

it('withholds a private sharer entirely', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $private = User::factory()->create(['is_public' => false]);

    makeAttributedSource($place, $private);

    $this->getJson("/api/v1/places/{$place->slug}/sources")
        ->assertOk()
        ->assertJsonPath('data.0.sharer', null);
});

it('serves a signed thumbnail URL when the post has a thumbnail asset', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $source = makeAttributedSource($place, User::factory()->create(['is_public' => true]));

    MediaAsset::factory()->create([
        'source_post_id' => $source->source_post_id,
        'kind' => MediaKind::Thumbnail,
        'disk' => 'local_media',
        'storage_path' => 'derived/thumb.jpg',
    ]);

    $url = $this->getJson("/api/v1/places/{$place->slug}/sources")
        ->assertOk()
        ->json('data.0.source_post.thumbnail_url');

    // A signed temporary URL — not a raw storage path/URL.
    expect($url)->toBeString()->toContain('thumb.jpg')->toContain('signature=');
});

it('paginates sources by cursor', function () {
    $place = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $sharer = User::factory()->create(['is_public' => true]);
    foreach (range(1, 3) as $i) {
        makeAttributedSource($place, $sharer);
    }

    $page1 = $this->getJson("/api/v1/places/{$place->slug}/sources?limit=2")->assertOk();
    expect($page1->json('data'))->toHaveCount(2);

    $cursor = $page1->json('meta.pagination.next_cursor');
    $page2 = $this->getJson("/api/v1/places/{$place->slug}/sources?limit=2&cursor=".urlencode($cursor))->assertOk();
    expect($page2->json('data'))->toHaveCount(1)
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();

    $ids = collect([...$page1->json('data'), ...$page2->json('data')])->pluck('id');
    expect($ids->unique())->toHaveCount(3);
});

it('404s sources of a merged place', function () {
    $survivor = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $merged = Place::factory()->atPoint(38.7, -9.1)->create([
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
    ]);

    $this->getJson("/api/v1/places/{$merged->slug}/sources")->assertStatus(404);
});
