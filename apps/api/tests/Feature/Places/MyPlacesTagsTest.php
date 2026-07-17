<?php

use App\Models\HiddenPlace;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * GET /me/places/tags (ADR-084) — the discovery-tag facet of my places: the
 * tags actually on my collection, with per-tag counts, for the filter autocomplete.
 */
function myTagPlace(string $name, array $attrs = []): Place
{
    return Place::factory()->active()->atPoint(51.51, -0.13)->create(['name' => $name, ...$attrs]);
}

it('requires authentication', function () {
    $this->getJson('/api/v1/me/places/tags')->assertStatus(401);
});

it('returns the tags on my places with per-tag counts, most-used first', function () {
    $me = User::factory()->create();
    $a = myTagPlace('A');
    $b = myTagPlace('B');
    publishedShare($a, sharer: $me);
    publishedShare($b, sharer: $me);

    $brunch = Tag::factory()->create(['slug' => 'brunch', 'name' => 'Brunch']);
    $ramen = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $a->tags()->attach([$brunch->id => ['source' => 'extraction'], $ramen->id => ['source' => 'extraction']]);
    $b->tags()->attach([$brunch->id => ['source' => 'extraction']]);

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/tags')->assertOk()->json('data');

    // brunch (2 of my places) ranks before ramen (1); each carries its count.
    expect(collect($data)->pluck('slug')->all())->toBe(['brunch', 'ramen']);
    expect(collect($data)->firstWhere('slug', 'brunch')['places_count'])->toBe(2);
    expect(collect($data)->firstWhere('slug', 'ramen')['places_count'])->toBe(1);
});

it('never includes a tag that is only on someone else’s places', function () {
    $me = User::factory()->create();
    $mine = myTagPlace('Mine');
    $theirs = myTagPlace('Theirs');
    publishedShare($mine, sharer: $me);
    publishedShare($theirs); // someone else's

    $mineTag = Tag::factory()->create(['slug' => 'mine-tag', 'name' => 'Mine Tag']);
    $strangerTag = Tag::factory()->create(['slug' => 'stranger-tag', 'name' => 'Stranger Tag']);
    $mine->tags()->attach($mineTag->id, ['source' => 'extraction']);
    $theirs->tags()->attach($strangerTag->id, ['source' => 'extraction']);

    Sanctum::actingAs($me);
    $slugs = collect($this->getJson('/api/v1/me/places/tags')->assertOk()->json('data'))->pluck('slug');
    expect($slugs)->toContain('mine-tag')->not->toContain('stranger-tag');
});

it('also counts saved (not just shared) places', function () {
    $me = User::factory()->create();
    $saved = myTagPlace('Saved');
    $tag = Tag::factory()->create(['slug' => 'saved-tag', 'name' => 'Saved Tag']);
    $saved->tags()->attach($tag->id, ['source' => 'extraction']);
    $list = PlaceList::factory()->for($me)->create();
    $list->items()->create(['place_id' => $saved->id, 'position' => 1]);

    Sanctum::actingAs($me);
    $slugs = collect($this->getJson('/api/v1/me/places/tags')->assertOk()->json('data'))->pluck('slug');
    expect($slugs)->toContain('saved-tag');
});

it('excludes tags whose only place I have soft-hidden', function () {
    $me = User::factory()->create();
    $hidden = myTagPlace('Hidden');
    publishedShare($hidden, sharer: $me);
    $tag = Tag::factory()->create(['slug' => 'hidden-only', 'name' => 'Hidden Only']);
    $hidden->tags()->attach($tag->id, ['source' => 'extraction']);
    HiddenPlace::create(['user_id' => $me->id, 'place_id' => $hidden->id]);

    Sanctum::actingAs($me);
    $slugs = collect($this->getJson('/api/v1/me/places/tags')->assertOk()->json('data'))->pluck('slug');
    expect($slugs)->not->toContain('hidden-only');
});

it('dedupes a slug that exists under two kinds into a single entry (same place)', function () {
    $me = User::factory()->create();
    $p = myTagPlace('P');
    publishedShare($p, sharer: $me);
    // The unique index is per (kind, slug), so "pizza" can exist as both a
    // cuisine and a dish; the filter matches on slug, so it must show once.
    $cuisine = Tag::factory()->create(['kind' => 'cuisine', 'slug' => 'pizza', 'name' => 'Pizza']);
    $dish = Tag::factory()->create(['kind' => 'dish', 'slug' => 'pizza', 'name' => 'Pizza']);
    $p->tags()->attach([$cuisine->id => ['source' => 'extraction'], $dish->id => ['source' => 'extraction']]);

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/tags')->assertOk()->json('data');
    $pizza = collect($data)->where('slug', 'pizza');
    // One row, and the count is DISTINCT places (both kinds on the one place = 1).
    expect($pizza->count())->toBe(1)
        ->and($pizza->first()['places_count'])->toBe(1);
});

it('counts a slug spanning two kinds across DIFFERENT places as the distinct place count', function () {
    $me = User::factory()->create();
    $a = myTagPlace('A');
    $b = myTagPlace('B');
    publishedShare($a, sharer: $me);
    publishedShare($b, sharer: $me);
    $cuisine = Tag::factory()->create(['kind' => 'cuisine', 'slug' => 'pizza', 'name' => 'Pizza']);
    $dish = Tag::factory()->create(['kind' => 'dish', 'slug' => 'pizza', 'name' => 'Pizza']);
    $a->tags()->attach($cuisine->id, ['source' => 'extraction']);
    $b->tags()->attach($dish->id, ['source' => 'extraction']);

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/tags')->assertOk()->json('data');
    $pizza = collect($data)->where('slug', 'pizza');
    // Two distinct of my places carry the slug — the number filtering returns.
    expect($pizza->count())->toBe(1)
        ->and($pizza->first()['places_count'])->toBe(2);
});

it('counts only MY places for a tag also on a stranger’s place', function () {
    $me = User::factory()->create();
    $mine = myTagPlace('Mine');
    $theirs = myTagPlace('Theirs');
    publishedShare($mine, sharer: $me);
    publishedShare($theirs); // stranger's

    $shared = Tag::factory()->create(['slug' => 'shared', 'name' => 'Shared']);
    $mine->tags()->attach($shared->id, ['source' => 'extraction']);
    $theirs->tags()->attach($shared->id, ['source' => 'extraction']);

    Sanctum::actingAs($me);
    $data = $this->getJson('/api/v1/me/places/tags')->assertOk()->json('data');
    // On 2 places total, but only 1 is mine — the count must not leak the stranger's.
    expect(collect($data)->firstWhere('slug', 'shared')['places_count'])->toBe(1);
});

it('returns an empty list when I have no places', function () {
    $me = User::factory()->create();
    Sanctum::actingAs($me);
    expect($this->getJson('/api/v1/me/places/tags')->assertOk()->json('data'))->toBe([]);
});

it('carries the tag contract shape (id, kind, name, slug, places_count)', function () {
    $me = User::factory()->create();
    $p = myTagPlace('P');
    publishedShare($p, sharer: $me);
    $p->tags()->attach(Tag::factory()->create(['slug' => 'sushi', 'name' => 'Sushi'])->id, ['source' => 'extraction']);

    Sanctum::actingAs($me);
    $row = $this->getJson('/api/v1/me/places/tags')->assertOk()->json('data.0');
    expect($row)->toHaveKeys(['id', 'kind', 'name', 'slug', 'places_count']);
    expect($row['slug'])->toBe('sushi');
});
