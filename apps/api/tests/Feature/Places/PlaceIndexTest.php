<?php

use App\Models\Influencer;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Models\SourcePost;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::preventLazyLoading();
});

afterEach(function () {
    Model::preventLazyLoading(false);
});

it('lists pending + active places, never merged, in the {data, meta} envelope', function () {
    $active = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Active Spot']);
    $pending = Place::factory()->atPoint(38.7180, -9.1360)->create(['name' => 'Pending Spot']);
    $survivor = Place::factory()->active()->atPoint(38.72, -9.14)->create();
    Place::factory()->atPoint(38.7169, -9.1355)->create([
        'status' => 'merged',
        'merged_into_place_id' => $survivor->id,
        'name' => 'Tombstone',
    ]);

    $res = $this->getJson('/api/v1/places')->assertOk();

    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('Active Spot')->toContain('Pending Spot')
        ->not->toContain('Tombstone');
    expect($res->json('meta.pagination.limit'))->toBe(25)
        ->and($res->json('meta.pagination'))->toHaveKeys(['next_cursor', 'prev_cursor', 'limit']);

    $row = collect($res->json('data'))->firstWhere('name', 'Active Spot');
    expect($row['id'])->toBe((string) $active->id)
        ->and($row['slug'])->toBe($active->slug)
        ->and($row['status'])->toBe('active')
        ->and($row['lat'])->toEqualWithDelta(38.7169, 0.0001)
        ->and($row['lng'])->toEqualWithDelta(-9.1355, 0.0001);
    expect(collect($res->json('data'))->firstWhere('name', 'Pending Spot')['status'])->toBe('pending');
});

