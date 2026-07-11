<?php

use Database\Seeders\MapPerformanceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Runnable on demand: `php artisan test --group=perf`. Excluded from the default
// suite (10k seed + timing is noisy on shared CI runners).
it('serves a city-zoom viewport quickly over 10k seeded places', function () {
    $this->seed(MapPerformanceSeeder::class);

    $bbox = '-9.20,38.69,-9.10,38.75';
    $url = "/api/v1/map/places?bbox={$bbox}&zoom=13";

    // Warm caches / query plan.
    $this->getJson($url)->assertOk();

    $timings = [];
    for ($i = 0; $i < 20; $i++) {
        $start = hrtime(true);
        $this->getJson($url)->assertOk();
        $timings[] = (hrtime(true) - $start) / 1e6; // ms
    }

    sort($timings);
    $p95 = $timings[(int) floor(count($timings) * 0.95) - 1];
    fwrite(STDERR, sprintf("\nmap p95 @ zoom 13 over 10k places: %.1f ms\n", $p95));

    // Generous ceiling for a shared Docker Postgres; the production target is <300ms.
    expect($p95)->toBeLessThan(1500.0);

    // The bbox predicate must hit the GIST index, not seq-scan 10k rows — probe
    // with the controller's actual predicate shape.
    $plan = collect(DB::select(
        "EXPLAIN SELECT id FROM places
         WHERE status IN ('pending', 'active')
           AND merged_into_place_id IS NULL
           AND location && ST_MakeEnvelope(-9.20, 38.69, -9.10, 38.75, 4326)::geography"
    ))->pluck('QUERY PLAN')->implode("\n");

    expect($plan)->toContain('places_location_gist');
})->group('perf');
