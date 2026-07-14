<?php

use App\Models\Place;
use App\Models\User;
use App\Models\UserPlaceTag;
use Laravel\Sanctum\Sanctum;

it('adds, lists, and removes a private tag for a place', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(-34.9, -56.16)->create(['name' => 'Clara Café']);

    $tagId = $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'visitar a las 5'])
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'visitar a las 5')
        ->json('data.0.id');

    $this->getJson("/api/v1/me/places/{$place->slug}/tags")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'visitar a las 5');

    $this->deleteJson("/api/v1/me/places/{$place->slug}/tags/{$tagId}")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->assertDatabaseMissing('user_place_tags', ['id' => $tagId]);
});

it('is idempotent when adding the same label twice', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'favorito'])->assertCreated();
    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'favorito'])
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(UserPlaceTag::where('place_id', $place->id)->count())->toBe(1);
});

it('collapses whitespace so padded labels are the same tag', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'visitar a las 5'])->assertCreated();
    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => '  visitar   a las 5 '])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'visitar a las 5');
});

it('collapses case-variant labels to one tag (first spelling wins)', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'Visitar'])->assertCreated();
    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'visitar'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.label', 'Visitar');

    expect(UserPlaceTag::where('place_id', $place->id)->count())->toBe(1);
});

it('rejects an empty label', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => '   '])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.details.label.0', 'The label field is required.');
});

it('keeps one user\'s tags invisible to another — listing and delete', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    Sanctum::actingAs($owner);
    $tagId = $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'secreto'])
        ->assertCreated()->json('data.0.id');

    // The other user never sees the owner's tag in the listing…
    Sanctum::actingAs($other);
    $this->getJson("/api/v1/me/places/{$place->slug}/tags")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // …and cannot delete it (404, not 403 — no existence oracle).
    $this->deleteJson("/api/v1/me/places/{$place->slug}/tags/{$tagId}")->assertNotFound();
    $this->assertDatabaseHas('user_place_tags', ['id' => $tagId]);
});

it('exposes my_tags on the place detail for the owner only', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    Sanctum::actingAs($owner);
    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'visitar a las 5'])->assertCreated();

    // Owner sees their own tag embedded in the place detail.
    $this->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonPath('data.my_tags.0.label', 'visitar a las 5');

    // Another authed user sees an empty my_tags — never the owner's labels.
    Sanctum::actingAs($other);
    $this->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonCount(0, 'data.my_tags');
});

it('omits my_tags entirely for a guest', function () {
    $owner = User::factory()->create();
    $place = Place::factory()->active()->atPoint(0, 0)->create();
    // Seed a tag directly so no acting-as guard lingers for the guest request.
    UserPlaceTag::factory()->create(['user_id' => $owner->id, 'place_id' => $place->id, 'label' => 'privado']);

    $this->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonMissingPath('data.my_tags');
});

it('requires authentication', function () {
    $place = Place::factory()->active()->atPoint(0, 0)->create();

    $this->getJson("/api/v1/me/places/{$place->slug}/tags")->assertUnauthorized();
    $this->postJson("/api/v1/me/places/{$place->slug}/tags", ['label' => 'x'])->assertUnauthorized();
});
