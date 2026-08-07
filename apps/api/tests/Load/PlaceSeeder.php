<?php

namespace Tests\Load;

use App\Enums\PlaceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-seeds visible places for the map load test (T-053).
 *
 * Not a model factory, and not `Place::factory()->count(10_000)`: that is 10k
 * INSERTs plus 10k model hydrations plus every observer, and it turns a two
 * second setup into minutes. The point of this file is the *shape* of the
 * table, not the behaviour of the model — the pipeline tests cover that.
 */
class PlaceSeeder
{
    /** Montevideo. Real coordinates so the bboxes in the test mean something. */
    private const CENTER_LNG = -56.1645;

    private const CENTER_LAT = -34.9011;

    /**
     * ~11 km at this latitude. Wide enough that a city viewport holds thousands
     * and a single block holds tens — the two cases the endpoint switches
     * between.
     */
    private const SPREAD_DEG = 0.10;

    /**
     * Places are laid out DETERMINISTICALLY (a hash-free lattice with an
     * irrational stride), not randomly. A random layout makes the pin-cap and
     * cluster-count assertions flaky by construction: one run puts 280 places
     * in the test's block and the next puts 310, and the failure looks like a
     * regression in the endpoint.
     */
    public static function seed(int $count): void
    {
        $rows = [];
        $now = now()->toDateTimeString();

        for ($i = 0; $i < $count; $i++) {
            // Golden-ratio stride: spreads points evenly without clumping or
            // repeating, and gives the same layout on every run.
            $lng = self::CENTER_LNG + (fmod($i * 0.6180339887, 1.0) - 0.5) * self::SPREAD_DEG;
            $lat = self::CENTER_LAT + (fmod($i * 0.4142135624, 1.0) - 0.5) * self::SPREAD_DEG;

            $rows[] = [
                'name' => "Load Place {$i}",
                'slug' => "load-place-{$i}",
                'normalized_name' => "load place {$i}",
                // Both visible statuses, because the endpoint filters on
                // `IN (pending, active)` and a seed of one status would let a
                // regression that drops the other pass unnoticed.
                'status' => $i % 3 === 0 ? PlaceStatus::Pending->value : PlaceStatus::Active->value,
                'lng' => $lng,
                'lat' => $lat,
                'shares_count' => $i % 17,
                // NOT NULL in the schema, and the map's country/city facets
                // read it.
                'country_code' => 'UY',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunked: one 10k-row INSERT exceeds the bound-parameter limit.
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $values[] = '(?, ?, ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?, ?, ?, ?)';
                $bindings = [
                    ...$bindings,
                    $row['name'], $row['slug'], $row['normalized_name'], $row['status'],
                    $row['lng'], $row['lat'], $row['shares_count'], $row['country_code'],
                    $row['created_at'], $row['updated_at'],
                ];
            }

            DB::insert(
                'INSERT INTO places (name, slug, normalized_name, status, location, shares_count, country_code, created_at, updated_at) VALUES '
                .implode(', ', $values),
                $bindings,
            );
        }

        // Without this the planner has no statistics for a table that went from
        // empty to 10k inside one transaction, and will happily seq-scan it —
        // which would make the index assertion fail for a reason that has
        // nothing to do with the schema.
        DB::statement('ANALYZE places');
    }
}
