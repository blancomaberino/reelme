<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Review;
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
 * Attach a place_source carrying a frozen extraction snapshot, wired to a real
 * source_post + influencer so the detail endpoint can surface reel_url + account.
 *
 * @param  array<string, mixed>  $snapshot
 */
function attachSource(Place $place, array $snapshot, string $platform, string $handle, string $displayName, bool $primary = false): PlaceSource
{
    $influencer = Influencer::factory()->create(['handle' => $handle, 'display_name' => $displayName]);
    $post = SourcePost::factory()->create([
        'platform' => $platform,
        'influencer_id' => $influencer->id,
        'url' => "https://example.test/{$handle}/reel",
        'posted_at' => now(),
    ]);
    $share = Share::factory()->create(['source_post_id' => $post->id]);

    return PlaceSource::factory()->create([
        'place_id' => $place->id,
        'source_post_id' => $post->id,
        'share_id' => $share->id,
        'extraction_snapshot_json' => $snapshot,
        'is_primary' => $primary,
    ]);
}

it('aggregates deduped tags + dishes and lists contributing sources', function () {
    $place = Place::factory()->active()->atPoint(51.5117, -0.1300)->create([
        'name' => 'Lanzhou Beef Noodle House',
        'cuisine_primary' => 'chinese',
        'city' => 'London',
        'country_code' => 'GB',
        'address_line1' => '45 Gerrard St',
        'region' => 'England',
        'shares_count' => 2,
    ]);

    // The NON-primary source is attached first (lower id) so the "primary
    // first" assertion pins the is_primary ordering, not insertion order.
    attachSource($place, [
        'name' => 'Lanzhou Beef Noodle House',
        'cuisines' => ['chinese', 'halal'],
        'vibe_tags' => ['casual', 'authentic'],
        'dietary_tags' => ['halal', 'vegetarian-options'],
        'dishes' => [
            ['name' => 'Beef Noodle Soup', 'shown_in_video' => false], // dup by name → primary's copy wins
            ['name' => 'Hand-Pulled Noodles', 'shown_in_video' => true],
        ],
    ], 'tiktok', 'street.eats', 'Street Eats');

    attachSource($place, [
        'name' => 'Lanzhou Beef Noodle House',
        'cuisines' => ['chinese', 'noodles'],
        'vibe_tags' => ['casual', 'hole-in-the-wall'],
        'dietary_tags' => ['halal'],
        'dishes' => [
            ['name' => 'Beef Noodle Soup', 'shown_in_video' => true],
            ['name' => 'Dumplings', 'shown_in_video' => false],
        ],
    ], 'instagram', 'noodle.hunter', 'Noodle Hunter', primary: true);

    $res = $this->getJson("/api/v1/places/{$place->id}?include=sources")->assertOk();

    $res->assertJsonPath('data.id', (string) $place->id)
        ->assertJsonPath('data.name', 'Lanzhou Beef Noodle House')
        ->assertJsonPath('data.category', 'chinese')
        ->assertJsonPath('data.address', '45 Gerrard St, London, England, GB')
        ->assertJsonPath('data.source_count', 2);

    $data = $res->json('data');
    expect($data['cuisines'])->toEqualCanonicalizing(['chinese', 'noodles', 'halal'])
        ->and($data['vibe_tags'])->toEqualCanonicalizing(['casual', 'hole-in-the-wall', 'authentic'])
        ->and($data['dietary_tags'])->toEqualCanonicalizing(['halal', 'vegetarian-options'])
        ->and(collect($data['dishes'])->pluck('name')->all())
        ->toEqualCanonicalizing(['Beef Noodle Soup', 'Dumplings', 'Hand-Pulled Noodles']);

    // Dedup by name keeps the first occurrence's shown_in_video.
    $beef = collect($data['dishes'])->firstWhere('name', 'Beef Noodle Soup');
    expect($beef['shown_in_video'])->toBeTrue();

    expect($data['sources'])->toHaveCount(2);
    // Primary source first.
    expect($data['sources'][0]['is_primary'])->toBeTrue()
        ->and($data['sources'][0]['influencer']['handle'])->toBe('noodle.hunter')
        ->and($data['sources'][0]['influencer']['display_name'])->toBe('Noodle Hunter')
        ->and($data['sources'][0]['source_post']['url'])->toBe('https://example.test/noodle.hunter/reel')
        ->and($data['sources'][0]['source_post']['platform'])->toBe('instagram')
        ->and($data['sources'][0]['source_post']['posted_at'])->not->toBeNull();
    expect(collect($data['sources'])->pluck('source_post.platform')->all())->toContain('tiktok');
});