it('sorts by recent (default) newest first with id tiebreak', function () {
    $old = Place::factory()->active()->atPoint(38.7, -9.1)->create(['created_at' => now()->subDays(2)]);
    $new = Place::factory()->active()->atPoint(38.7, -9.1)->create(['created_at' => now()->subHour()]);
    $mid = Place::factory()->active()->atPoint(38.7, -9.1)->create(['created_at' => now()->subDay()]);

    $ids = collect($this->getJson('/api/v1/places')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([(string) $new->id, (string) $mid->id, (string) $old->id]);
});

it('sorts by popular (shares_count desc)', function () {
    // The popular winner is the OLDER row so a fall-through to the default
    // recent sort would produce the opposite order.
    $hot = Place::factory()->active()->atPoint(38.7, -9.1)->create(['shares_count' => 9, 'created_at' => now()->subDay()]);
    $quiet = Place::factory()->active()->atPoint(38.7, -9.1)->create(['shares_count' => 1]);

    $ids = collect($this->getJson('/api/v1/places?sort=popular')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([(string) $hot->id, (string) $quiet->id]);
});

it('walks popular pages via cursor, including shares_count ties, without duplicates or gaps', function () {
    Place::factory()->active()->atPoint(38.7, -9.1)->count(5)->sequence(
        fn ($seq) => ['shares_count' => [9, 5, 5, 5, 1][$seq->index]],
    )->create();

    $page1 = $this->getJson('/api/v1/places?sort=popular&limit=2')->assertOk();
    $page2 = $this->getJson('/api/v1/places?sort=popular&limit=2&cursor='.urlencode($page1->json('meta.pagination.next_cursor')))->assertOk();
    $page3 = $this->getJson('/api/v1/places?sort=popular&limit=2&cursor='.urlencode($page2->json('meta.pagination.next_cursor')))->assertOk();

    $all = collect([...$page1->json('data'), ...$page2->json('data'), ...$page3->json('data')]);
    expect($all)->toHaveCount(5)
        ->and($all->pluck('id')->unique())->toHaveCount(5)
        ->and($all->pluck('source_count')->all())->toBe([9, 5, 5, 5, 1])
        ->and($page3->json('meta.pagination.next_cursor'))->toBeNull();
});

it('walks pages via cursor without duplicates or gaps', function () {
    Place::factory()->active()->atPoint(38.7, -9.1)->count(5)->sequence(
        fn ($seq) => ['created_at' => now()->subMinutes($seq->index)],
    )->create();

    $page1 = $this->getJson('/api/v1/places?limit=2')->assertOk();
    expect($page1->json('data'))->toHaveCount(2);
    $cursor = $page1->json('meta.pagination.next_cursor');
    expect($cursor)->not->toBeNull();

    $page2 = $this->getJson('/api/v1/places?limit=2&cursor='.urlencode($cursor))->assertOk();
    expect($page2->json('data'))->toHaveCount(2);
    $cursor2 = $page2->json('meta.pagination.next_cursor');

    $page3 = $this->getJson('/api/v1/places?limit=2&cursor='.urlencode($cursor2))->assertOk();
    expect($page3->json('data'))->toHaveCount(1)
        ->and($page3->json('meta.pagination.next_cursor'))->toBeNull();

    $all = collect([...$page1->json('data'), ...$page2->json('data'), ...$page3->json('data')])->pluck('id');
    expect($all->unique())->toHaveCount(5);
});

it('rejects a cursor minted for a different sort', function () {
    Place::factory()->active()->atPoint(38.7, -9.1)->count(3)->create();

    $cursor = $this->getJson('/api/v1/places?limit=1&sort=recent')->json('meta.pagination.next_cursor');

    $this->getJson('/api/v1/places?limit=1&sort=popular&cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a garbage cursor with 422, not 500', function () {
    $this->getJson('/api/v1/places?cursor=not-a-cursor')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('rejects a structurally-valid recent cursor whose key is not a timestamp', function (string $key) {
    $crafted = rtrim(strtr(base64_encode((string) json_encode(['s' => 'recent', 'k' => [$key, 1]])), '+/', '-_'), '=');

    $this->getJson('/api/v1/places?sort=recent&cursor='.urlencode($crafted))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
})->with([
    'not a date' => ['not-a-date'],
    'shape-valid but out of range' => ['2026-13-40 99:99:99.000000'],
    'year zero (valid in PHP, not in Postgres)' => ['0000-01-01 00:00:00.000000'],
]);

it('filters by q on normalized name (prefix and fuzzy)', function () {
    Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Lanzhou Beef Noodle House']);
    Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Sushi Corner']);

    $prefix = collect($this->getJson('/api/v1/places?q=Lanzhou')->assertOk()->json('data'))->pluck('name');
    expect($prefix)->toContain('Lanzhou Beef Noodle House')->not->toContain('Sushi Corner');

    // Typo ("lanzou") — no prefix match, caught by trigram similarity.
    $fuzzy = collect($this->getJson('/api/v1/places?q='.urlencode('lanzou beef noodle house'))->assertOk()->json('data'))->pluck('name');
    expect($fuzzy)->toContain('Lanzhou Beef Noodle House')->not->toContain('Sushi Corner');
});

it('filters by near + radius_m and reports distance_m', function () {
    // ~0.001° latitude ≈ 111 m.
    $near = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Near']);
    $mid = Place::factory()->active()->atPoint(38.7259, -9.1355)->create(['name' => 'Mid']);   // ~1 km
    Place::factory()->active()->atPoint(38.7619, -9.1355)->create(['name' => 'Far']);          // ~5 km

    $res = $this->getJson('/api/v1/places?near=38.7169,-9.1355')->assertOk(); // default 2000 m
    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('Near')->toContain('Mid')->not->toContain('Far');

    $tight = collect($this->getJson('/api/v1/places?near=38.7169,-9.1355&radius_m=500')->assertOk()->json('data'))->pluck('name');
    expect($tight)->toContain('Near')->not->toContain('Mid');

    $row = collect($res->json('data'))->firstWhere('name', 'Mid');
    expect($row['distance_m'])->toBeGreaterThan(800)->toBeLessThan(1300);
});

it('sorts by distance nearest-first and paginates stably', function () {
    $a = Place::factory()->active()->atPoint(38.7170, -9.1355)->create(['name' => 'A']);
    $b = Place::factory()->active()->atPoint(38.7220, -9.1355)->create(['name' => 'B']);
    $c = Place::factory()->active()->atPoint(38.7300, -9.1355)->create(['name' => 'C']);

    $page1 = $this->getJson('/api/v1/places?near=38.7169,-9.1355&sort=distance&limit=2')->assertOk();
    expect(collect($page1->json('data'))->pluck('name')->all())->toBe(['A', 'B']);

    $cursor = $page1->json('meta.pagination.next_cursor');
    $page2 = $this->getJson('/api/v1/places?near=38.7169,-9.1355&sort=distance&limit=2&cursor='.urlencode($cursor))->assertOk();
    expect(collect($page2->json('data'))->pluck('name')->all())->toBe(['C'])
        ->and($page2->json('meta.pagination.next_cursor'))->toBeNull();
});

it('422s sort=distance without near', function () {
    $this->getJson('/api/v1/places?sort=distance')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed');
});

it('422s a malformed near and an out-of-range limit', function () {
    $this->getJson('/api/v1/places?near=garbage')->assertStatus(422);
    $this->getJson('/api/v1/places?near=91,0')->assertStatus(422);
    $this->getJson('/api/v1/places?limit=200')->assertStatus(422);
    $this->getJson('/api/v1/places?limit=0')->assertStatus(422);
});

it('filters by influencer_id via source attribution', function () {
    $influencer = Influencer::factory()->create();
    $other = Influencer::factory()->create();

    $mine = Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Featured']);
    $theirs = Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Other']);

    foreach ([[$mine, $influencer], [$theirs, $other]] as [$place, $inf]) {
        $post = SourcePost::factory()->create(['influencer_id' => $inf->id]);
        PlaceSource::factory()->create([
            'place_id' => $place->id,
            'source_post_id' => $post->id,
            'share_id' => Share::factory()->create(['source_post_id' => $post->id])->id,
        ]);
    }

    $names = collect(
        $this->getJson('/api/v1/places?influencer_id='.$influencer->id)->assertOk()->json('data')
    )->pluck('name');

    expect($names)->toContain('Featured')->not->toContain('Other');
});

it('filters by tags[] via the pivot (live since T-031)', function () {
    $tagged = Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Tagged']);
    Place::factory()->active()->atPoint(38.7, -9.1)->create(['name' => 'Untagged']);
    $tag = Tag::factory()->create(['slug' => 'ramen', 'name' => 'Ramen']);
    $tagged->tags()->attach($tag->id, ['source' => 'extraction']);

    $names = collect($this->getJson('/api/v1/places?tags[]=ramen')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Tagged')->not->toContain('Untagged');

    // Multiple slugs are OR'd — a place needs any one of them.
    $ored = collect($this->getJson('/api/v1/places?tags[]=ramen&tags[]=sushi')->assertOk()->json('data'))->pluck('name');
    expect($ored)->toContain('Tagged')->not->toContain('Untagged');

    // A slug matching nothing filters everything out (no longer a no-op).
    expect($this->getJson('/api/v1/places?tags[]=nope')->assertOk()->json('data'))->toBe([]);
});

it('exposes rate-limit headers', function () {
    $this->getJson('/api/v1/places')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '120');
});
