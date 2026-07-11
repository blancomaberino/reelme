<?php

use App\Models\Place;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Scout\EngineManager;
use Meilisearch\Client;
use Meilisearch\Contracts\TasksQuery;

uses(RefreshDatabase::class);

/*
 * Real-engine tests (@group meilisearch): typo tolerance and index settings
 * need the actual server (Sail service locally, service container in CI).
 * Unreachable server → skipped, never red.
 */

beforeEach(function () {
    $host = (string) env('MEILISEARCH_HOST', 'http://meilisearch:7700');
    $key = env('MEILISEARCH_KEY');

    try {
        $this->meili = new Client($host, $key === null ? null : (string) $key);
        $this->meili->health();
    } catch (Throwable) {
        $this->markTestSkipped("Meilisearch not reachable at {$host}.");
    }

    // Unique per-test prefix so runs never see each other's documents; the
    // engine manager re-reads scout.driver on each engine() call.
    $this->prefix = 'reelmap_testing_'.Str::lower(Str::random(8)).'_';
    config([
        'scout.driver' => 'meilisearch',
        'scout.prefix' => $this->prefix,
        'scout.meilisearch.host' => $host,
    ]);
    app(EngineManager::class)->forgetDrivers();

    $this->artisan('scout:sync-index-settings')->assertSuccessful();
    waitForMeili($this->meili);
});

afterEach(function () {
    if (isset($this->meili, $this->prefix)) {
        foreach (['places', 'tags', 'influencers'] as $table) {
            try {
                $this->meili->deleteIndex($this->prefix.$table);
            } catch (Throwable) {
                // index may not exist — fine
            }
        }
    }
    config(['scout.driver' => 'collection', 'scout.prefix' => 'reelmap_testing_']);
    app(EngineManager::class)->forgetDrivers();
});

/** Block until Meilisearch has drained its task queue (never sleep blindly). */
function waitForMeili(Client $client): void
{
    foreach (range(1, 50) as $i) {
        $pending = $client->getTasks(
            (new TasksQuery)->setStatuses(['enqueued', 'processing'])
        )->getResults();
        if ($pending === []) {
            return;
        }
        usleep(100_000);
    }

    throw new RuntimeException('Meilisearch task queue did not settle.');
}

it('is typo-tolerant: "nodle" finds the noodle place', function () {
    Place::factory()->active()->atPoint(51.5117, -0.13)->create(['name' => 'Lanzhou Beef Noodle House']);
    Place::factory()->active()->atPoint(51.5, -0.14)->create(['name' => 'Sushi Corner']);
    waitForMeili($this->meili);

    $names = collect($this->getJson('/api/v1/search?q=nodle&types=places')->assertOk()->json('data.places'))
        ->pluck('name');

    expect($names)->toContain('Lanzhou Beef Noodle House')->not->toContain('Sushi Corner');
})->group('meilisearch');

it('multi-searches every requested type in one call and reports took_ms', function () {
    Place::factory()->active()->atPoint(51.5117, -0.13)->create(['name' => 'Lanzhou Beef Noodle House']);
    Tag::factory()->create(['name' => 'Noodles', 'slug' => 'noodles']);
    waitForMeili($this->meili);

    $res = $this->getJson('/api/v1/search?q=noodle')->assertOk();

    expect(collect($res->json('data.places'))->pluck('name'))->toContain('Lanzhou Beef Noodle House')
        ->and(collect($res->json('data.tags'))->pluck('slug'))->toContain('noodles')
        ->and($res->json('meta.took_ms'))->toBeInt();
})->group('meilisearch');

it('indexes place documents with _geo and drops merged places on save', function () {
    $place = Place::factory()->active()->atPoint(38.7169, -9.1355)->create(['name' => 'Geo Cafe']);
    waitForMeili($this->meili);

    $doc = $this->meili->index($this->prefix.'places')->getDocument((string) $place->id);
    expect($doc['_geo']['lat'])->toEqualWithDelta(38.7169, 0.0001)
        ->and($doc['_geo']['lng'])->toEqualWithDelta(-9.1355, 0.0001);

    $survivor = Place::factory()->active()->atPoint(38.7, -9.1)->create();
    $place->status = 'merged';
    $place->merged_into_place_id = $survivor->id;
    $place->save();
    waitForMeili($this->meili);

    $names = collect($this->getJson('/api/v1/search?q=geo+cafe&types=places')->assertOk()->json('data.places'))
        ->pluck('name');
    expect($names)->not->toContain('Geo Cafe');
})->group('meilisearch');

it('reindex command rebuilds indexes with settings idempotently', function () {
    Place::factory()->active()->atPoint(51.5, -0.13)->create(['name' => 'Reindexed Ramen']);
    waitForMeili($this->meili);

    $this->artisan('reelmap:search:reindex')->assertSuccessful();
    waitForMeili($this->meili);
    $this->artisan('reelmap:search:reindex')->assertSuccessful();
    waitForMeili($this->meili);

    $settings = $this->meili->index($this->prefix.'places')->getSettings();
    expect($settings['filterableAttributes'])->toContain('price_range', 'tags', '_geo');

    $names = collect($this->getJson('/api/v1/search?q=ramen&types=places')->assertOk()->json('data.places'))
        ->pluck('name');
    expect($names)->toContain('Reindexed Ramen');
})->group('meilisearch');
