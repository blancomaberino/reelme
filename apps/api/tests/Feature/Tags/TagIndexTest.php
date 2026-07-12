<?php

use App\Enums\TagKind;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists tags alphabetically in the {data, meta} envelope', function () {
    Tag::factory()->ofKind(TagKind::Cuisine)->create(['name' => 'Noodles', 'slug' => 'noodles']);
    Tag::factory()->ofKind(TagKind::Vibe)->create(['name' => 'Casual', 'slug' => 'casual']);

    $res = $this->getJson('/api/v1/tags')->assertOk();

    expect(collect($res->json('data'))->pluck('slug')->all())->toBe(['casual', 'noodles'])
        ->and($res->json('data.0.kind'))->toBe('vibe')
        ->and($res->json('meta.pagination'))->toHaveKeys(['next_cursor', 'prev_cursor', 'limit']);
});

it('prefix-matches ?q= against slug and name', function () {
    Tag::factory()->create(['name' => 'Noodles', 'slug' => 'noodles']);
    Tag::factory()->create(['name' => 'Hand-Pulled Noodles', 'slug' => 'hand-pulled-noodles']);
    Tag::factory()->create(['name' => 'Sushi', 'slug' => 'sushi']);

    $slugs = collect($this->getJson('/api/v1/tags?q=noo')->assertOk()->json('data'))->pluck('slug');

    expect($slugs)->toContain('noodles')->not->toContain('sushi', 'hand-pulled-noodles');

    // name prefix (case-insensitive) also matches.
    $byName = collect($this->getJson('/api/v1/tags?q=hand')->assertOk()->json('data'))->pluck('slug');
    expect($byName)->toContain('hand-pulled-noodles');
});

it('orders by usage with ?popular=1 and paginates through ties', function () {
    // Creation order is deliberately NOT popularity order (asc or desc), so an
    // id-ordered fall-through produces different pages.
    $warm = Tag::factory()->create(['slug' => 'warm']);      // 1 use, id 1
    $hot = Tag::factory()->create(['slug' => 'zzz-hot']);    // 3 uses, id 2
    $tied = Tag::factory()->create(['slug' => 'tied']);      // 1 use, id 3
    Tag::factory()->create(['slug' => 'aaa-cold']);          // 0 uses, id 4

    $places = Place::factory()->active()->atPoint(51.5, -0.13)->count(3)->create();
    $hot->places()->attach($places->pluck('id'));
    $warm->places()->attach($places->take(1)->pluck('id'));
    $tied->places()->attach($places->take(1)->pluck('id'));

    // The warm/tied tie (both count=1, id-desc tiebreak) crosses the boundary.
    $page1 = $this->getJson('/api/v1/tags?popular=1&limit=2')->assertOk();
    expect(collect($page1->json('data'))->pluck('slug')->all())->toBe(['zzz-hot', 'tied'])
        ->and($page1->json('data.0.places_count'))->toBe(3);

    $cursor = $page1->json('meta.pagination.next_cursor');
    $page2 = $this->getJson('/api/v1/tags?popular=1&limit=2&cursor='.urlencode($cursor))->assertOk();
    expect(collect($page2->json('data'))->pluck('slug')->all())->toBe(['warm', 'aaa-cold'])
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();
});

it('walks alpha pages via cursor without duplicates or gaps', function () {
    foreach (['delta', 'alpha', 'charlie', 'bravo', 'echo'] as $slug) {
        Tag::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
    }

    $page1 = $this->getJson('/api/v1/tags?limit=2')->assertOk();
    expect(collect($page1->json('data'))->pluck('slug')->all())->toBe(['alpha', 'bravo']);

    $page2 = $this->getJson('/api/v1/tags?limit=2&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();
    expect(collect($page2->json('data'))->pluck('slug')->all())->toBe(['charlie', 'delta']);

    $page3 = $this->getJson('/api/v1/tags?limit=2&cursor='.urlencode($page2->json('meta.pagination.next_cursor')))->assertOk();
    expect(collect($page3->json('data'))->pluck('slug')->all())->toBe(['echo'])
        ->and($page3->json('meta.pagination.next_cursor'))->toBeNull();
});

it('rejects a cursor minted for the other tag ordering', function () {
    Tag::factory()->count(3)->create();

    $cursor = $this->getJson('/api/v1/tags?limit=1')->json('meta.pagination.next_cursor');

    $this->getJson('/api/v1/tags?popular=1&limit=1&cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('exposes rate-limit headers', function () {
    $this->getJson('/api/v1/tags')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});

it('treats LIKE metacharacters in ?q= as literals', function () {
    Tag::factory()->create(['name' => 'Noodles', 'slug' => 'noodles']);
    Tag::factory()->create(['name' => '100% Vegan', 'slug' => '100-vegan']);

    // "%" must not act as a wildcard that matches everything.
    expect($this->getJson('/api/v1/tags?q='.urlencode('%'))->assertOk()->json('data'))->toBe([]);
    expect($this->getJson('/api/v1/tags?q='.urlencode('100% v'))->assertOk()->json('data'))
        ->toHaveCount(1);

    // "_" must not act as a single-char wildcard either.
    Tag::factory()->create(['name' => 'a_b', 'slug' => 'ab-lit']);
    Tag::factory()->create(['name' => 'axb', 'slug' => 'axb']);
    expect(collect($this->getJson('/api/v1/tags?q='.urlencode('a_'))->assertOk()->json('data'))->pluck('slug')->all())
        ->toBe(['ab-lit']);
});
