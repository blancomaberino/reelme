<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('creates a list, adds places, and returns them map-ready', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $a = Place::factory()->active()->atPoint(-34.9, -56.16)->create(['name' => 'Clara Café']);
    $b = Place::factory()->active()->atPoint(41.15, -8.61)->create(['name' => 'Manteigaria']);

    $list = $this->postJson('/api/v1/me/lists', ['name' => 'Trip 2026'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Trip 2026')
        ->assertJsonPath('data.slug', 'trip-2026')
        ->assertJsonPath('data.items_count', 0)
        ->json('data.id');

    $this->postJson("/api/v1/me/lists/{$list}/places/{$a->id}", ['note' => 'coffee'])->assertCreated();
    $res = $this->postJson("/api/v1/me/lists/{$list}/places/{$b->id}")->assertCreated();

    $res->assertJsonPath('data.items_count', 2);
    $items = collect($res->json('data.items'));
    expect($items->pluck('place.name')->all())->toEqual(['Clara Café', 'Manteigaria']);
    // Map-ready: each place carries lat/lng.
    expect($items[0]['place']['lat'])->toEqualWithDelta(-34.9, 0.001)
        ->and($items[0]['note'])->toBe('coffee');
});

it('is idempotent when adding the same place twice', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $list = PlaceList::factory()->for($user)->create();

    $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")->assertCreated();
    $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")
        ->assertOk()
        ->assertJsonPath('data.items_count', 1);
});

it('removes a place from a list', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $list = PlaceList::factory()->for($user)->create();
    $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")->assertCreated();

    $this->deleteJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")
        ->assertOk()
        ->assertJsonPath('data.items_count', 0);
});

it('tombstones a sourceless place when its last list drops it (T-073)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    // A place kept alive only by the save (no published source of its own).
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $list = PlaceList::factory()->for($user)->create();
    $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")->assertCreated();

    $this->deleteJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")->assertOk();

    // No source and no list holds it now → orphaned ghost pin → tombstoned.
    expect($place->fresh()->status)->toBe(PlaceStatus::Removed);
});

it('keeps a sourceless place saved in another list alive (no tombstone)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $a = PlaceList::factory()->for($user)->create();
    $b = PlaceList::factory()->for($user)->create();
    $this->postJson("/api/v1/me/lists/{$a->id}/places/{$place->id}")->assertCreated();
    $this->postJson("/api/v1/me/lists/{$b->id}/places/{$place->id}")->assertCreated();

    $this->deleteJson("/api/v1/me/lists/{$a->id}/places/{$place->id}")->assertOk();

    // Still saved in B → not orphaned.
    expect($place->fresh()->status)->toBe(PlaceStatus::Active);
});

it('updates and deletes a list', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $list = PlaceList::factory()->for($user)->create(['name' => 'Old']);

    $this->patchJson("/api/v1/me/lists/{$list->id}", ['name' => 'New', 'is_public' => true])
        ->assertOk()
        ->assertJsonPath('data.name', 'New')
        ->assertJsonPath('data.is_public', true);

    $this->deleteJson("/api/v1/me/lists/{$list->id}")->assertOk();
    $this->assertDatabaseMissing('place_lists', ['id' => $list->id]);
});

it('scopes lists to the owner — another user gets 404, not 403', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $list = PlaceList::factory()->for($owner)->create();
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    Sanctum::actingAs($other);
    $this->getJson("/api/v1/me/lists/{$list->id}")->assertNotFound();
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/me/lists/{$list->id}")->assertNotFound();
    $this->postJson("/api/v1/me/lists/{$list->id}/places/{$place->id}")->assertNotFound();

    // The owner's index never shows another user's lists.
    $this->getJson('/api/v1/me/lists')->assertOk()->assertJsonCount(0, 'data');
});

it('derives a unique slug per owner', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/me/lists', ['name' => 'Lisbon'])->assertJsonPath('data.slug', 'lisbon');
    $this->postJson('/api/v1/me/lists', ['name' => 'Lisbon'])->assertJsonPath('data.slug', 'lisbon-2');
});

it('flags which lists contain a place via ?contains', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $withIt = PlaceList::factory()->for($user)->create(['name' => 'Has it']);
    $without = PlaceList::factory()->for($user)->create(['name' => 'Empty']);
    $this->postJson("/api/v1/me/lists/{$withIt->id}/places/{$place->id}")->assertCreated();

    $data = collect($this->getJson("/api/v1/me/lists?contains={$place->id}")->assertOk()->json('data'))
        ->keyBy('name');
    expect($data['Has it']['contains'])->toBeTrue()
        ->and($data['Empty']['contains'])->toBeFalse();

    // Without the param, `contains` is omitted entirely.
    $plain = $this->getJson('/api/v1/me/lists')->assertOk()->json('data.0');
    expect($plain)->not->toHaveKey('contains');
});

it('requires authentication', function () {
    $this->postJson('/api/v1/me/lists', ['name' => 'x'])->assertUnauthorized();
});
