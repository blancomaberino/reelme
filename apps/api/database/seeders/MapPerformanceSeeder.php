<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds 10k active places over greater Lisbon with ~20 gaussian hotspots so the
 * Map API's clustering + p95 target (T-029) can be exercised realistically.
 * Bulk-inserts in chunks with raw `ST_SetSRID(ST_MakePoint(...),4326)` so it runs
 * in seconds (bypassing model events — normalized_name/slug are set inline).
 */
class MapPerformanceSeeder extends Seeder
{
    private const TOTAL = 10000;

    private const CHUNK = 1000;

    /** Greater Lisbon bbox. */
    private const BBOX = [-9.30, 38.60, -8.90, 38.85];

    public function run(): void
    {
        $hotspots = $this->hotspots(20);
        $now = now()->toDateTimeString();
        $rows = [];

        for ($i = 0; $i < self::TOTAL; $i++) {
            [$lng, $lat] = $this->pointNear($hotspots[$i % count($hotspots)]);
            $name = 'Seed Place '.$i;

            $rows[] = [
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'normalized_name' => Str::lower($name),
                'country_code' => 'PT',
                'status' => 'active',
                'shares_count' => random_int(1, 8),
                'location' => DB::raw(sprintf('ST_SetSRID(ST_MakePoint(%s, %s), 4326)::geography',
                    number_format($lng, 8, '.', ''), number_format($lat, 8, '.', ''))),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === self::CHUNK) {
                DB::table('places')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('places')->insert($rows);
        }
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function hotspots(int $count): array
    {
        [$minLng, $minLat, $maxLng, $maxLat] = self::BBOX;
        $spots = [];
        for ($i = 0; $i < $count; $i++) {
            $spots[] = [
                $minLng + mt_rand() / mt_getrandmax() * ($maxLng - $minLng),
                $minLat + mt_rand() / mt_getrandmax() * ($maxLat - $minLat),
            ];
        }

        return $spots;
    }

    /**
     * A gaussian-jittered point around a hotspot, clamped to the bbox.
     *
     * @param  array{0: float, 1: float}  $spot
     * @return array{0: float, 1: float}
     */
    private function pointNear(array $spot): array
    {
        $sigma = 0.01; // ~1km
        $lng = max(self::BBOX[0], min(self::BBOX[2], $spot[0] + $this->gaussian() * $sigma));
        $lat = max(self::BBOX[1], min(self::BBOX[3], $spot[1] + $this->gaussian() * $sigma));

        return [$lng, $lat];
    }

    /** Standard-normal sample via Box-Muller. */
    private function gaussian(): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
