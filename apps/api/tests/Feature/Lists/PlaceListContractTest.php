<?php

use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use App\Support\Contracts\ApiSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Contract tests (T-102): the saved-collection payloads must validate against
 * packages/contracts/schemas/place-list.json (index form) and
 * place-list-detail.json (with items) — the files `PlaceListSummary` and
 * `PlaceListDetail` are generated from.
 */
function assertListContract(mixed $payload, string $schema): void
{
    $errors = ApiSchema::errors(ApiSchema::validate($payload, $schema));
    expect($errors)->toBe([], "{$schema}.json violations: ".json_encode($errors));
}

function listWithPlaces(User $owner, int $count = 2): PlaceList
{
    $list = PlaceList::factory()->for($owner)->create(['name' => 'Lisbon 2026']);
    foreach (range(1, $count) as $i) {
        $place = Place::factory()->active()->atPoint(38.71 + $i / 100, -9.13)->create();
        $list->items()->create(['place_id' => $place->id, 'position' => $i, 'note' => "stop {$i}"]);
    }

    return $list;
}

it('validates index rows against place-list.json', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    listWithPlaces($owner);
    PlaceList::factory()->for($owner)->create(['name' => 'Empty']);

    $rows = $this->getJson('/api/v1/me/lists')->assertOk()->json('data');
    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        assertListContract($row, 'place-list');
        expect($row)->not->toHaveKey('contains');
    }
});

it('validates index rows carrying the ?contains flag', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $list = listWithPlaces($owner, 1);
    $held = $list->items()->first()->place_id;

    $rows = $this->getJson("/api/v1/me/lists?contains={$held}")->assertOk()->json('data');

    foreach ($rows as $row) {
        assertListContract($row, 'place-list');
    }
    expect($rows[0]['contains'])->toBeTrue();
});

it('validates a list detail with its items against place-list-detail.json', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $list = listWithPlaces($owner, 3);

    $data = $this->getJson("/api/v1/me/lists/{$list->id}")->assertOk()->json('data');

    assertListContract($data, 'place-list-detail');
    expect($data['items'])->toHaveCount(3)
        ->and($data['items_count'])->toBe(3)
        ->and($data['items'][0]['note'])->toBe('stop 1');

    // Each item's place block is the same summary card the map renders.
    foreach ($data['items'] as $item) {
        assertListContract($item['place'], 'place-summary');
    }
});

it('validates an empty list detail', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $list = PlaceList::factory()->for($owner)->create();

    $data = $this->getJson("/api/v1/me/lists/{$list->id}")->assertOk()->json('data');

    assertListContract($data, 'place-list-detail');
    expect($data['items'])->toBe([])
        ->and($data['public_slug'])->toBeNull();
});

it('validates the add/remove-place responses', function () {
    $owner = User::factory()->create();
    Sanctum::actingAs($owner);
    $list = PlaceList::factory()->for($owner)->create();
    $place = Place::factory()->active()->atPoint(38.71, -9.13)->create();

    $added = $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}", ['note' => 'brunch'])
        ->assertCreated()->json('data');
    assertListContract($added, 'place-list-detail');

    $removed = $this->deleteJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")
        ->assertOk()->json('data');
    assertListContract($removed, 'place-list-detail');
});

it('validates the public read of a shared list, with owner attribution', function () {
    $owner = User::factory()->create(['is_public' => true]);
    Sanctum::actingAs($owner);
    $list = listWithPlaces($owner);
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['is_public' => true])->assertOk();
    $slug = $list->fresh()->public_slug;

    $data = $this->getJson("/api/v1/lists/{$slug}")->assertOk()->json('data');

    assertListContract($data, 'place-list-detail');
    expect($data['owner']['username'])->toBe($owner->username)
        ->and($data['is_public'])->toBeTrue();
});

it('validates the public read when the owner is private — owner nulled, not omitted', function () {
    $owner = User::factory()->create(['is_public' => false]);
    Sanctum::actingAs($owner);
    $list = listWithPlaces($owner, 1);
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['is_public' => true])->assertOk();
    $slug = $list->fresh()->public_slug;

    $this->app['auth']->forgetGuards();
    $data = $this->getJson("/api/v1/lists/{$slug}")->assertOk()->json('data');

    assertListContract($data, 'place-list-detail');
    expect($data)->toHaveKey('owner')
        ->and($data['owner'])->toBeNull();
});
