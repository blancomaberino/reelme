<?php

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

it('requires authentication', function () {
    $this->postJson('/api/v1/me/lists', ['name' => 'x'])->assertUnauthorized();
});
