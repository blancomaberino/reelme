<?php

namespace App\Services\Places;

use App\Enums\AnalysisStatus;
use App\Enums\PlaceStatus;
use App\Models\AnalysisRun;
use App\Models\Place;
use App\Models\PlaceSource;
use App\Models\Share;
use App\Services\Geo\Geocoder;
use App\Services\Geo\GeocodeResult;
use App\Services\Geo\GeoHints;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The dedup decision tree (04 §6), pure and injectable. Given a share's winning
 * extraction it geocodes the place name, then resolves to an existing place
 * (by google_place_id, or geo+name fuzzy match) or creates a new pending pin —
 * all under a per-canonical lock so concurrent shares can't duplicate a place.
 */
class PlaceResolver
{
    public function __construct(private readonly Geocoder $geocoder) {}

    public function resolve(Share $share): ResolutionOutcome
    {
        $run = $this->winningRun($share);
        $result = $run !== null ? ($run->result_json ?? []) : [];
        $place = is_array($result['place'] ?? null) ? $result['place'] : [];
        $name = trim((string) ($place['name'] ?? ''));

        if ($name === '') {
            return ResolutionOutcome::geocodeFailed();
        }

        // A transient provider error throws GeocodeFailed here — let it propagate
        // so the job retries; a null/low-score result is a legitimate miss.
        $geo = $this->geocoder->findPlace($name, $this->hints($result));

        $minScore = (float) config('places.geocode.min_score', 0.5);
        if ($geo === null || $geo->score < $minScore) {
            return ResolutionOutcome::geocodeFailed();
        }

        $lockKey = 'resolve:'.md5($geo->googlePlaceId !== '' ? $geo->googlePlaceId : $name.'|'.($place['address']['city'] ?? ''));

        return Cache::lock($lockKey, (int) config('places.lock_seconds', 30))
            ->block(5, fn () => $this->resolveLocked($share, $run, $place, $geo, $name));
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function resolveLocked(Share $share, ?AnalysisRun $run, array $place, GeocodeResult $geo, string $name): ResolutionOutcome
    {
        // 1. Exact google_place_id match — the primary dedup key.
        $byId = Place::query()
            ->where('google_place_id', $geo->googlePlaceId)
            ->where('status', '!=', PlaceStatus::Merged->value)
            ->first();

        if ($byId !== null) {
            $target = $this->terminal($byId);

            return ResolutionOutcome::attached($target, $this->attach($target, $share, $run, $place));
        }

        // 2. Geo + name fuzzy scan.
        $candidates = $this->candidates($geo, $name);
        $radius = (float) config('places.dedup.radius_meters', 75);
        $threshold = (float) config('places.dedup.name_similarity_threshold', 0.85);
        $matches = array_values(array_filter(
            $candidates,
            fn (array $c) => $c['distance_m'] < $radius && $c['similarity'] >= $threshold,
        ));

        if (count($matches) === 1) {
            /** @var Place $existing */
            $existing = Place::query()->findOrFail($matches[0]['place_id']);
            if ($existing->google_place_id === null) {
                $existing->google_place_id = $geo->googlePlaceId;
                $existing->save();
            }

            return ResolutionOutcome::attached($existing, $this->attach($existing, $share, $run, $place));
        }

        if (count($matches) > 1) {
            return ResolutionOutcome::ambiguous($matches);
        }

        // 3. No match — create a new pending pin.
        $created = $this->create($geo, $place);

        return ResolutionOutcome::created($created, $this->attach($created, $share, $run, $place));
    }

    /**
     * PostGIS candidate scan: places within the radius (geography meters), scored
     * by max(pg_trgm similarity, Jaro-Winkler) on accent-folded normalized names.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(GeocodeResult $geo, string $name): array
    {
        $normalized = Place::normalizeName($name);
        $radius = (float) config('places.dedup.radius_meters', 75);

        // Status literals mirror PlaceStatus::matchable() (pending, active).
        $rows = DB::select(
            'SELECT id, name, normalized_name, address_line1, city, region, country_code,
                    ST_Y(location::geometry) AS lat,
                    ST_X(location::geometry) AS lng,
                    ST_Distance(location, ST_MakePoint(?, ?)::geography) AS distance_m,
                    similarity(normalized_name, ?) AS trigram_similarity
             FROM places
             WHERE status IN (\'pending\', \'active\')
               AND merged_into_place_id IS NULL
               AND ST_DWithin(location, ST_MakePoint(?, ?)::geography, ?)',
            [$geo->lng, $geo->lat, $normalized, $geo->lng, $geo->lat, $radius]
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
            ];
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function create(GeocodeResult $geo, array $place): Place
    {
        $components = $geo->addressComponents;
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];

        $model = new Place([
            'name' => $geo->canonicalName !== '' ? $geo->canonicalName : ($place['name'] ?? 'Unknown'),
            'address_line1' => $this->component($components, 'route') ?? ($address['street'] ?? null),
            'city' => $this->component($components, 'locality') ?? ($address['city'] ?? null),
            'region' => $this->component($components, 'administrative_area_level_1') ?? ($address['region'] ?? null),
            'postal_code' => $this->component($components, 'postal_code') ?? ($address['postal_code'] ?? null),
            'country_code' => $this->countryCode($geo, $address),
            'google_place_id' => $geo->googlePlaceId,
            'cuisine_primary' => is_array($place['cuisines'] ?? null) ? ($place['cuisines'][0] ?? null) : null,
            'price_range' => $place['price_range'] ?? null,
            'phone' => $place['phone'] ?? null,
            'website' => $place['website'] ?? null,
            'status' => PlaceStatus::Pending,
        ]);
        $model->setPoint($geo->lat, $geo->lng);
        $model->save();
        $model->refresh();

        return $model;
    }

    /**
     * Attach a share's extraction to a place as a (idempotent) place_source.
     *
     * @param  array<string, mixed>  $extractedPlace
     */
    private function attach(Place $place, Share $share, ?AnalysisRun $run, array $extractedPlace): PlaceSource
    {
        $isPrimary = ! $place->sources()->where('is_primary', true)->exists();

        return PlaceSource::query()->firstOrCreate(
            ['place_id' => $place->id, 'share_id' => $share->id],
            [
                'source_post_id' => $share->source_post_id,
                'analysis_run_id' => $run?->id,
                'extraction_snapshot_json' => $extractedPlace,
                'is_primary' => $isPrimary,
            ],
        );
    }

    /** Follow a single merge hop to the surviving place. */
    private function terminal(Place $place): Place
    {
        return $place->merged_into_place_id !== null
            ? ($place->mergedInto()->first() ?? $place)
            : $place;
    }

    private function winningRun(Share $share): ?AnalysisRun
    {
        if ($share->analysis_run_id !== null && $share->analysisRun !== null) {
            return $share->analysisRun;
        }

        return $share->analysisRuns()
            ->where('status', AnalysisStatus::Succeeded->value)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function hints(array $result): GeoHints
    {
        $address = is_array($result['place']['address'] ?? null) ? $result['place']['address'] : [];
        $geo = is_array($result['place']['geo'] ?? null) ? $result['place']['geo'] : [];

        return new GeoHints(
            street: $address['street'] ?? null,
            city: $address['city'] ?? null,
            region: $address['region'] ?? null,
            postalCode: $address['postal_code'] ?? null,
            country: $address['country'] ?? null,
            lat: isset($geo['lat']) ? (float) $geo['lat'] : null,
            lng: isset($geo['lng']) ? (float) $geo['lng'] : null,
            language: $result['post']['language'] ?? null,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $components
     */
    private function component(array $components, string $type): ?string
    {
        foreach ($components as $component) {
            if (in_array($type, $component['types'] ?? [], true)) {
                $value = $component['long_name'] ?? $component['short_name'] ?? null;

                return $value !== null ? (string) $value : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $address
     */
    private function countryCode(GeocodeResult $geo, array $address): string
    {
        foreach ($geo->addressComponents as $component) {
            if (in_array('country', $component['types'] ?? [], true)) {
                $short = strtoupper((string) ($component['short_name'] ?? ''));
                if (strlen($short) === 2) {
                    return $short;
                }
            }
        }

        $extraction = strtoupper(trim((string) ($address['country'] ?? '')));

        return strlen($extraction) === 2 ? $extraction : 'XX';
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function joinAddress(array $parts): string
    {
        return implode(', ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== ''));
    }

    /** Jaro-Winkler similarity (0–1) on two normalized names. */
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
