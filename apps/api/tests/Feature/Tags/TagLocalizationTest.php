<?php

use App\Models\Place;
use App\Models\Tag;
use App\Services\Places\TagMaterializer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Localized tag labels (ADR-084 #2): `name` stays canonical English, `label` is
 * localized from `name_i18n` per request locale, falling back to `name`.
 */
it('localizes the tag label to ?locale, falling back to the English name', function () {
    Tag::factory()->create(['kind' => 'vibe', 'name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    $es = $this->getJson('/api/v1/tags?q=casual&locale=es')->assertOk()->json('data.0');
    expect($es['name'])->toBe('casual')->and($es['label'])->toBe('Informal');

    // No `en` translation → the canonical English name IS the English label.
    $en = $this->getJson('/api/v1/tags?q=casual&locale=en')->assertOk()->json('data.0');
    expect($en['label'])->toBe('casual');
});

it('prefix-matches a localized label in ?q= (ADR-084 #3)', function () {
    Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);
    Tag::factory()->create(['name' => 'sushi', 'slug' => 'sushi']);

    // "inform" is a prefix of the Spanish label "Informal"; the tag is stored as "casual".
    $slugs = collect($this->getJson('/api/v1/tags?q=inform')->assertOk()->json('data'))->pluck('slug');
    expect($slugs)->toContain('casual')->not->toContain('sushi');
});

it('falls back to the English name when the locale has no translation', function () {
    Tag::factory()->create(['name' => 'poutine', 'slug' => 'poutine', 'name_i18n' => null]);
    $row = $this->getJson('/api/v1/tags?q=poutine&locale=es')->assertOk()->json('data.0');
    expect($row['label'])->toBe('poutine');
});

it('resolves the locale from Accept-Language when no ?locale is given', function () {
    Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);
    $row = $this->getJson('/api/v1/tags?q=casual', ['Accept-Language' => 'es-419,es;q=0.9'])->assertOk()->json('data.0');
    expect($row['label'])->toBe('Informal');
});

it('honors Accept-Language q-weights over header order', function () {
    Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => ['es' => 'Informal']]);

    // en listed first but outranked by es → Spanish label.
    $es = $this->getJson('/api/v1/tags?q=casual', ['Accept-Language' => 'en;q=0.5, es;q=0.9'])->assertOk()->json('data.0');
    expect($es['label'])->toBe('Informal');

    // es outranked by en → the canonical English name.
    $en = $this->getJson('/api/v1/tags?q=casual', ['Accept-Language' => 'es;q=0.4, en;q=0.8'])->assertOk()->json('data.0');
    expect($en['label'])->toBe('casual');
});

it('seeds name_i18n from the dictionary when materializing a new tag', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    // "casual" is in the dictionary (→ Informal); "kombucha" is not.
    app(TagMaterializer::class)->materialize($place, ['vibe_tags' => ['casual', 'kombucha']], 0.8);

    expect(Tag::where('slug', 'casual')->first()->name_i18n)->toBe(['es' => 'Informal'])
        ->and(Tag::where('slug', 'kombucha')->first()->name_i18n)->toBeNull();
});

it('backfills es names via the command without overwriting existing ones', function () {
    $missing = Tag::factory()->create(['name' => 'casual', 'slug' => 'casual', 'name_i18n' => null]);
    $custom = Tag::factory()->create(['name' => 'brunch', 'slug' => 'brunch', 'name_i18n' => ['es' => 'Mi Brunch']]);
    $unknown = Tag::factory()->create(['name' => 'kombucha', 'slug' => 'kombucha', 'name_i18n' => null]);

    $this->artisan('reelmap:tags:backfill-i18n')->assertSuccessful();

    expect($missing->fresh()->name_i18n)->toBe(['es' => 'Informal'])    // filled from the dictionary
        ->and($custom->fresh()->name_i18n)->toBe(['es' => 'Mi Brunch'])  // pre-existing value preserved
        ->and($unknown->fresh()->name_i18n)->toBeNull();                 // no dictionary entry
});
