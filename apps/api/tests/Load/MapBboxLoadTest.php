<?php

use App\Models\Place;
use Illuminate\Support\Facades\DB;
use Tests\Load\PlaceSeeder;
use Tests\TestCase;

/**
 * `GET /map/places` at 10k places (T-053, NFR-2).
 *
 * The map is the app's front door and it re-queries on every pan, so this is
 * the one endpoint whose cost scales with how much the product succeeds. The
 * question is not "is it fast on a laptop" — it is whether it stays fast as the
 * table grows, and those have different answers and different tests.
 *
 * So there are two kinds of assertion here, on purpose:
 *
 *  1. **Query plans** — that the bbox predicate uses the GIST index and the
 *     clustered path does not sort the whole table. These are deterministic:
 *     they hold on any machine, in CI, at any load. They are what actually keeps
 *     the endpoint fast, and they fail the moment somebody changes the predicate
 *     to something unindexable (`ST_Contains(...)` on a geometry cast, a
 *     `WHERE ... OR ...`, a function wrapped around `location`).
 *  2. **Wall clock** — recorded, and asserted only against a deliberately loose
 *     ceiling. A tight timing assertion in CI is a flaky test, and a flaky test
 *     gets deleted. The numbers are for the record in docs/load-testing.md; the
 *     ceiling is only there to catch a change that makes it *catastrophically*
 *     slower (a full seq scan, an N+1 over 300 pins).
 *
 * Registered as its own `Load` testsuite so it can be run alone, but it is NOT
 * excluded from CI: 10k rows go in as a handful of bulk INSERTs and cost a
 * couple of seconds, which is worth paying on every PR for the plan assertions.
 */
beforeEach(function () {
    PlaceSeeder::seed(10_000);
});

/** Montevideo, roughly the city — the zoom-13 "opened the app" viewport. */
const CITY_BBOX = ['minLng' => -56.25, 'minLat' => -34.95, 'maxLng' => -56.05, 'maxLat' => -34.83];

/** A few blocks — the zoom-16 "walking around" viewport, past the cluster cutoff. */
const BLOCK_BBOX = ['minLng' => -56.165, 'minLat' => -34.912, 'maxLng' => -56.155, 'maxLat' => -34.905];

/** `bbox` goes over the wire as one comma-joined string — see MapPlacesRequest. */
function mapUrl(array $bbox, int $zoom): string
{
    $joined = implode(',', [$bbox['minLng'], $bbox['minLat'], $bbox['maxLng'], $bbox['maxLat']]);

    return '/api/v1/map/places?'.http_build_query(['bbox' => $joined, 'zoom' => $zoom]);
}

/** Wall-clock for one request, in milliseconds. */
function timeRequest(TestCase $test, string $url): array
{
    $start = hrtime(true);
    $response = $test->getJson($url);
    $ms = (hrtime(true) - $start) / 1e6;

    return [$response, $ms];
}

/** The plan Postgres picks for the endpoint's real query over a given bbox. */
function planFor(array $bbox): string
{
    // Built from the REAL scope + the REAL predicate rather than hand-written
    // SQL. A copy would keep passing after `publiclyVisible()` or the bbox
    // clause changed underneath it, which is the one thing this must not do.
    $query = Place::query()
        ->publiclyVisible()
        ->whereRaw(
            'location && ST_MakeEnvelope(?, ?, ?, ?, 4326)::geography',
            [$bbox['minLng'], $bbox['minLat'], $bbox['maxLng'], $bbox['maxLat']],
        )
        ->select('places.id');

    $plan = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    return json_encode($plan[0]->{'QUERY PLAN'} ?? $plan[0]);
}

it('uses the spatial index for a selective viewport', function () {
    // THE assertion of this file. `&&` against a geography column is indexable;
    // most of the natural-looking alternatives are not, and the endpoint would
    // still return correct results while degrading to a seq scan — invisible at
    // 10k rows on a laptop and fatal at a million.
    //
    // The bbox is a few BLOCKS, not the city, and that matters: asserted over
    // the city viewport this test failed, because that bbox contains ~99% of
    // the seeded rows and a seq scan is genuinely the cheaper plan. Postgres
    // was right and the test was wrong. Index usage is only a meaningful claim
    // where the predicate is selective — which is also the case that actually
    // matters, since a user looks at a neighbourhood.
    $plan = planFor(BLOCK_BBOX);

    expect($plan)->toContain('places_location_gist')
        // Naming the index could still pass on a plan that then re-checks every
        // row, so: no Seq Scan anywhere.
        ->and($plan)->not->toContain('Seq Scan');
});

