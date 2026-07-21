<?php

namespace App\Services\Places;

use App\Models\Place;
use Illuminate\Support\Facades\DB;

/**
 * The geo + name dedup scan (04 §6), split out of PlaceResolver (T-095). Finds
 * existing places near a point whose normalized name is similar enough to be the
 * same venue — the single definition of "looks like a duplicate", shared by the
 * pipeline resolver and the T-035 admin review queue so both surfaces agree.
 *
 * Similarity is the MAX of two complementary signals: Postgres pg_trgm
 * `similarity()` (trigram overlap) and a Jaro-Winkler edit-distance score. They
 * catch different near-misses — trigram is weak on short names and transposed
 * tokens, Jaro-Winkler rewards a shared prefix — so combining them raises dedup
 * recall. See ADR-095 (07-risks-decisions.md) for why the second signal stays.
 */
class PlaceDedupMatcher
{
    /**
     * Dedup matches at a point: candidates within the dedup radius AND above the
     * name-similarity threshold. Shared by the geocoded path and the IG-profile-
     * coordinates fallback (T-075).
     *
     * @return list<array<string, mixed>>
     */
    public function fuzzyMatches(float $lat, float $lng, string $name): array
    {
        $candidates = $this->scanCandidates($lat, $lng, Place::normalizeName($name));
        $radius = (float) config('places.dedup.radius_meters', 75);
        $threshold = (float) config('places.dedup.name_similarity_threshold', 0.85);

        return array_values(array_filter(
            $candidates,
            fn (array $c) => $c['distance_m'] < $radius && $c['similarity'] >= $threshold,
        ));
    }

    /**
     * Duplicate candidates for an existing place — the same scan the pipeline
     * dedup runs, exposed for the T-035 admin review queue. Sorted best-first,
     * excluding the place itself.
     *
     * @return list<array<string, mixed>>
     */
    public function candidatesFor(Place $place): array
    {
        ['lat' => $lat, 'lng' => $lng] = $place->coordinates();

        $candidates = array_values(array_filter(
            $this->scanCandidates($lat, $lng, $place->normalized_name),
            fn (array $c) => $c['place_id'] !== $place->id,
        ));

        usort($candidates, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanCandidates(float $lat, float $lng, string $normalized): array
    {
        $radius = (float) config('places.dedup.radius_meters', 75);

        // Status literals mirror PlaceStatus::matchable() (pending, active) — so a
        // `removed` tombstone is deliberately excluded from the fuzzy scan: reviving
        // an orphaned place is exact-google_place_id-only (resolveLocked, step 1), so
        // an unrelated near-name/near-point post can never resurrect the wrong pin.
        $rows = DB::select(
            'SELECT id, name, normalized_name, address_line1, city, region, country_code,
                    status, shares_count,
                    ST_Y(location::geometry) AS lat,
                    ST_X(location::geometry) AS lng,
                    ST_Distance(location, ST_MakePoint(?, ?)::geography) AS distance_m,
                    similarity(normalized_name, ?) AS trigram_similarity
             FROM places
             WHERE status IN (\'pending\', \'active\')
               AND merged_into_place_id IS NULL
               AND ST_DWithin(location, ST_MakePoint(?, ?)::geography, ?)',
            [$lng, $lat, $normalized, $lng, $lat, $radius]
        );

        return array_map(function ($row) use ($normalized) {
            $similarity = max(
                (float) $row->trigram_similarity,
                $this->jaroWinkler($normalized, (string) $row->normalized_name),
            );

            return [
                'place_id' => (int) $row->id,
                'name' => (string) $row->name,
                'distance_m' => round((float) $row->distance_m, 2),
                'similarity' => round($similarity, 4),
                'lat' => (float) $row->lat,
                'lng' => (float) $row->lng,
                'address' => $this->joinAddress([$row->address_line1, $row->city, $row->region, $row->country_code]),
                'status' => (string) $row->status,
                'shares_count' => (int) $row->shares_count,
            ];
        }, $rows);
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinAddress(array $parts): string
    {
        return implode(', ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
    }

    /**
     * Jaro-Winkler similarity (0–1) on two normalized names — the edit-distance
     * signal `max()`-combined with pg_trgm in {@see scanCandidates()}.
     */
    private function jaroWinkler(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $lenA = strlen($a);
        $lenB = strlen($b);
        $matchDistance = (int) max(0, floor(max($lenA, $lenB) / 2) - 1);

        $aMatches = array_fill(0, $lenA, false);
        $bMatches = array_fill(0, $lenB, false);
        $matches = 0;

        for ($i = 0; $i < $lenA; $i++) {
            $start = max(0, $i - $matchDistance);
            $end = min($i + $matchDistance + 1, $lenB);
            for ($j = $start; $j < $end; $j++) {
                if ($bMatches[$j] || $a[$i] !== $b[$j]) {
                    continue;
                }
                $aMatches[$i] = true;
                $bMatches[$j] = true;
                $matches++;
                break;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        $transpositions = 0;
        $k = 0;
        for ($i = 0; $i < $lenA; $i++) {
            if (! $aMatches[$i]) {
                continue;
            }
            while (! $bMatches[$k]) {
                $k++;
            }
            if ($a[$i] !== $b[$k]) {
                $transpositions++;
            }
            $k++;
        }
        $transpositions = (int) ($transpositions / 2);

        $jaro = (($matches / $lenA) + ($matches / $lenB) + (($matches - $transpositions) / $matches)) / 3;

        // Winkler prefix boost (up to 4 leading chars, factor 0.1).
        $prefix = 0;
        $maxPrefix = min(4, $lenA, $lenB);
        for ($i = 0; $i < $maxPrefix; $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }
            $prefix++;
        }

        return $jaro + ($prefix * 0.1 * (1 - $jaro));
    }
}
