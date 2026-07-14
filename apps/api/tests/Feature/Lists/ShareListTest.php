<?php

use App\Enums\PlaceStatus;
use App\Models\Place;
use App\Models\PlaceList;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/** Make a list public via the API and return its minted public_slug. */
function publishList(PlaceList $list): string
{
    $list->update(['is_public' => true]);

    return $list->refresh()->public_slug;
}

it('mints a stable public_slug when a list is toggled public', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $list = PlaceList::factory()->for($user)->create(['name' => 'Lisbon food']);

    expect($list->public_slug)->toBeNull();

    $slug = $this->patchJson("/api/v1/me/lists/{$list->id}", ['is_public' => true])
        ->assertOk()
        ->assertJsonPath('data.is_public', true)
        ->json('data.public_slug');

    expect($slug)->toStartWith('lisbon-food-');

    // Renaming keeps the shared link stable.
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['name' => 'Lisbon eats'])
        ->assertOk()
        ->assertJsonPath('data.public_slug', $slug);

    // Toggling private and public again keeps the same slug.
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['is_public' => false])->assertOk();
    $this->patchJson("/api/v1/me/lists/{$list->id}", ['is_public' => true])
        ->assertOk()
        ->assertJsonPath('data.public_slug', $slug);
});

it('reads a public list by its public_slug with owner attribution and places', function () {
    $owner = User::factory()->create(['username' => 'marce']);
    $a = Place::factory()->active()->atPoint(-34.9, -56.16)->create(['name' => 'Clara Café']);
    $b = Place::factory()->active()->atPoint(41.15, -8.61)->create(['name' => 'Manteigaria']);
    $list = PlaceList::factory()->for($owner)->create(['name' => 'Trip']);
    $list->items()->create(['place_id' => $a->id, 'position' => 1]);
    $list->items()->create(['place_id' => $b->id, 'position' => 2]);
    $slug = publishList($list);

    // A stranger (guest) can read it.
    $res = $this->getJson("/api/v1/lists/{$slug}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Trip')
        ->assertJsonPath('data.owner.username', 'marce')
        ->assertJsonPath('data.items_count', 2);

    $items = collect($res->json('data.items'));
    expect($items->pluck('place.name')->all())->toEqual(['Clara Café', 'Manteigaria'])
        ->and($items[0]['place']['lat'])->toEqualWithDelta(-34.9, 0.001);
});

it('suppresses owner attribution when the sharer has a private profile', function () {
    $owner = User::factory()->create(['is_public' => false]);
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    $list = PlaceList::factory()->for($owner)->create(['name' => 'Secret spots']);
    $list->items()->create(['place_id' => $place->id, 'position' => 1]);
    $slug = publishList($list);

    // The list content is shared, but the private-profile owner is not exposed.
    $this->getJson("/api/v1/lists/{$slug}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Secret spots')
        ->assertJsonPath('data.owner', null)
        ->assertJsonPath('data.items_count', 1);
});

it('404s a private (or never-shared) list without leaking existence', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $list = PlaceList::factory()->for($owner)->create();
    $slug = publishList($list);

    // Make it private again — the slug still resolves a row, but the read 404s.
    $list->update(['is_public' => false]);

    Sanctum::actingAs($stranger);
    $this->getJson("/api/v1/lists/{$slug}")->assertNotFound();

    // A slug that never existed is the same 404 (no oracle).
    $this->getJson('/api/v1/lists/does-not-exist-abc123')->assertNotFound();

    // The owner can still read their own list through the public route.
    Sanctum::actingAs($owner);
    $this->getJson("/api/v1/lists/{$slug}")->assertOk()->assertJsonPath('data.is_public', false);
});

it('exposes only publicly-visible places in a shared list', function () {
    $owner = User::factory()->create();
    $visible = Place::factory()->active()->atPoint(0, 0)->create(['name' => 'Visible']);
    $hidden = Place::factory()->atPoint(0, 0)->create(['name' => 'Hidden', 'status' => PlaceStatus::Hidden]);
    $list = PlaceList::factory()->for($owner)->create();
    $list->items()->create(['place_id' => $visible->id, 'position' => 1]);
    $list->items()->create(['place_id' => $hidden->id, 'position' => 2]);
    $slug = publishList($list);

    $res = $this->getJson("/api/v1/lists/{$slug}")->assertOk()->assertJsonPath('data.items_count', 1);
    expect(collect($res->json('data.items'))->pluck('place.name')->all())->toEqual(['Visible']);
});

it('saves a copy of a public list into the caller\'s own lists', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $a = Place::factory()->active()->atPoint(0, 0)->create(['name' => 'Uno']);
    $hidden = Place::factory()->atPoint(0, 0)->create(['status' => PlaceStatus::Hidden]);
    $list = PlaceList::factory()->for($owner)->create(['name' => 'Faves']);
    $list->items()->create(['place_id' => $a->id, 'note' => 'go here', 'position' => 1]);
    $list->items()->create(['place_id' => $hidden->id, 'position' => 2]);
    $slug = publishList($list);

    Sanctum::actingAs($viewer);
    $copy = $this->postJson("/api/v1/me/lists/{$slug}/copy")
        ->assertCreated()
        ->assertJsonPath('data.name', 'Faves')
        ->assertJsonPath('data.items_count', 1) // hidden place skipped
        ->assertJsonPath('data.is_public', false);

    expect($copy->json('data.items.0.place.name'))->toBe('Uno')
        ->and($copy->json('data.items.0.note'))->toBe('go here')
        ->and($copy->json('data.id'))->not->toBe((string) $list->id);

    // The copy belongs to the viewer, not the owner.
    Sanctum::actingAs($viewer);
    $this->getJson('/api/v1/me/lists')->assertOk()->assertJsonCount(1, 'data');
    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/me/lists')->assertOk()->assertJsonCount(1, 'data');
});

it('cannot copy a private or missing list', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $list = PlaceList::factory()->for($owner)->create();
    $slug = publishList($list);
    $list->update(['is_public' => false]);

    Sanctum::actingAs($viewer);
    $this->postJson("/api/v1/me/lists/{$slug}/copy")->assertNotFound();
    $this->postJson('/api/v1/me/lists/nope-abc123/copy')->assertNotFound();
});

it('requires auth to save a copy', function () {
    $owner = User::factory()->create();
    $list = PlaceList::factory()->for($owner)->create();
    $slug = publishList($list);

    $this->postJson("/api/v1/me/lists/{$slug}/copy")->assertUnauthorized();
});