it('binds by slug (canonical) as well as by numeric id', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Slug Cafe']);

    $this->getJson("/api/v1/places/{$place->slug}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $place->id)
        ->assertJsonPath('data.slug', $place->slug);
});

it('omits sources without include and rejects unknown includes', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    $this->getJson("/api/v1/places/{$place->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.sources');

    $this->getJson("/api/v1/places/{$place->id}?include=bogus")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('accepts include=offers as an empty list until M4', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    $this->getJson("/api/v1/places/{$place->id}?include=offers")
        ->assertOk()
        ->assertJsonPath('data.offers', []);
});

it('surfaces the Google rating block and cached review snippets', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create([
        'google_rating' => 4.0,
        'google_rating_count' => 37,
        'google_reviews_json' => [
            ['author' => 'Jane D.', 'rating' => 5, 'text' => 'Best noodles in town.'],
        ],
    ]);

    $res = $this->getJson("/api/v1/places/{$place->id}")->assertOk();

    $res->assertJsonPath('data.rating.google.count', 37)
        ->assertJsonPath('data.google_reviews.0.author', 'Jane D.')
        ->assertJsonPath('data.google_reviews.0.rating', 5);
    // JSON drops the zero fraction (4.0 → 4), so compare as a float.
    expect((float) $res->json('data.rating.google.value'))->toBe(4.0);
});

it('computes the native app rating from review rows', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 5]);
    Review::factory()->create(['place_id' => $place->id, 'user_id' => User::factory(), 'rating' => 3]);

    $res = $this->getJson("/api/v1/places/{$place->id}")
        ->assertOk()
        ->assertJsonPath('data.rating.app.count', 2);
    expect((float) $res->json('data.rating.app.value'))->toBe(4.0);
});

it('returns null app rating and zero google count when there is no signal', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    $this->getJson("/api/v1/places/{$place->id}")
        ->assertOk()
        ->assertJsonPath('data.rating.app.value', null)
        ->assertJsonPath('data.rating.app.count', 0)
        ->assertJsonPath('data.rating.google.value', null)
        ->assertJsonPath('data.rating.google.count', 0)
        ->assertJsonPath('data.google_reviews', []);
});

it('404s for a missing place id', function () {
    $this->getJson('/api/v1/places/999999')->assertStatus(404);
});

it('redirects a merged place to its survivor with meta.redirected_from', function () {
    $survivor = Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Survivor']);
    $merged = Place::factory()->atPoint(51.5, -0.13)->create([
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
    ]);

    $this->getJson("/api/v1/places/{$merged->id}")
        ->assertOk()
        ->assertJsonPath('data.id', (string) $survivor->id)
        ->assertJsonPath('meta.redirected_from', $merged->slug);

    $this->getJson("/api/v1/places/{$survivor->id}")
        ->assertOk()
        ->assertJsonMissingPath('meta.redirected_from');
});

it('404s when a merged chain is not resolvable in a single hop', function () {
    $mid = Place::factory()->atPoint(51.5, -0.13)->create(['status' => 'merged']);
    $head = Place::factory()->atPoint(51.5, -0.13)->create([
        'status' => 'merged',
        'merged_into_place_id' => $mid->id,
    ]);

    $this->getJson("/api/v1/places/{$head->id}")->assertStatus(404);
});

it('exposes rate-limit headers', function () {
    $place = Place::factory()->active()->atPoint(51.5, -0.13)->create();

    $this->getJson("/api/v1/places/{$place->id}")
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});
