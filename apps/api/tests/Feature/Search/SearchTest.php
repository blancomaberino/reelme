<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * Driver-agnostic search tests (collection engine via phpunit.xml). Typo
 * tolerance and index settings are covered by the @group meilisearch suite.
 */

it('federates places, tags and influencers with the {data, meta} envelope', function () {
    Place::factory()->active()->atPoint(51.5117, -0.13)->create(['name' => 'Lanzhou Beef Noodle House']);
    Tag::factory()->create(['name' => 'Noodles', 'slug' => 'noodles']);
    Influencer::factory()->create(['handle' => 'noodle.hunter', 'display_name' => 'Noodle Hunter']);
    Place::factory()->active()->atPoint(51.5, -0.14)->create(['name' => 'Sushi Corner']);

    $res = $this->getJson('/api/v1/search?q=noodle')->assertOk();

    expect(collect($res->json('data.places'))->pluck('name'))->toContain('Lanzhou Beef Noodle House')
        ->not->toContain('Sushi Corner');
    expect(collect($res->json('data.tags'))->pluck('slug'))->toContain('noodles');
    expect(collect($res->json('data.influencers'))->pluck('handle'))->toContain('noodle.hunter');
    expect($res->json('data.users'))->toBe([])
        ->and($res->json('meta.query'))->toBe('noodle');

    // Place hits carry the summary shape (coordinates included).
    $place = collect($res->json('data.places'))->firstWhere('name', 'Lanzhou Beef Noodle House');
    expect($place['lat'])->toEqualWithDelta(51.5117, 0.001)
        ->and($place['slug'])->not->toBeNull();
});

it('honors ?types= and rejects unknown types', function () {
    Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Noodle Bar']);
    Tag::factory()->create(['name' => 'Noodles', 'slug' => 'noodles']);

    $res = $this->getJson('/api/v1/search?q=noodle&types=tags')->assertOk();
    expect($res->json('data'))->toHaveKey('tags')
        ->not->toHaveKeys(['places', 'influencers', 'users']);

    $combo = $this->getJson('/api/v1/search?q=noodle&types=places,tags')->assertOk();
    expect($combo->json('data'))->toHaveKeys(['places', 'tags'])
        ->not->toHaveKeys(['influencers', 'users']);

    $this->getJson('/api/v1/search?q=noodle&types=bogus')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('requires q', function () {
    $this->getJson('/api/v1/search')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('never surfaces merged places', function () {
    $survivor = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Noodle Survivor']);
    Place::factory()->atPoint(51.5, -0.13)->create([
        'name' => 'Noodle Tombstone',
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
    ]);

    $names = collect($this->getJson('/api/v1/search?q=noodle&types=places')->assertOk()->json('data.places'))->pluck('name');

    expect($names)->toContain('Noodle Survivor')->not->toContain('Noodle Tombstone');
});

it('exposes rate-limit headers', function () {
    $this->getJson('/api/v1/search?q=x')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});

it('surfaces a freshly published place and its tags through search (full chain)', function () {
    // Runs on the collection driver, so the publish→materialize→searchable
    // chain is pinned even without a Meilisearch server.
    $place = publishTaggedShare();

    $res = $this->getJson('/api/v1/search?q=Lanzhou&types=places,tags')->assertOk();

    expect(collect($res->json('data.places'))->pluck('id'))->toContain((string) $place->id);

    $tags = collect($this->getJson('/api/v1/search?q=chinese&types=tags')->assertOk()->json('data.tags'));
    expect($tags->pluck('slug'))->toContain('chinese');
});

it('422s an array types param instead of 500ing', function () {
    $this->getJson('/api/v1/search?q=pizza&types[]=places')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});