it('answers a city-wide viewport as clusters, not 10k pins', function () {
    [$response, $ms] = timeRequest($this, mapUrl(CITY_BBOX, 13));

    $response->assertOk();
    $body = $response->json();

    // Below zoom 15 the server grid-clusters. If this ever returns raw pins the
    // device gets a multi-megabyte payload and renders 10k annotations — the
    // failure is on the CLIENT, which is why it has to be asserted here.
    expect($body['data']['clusters'] ?? null)->not->toBeNull()
        ->and($body['data']['pins'] ?? [])->toBe([])
        // Bounded by CELL_CAP regardless of how many places are in view.
        ->and(count($body['data']['clusters']))->toBeLessThanOrEqual(400)
        ->and($body['meta']['total_in_bbox'])->toBeGreaterThan(1_000);

    // Loose on purpose — see the file docblock.
    expect($ms)->toBeLessThan(2_000, "city viewport took {$ms}ms");
});

it('caps a dense zoomed-in viewport at the pin limit', function () {
    [$response, $ms] = timeRequest($this, mapUrl(BLOCK_BBOX, 16));

    $response->assertOk();
    $body = $response->json();

    // At/above zoom 15 raw pins come back, capped at 300 with `truncated` set —
    // the cap is what stops a dense downtown block from becoming an unbounded
    // response.
    expect($body['data']['pins'] ?? null)->not->toBeNull()
        ->and(count($body['data']['pins']))->toBeLessThanOrEqual(300);

    expect($ms)->toBeLessThan(2_000, "block viewport took {$ms}ms");
});

it('does not get slower when the viewport is empty', function () {
    // Mid-Atlantic. A bbox with nothing in it must be cheap: the index answers
    // "no rows" without touching the heap. If this is as slow as the city
    // viewport, the index is not being used and the other tests are passing on
    // small-table luck.
    [$response, $ms] = timeRequest($this, mapUrl(
        ['minLng' => -30.0, 'minLat' => -20.0, 'maxLng' => -29.9, 'maxLat' => -19.9],
        13,
    ));

    $response->assertOk();
    expect($response->json('meta.total_in_bbox'))->toBe(0)
        ->and($ms)->toBeLessThan(500, "empty viewport took {$ms}ms");
});

it('records the timings that docs/load-testing.md quotes', function () {
    // Not an assertion about speed — the numbers themselves, printed so the doc
    // can be regenerated instead of hand-maintained (a hand-typed benchmark is
    // stale the day after it is written). 20 iterations, warm.
    $cases = [
        'city (z13, clustered)' => [CITY_BBOX, 13],
        // Same code path and same zoom as the row above, over a bbox holding a
        // fraction of the rows. The single variable is places-in-view, which is
        // what tells us whether the clustered path scales with the TABLE or
        // with the VIEWPORT — the doc claims the latter, so it has to measure
        // it rather than assert it.
        'district (z13, clustered)' => [
            ['minLng' => -56.175, 'minLat' => -34.915, 'maxLng' => -56.155, 'maxLat' => -34.895],
            13,
        ],
        'block (z16, pins)' => [BLOCK_BBOX, 16],
        'empty ocean (z13)' => [['minLng' => -30.0, 'minLat' => -20.0, 'maxLng' => -29.9, 'maxLat' => -19.9], 13],
    ];

    $lines = [];
    foreach ($cases as $label => [$bbox, $zoom]) {
        $url = mapUrl($bbox, $zoom);
        $this->getJson($url); // warm
        $samples = [];
        for ($i = 0; $i < 20; $i++) {
            [, $ms] = timeRequest($this, $url);
            $samples[] = $ms;
        }
        sort($samples);
        $lines[] = sprintf(
            '| %s | %.1f | %.1f | %.1f |',
            $label,
            $samples[(int) (0.5 * 19)],
            $samples[(int) (0.95 * 19)],
            end($samples),
        );
    }

    fwrite(STDERR, "\n[load] | viewport | p50 ms | p95 ms | max ms |\n[load] "
        .implode("\n[load] ", $lines)."\n");

    expect($lines)->toHaveCount(4);
});

it('issues a bounded number of queries however many pins come back', function () {
    DB::enableQueryLog();
    $this->getJson(mapUrl(BLOCK_BBOX, 16))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The N+1 guard, and the reason it is a COUNT rather than a duration: an
    // N+1 over 300 pins is ~300 fast queries, which on a warm local Postgres
    // can still come in under any wall-clock ceiling worth setting — and then
    // fall over on a network round trip to a managed database.
    expect($count)->toBeLessThan(10, "map request issued {$count} queries");
});
