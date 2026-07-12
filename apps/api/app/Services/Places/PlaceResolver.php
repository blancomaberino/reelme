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
        $result = $this->payload($share, $run);
        $place = is_array($result['place'] ?? null) ? $result['place'] : [];
        $name = trim((string) ($place['name'] ?? ''));

        if ($name === '') {
            return ResolutionOutcome::geocodeFailed();
        }

        // Review picker: the user chose one of the offered candidate places (by id,
        // validated at PATCH time) — attach straight to it, short-circuiting geocode.
        if (($picked = $this->pickedPlace($share)) !== null) {
            $target = $this->terminal($picked);

            return ResolutionOutcome::attached($target, $this->attach($target, $share, $run, $place));
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

        // Attaching to an admin-hidden place would publish the share onto a
        // pin that renders nowhere, and creating a fresh one would violate the
        // unique google_place_id — park the share for a human instead (T-035).
        if ($byId !== null && $byId->status === PlaceStatus::Hidden) {
            return ResolutionOutcome::hiddenMatch();
        }

        if ($byId !== null) {
            $target = $this->terminal($byId);
            // Backfill Google's rating/reviews onto a place that predates them.
            if ($this->backfillGoogleSignal($target, $geo)) {
                $target->save();
            }

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
            $dirty = false;
            if ($existing->google_place_id === null) {
                $existing->google_place_id = $geo->googlePlaceId;
                $dirty = true;
            }
            $dirty = $this->backfillGoogleSignal($existing, $geo) || $dirty;
            if ($dirty) {
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
        return $this->scanCandidates($geo->lat, $geo->lng, Place::normalizeName($name));
    }

    /**
     * Duplicate candidates for an existing place — the same scan the pipeline
     * dedup runs, exposed for the T-035 admin review queue so both surfaces
     * agree on what "looks like a duplicate" means. Sorted best-first.
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

        // Status literals mirror PlaceStatus::matchable() (pending, active).
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
     * @param  array<string, mixed>  $place
     */
    private function create(GeocodeResult $geo, array $place): Place
    {
        $components = $geo->addressComponents;
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];

        // Fields sourced from the LLM extraction are untrusted — clamp/truncate to
        // the column limits so a bad value parks the share via review, not a
        // QueryException that burns all the job's retries as `resolve_conflict`.
        $cuisines = is_array($place['cuisines'] ?? null) ? $place['cuisines'] : [];

        $model = new Place([
            'name' => $this->truncate($geo->canonicalName !== '' ? $geo->canonicalName : (string) ($place['name'] ?? 'Unknown'), 255),
            'address_line1' => $this->truncate($this->component($components, 'route') ?? ($address['street'] ?? null), 255),
            'city' => $this->truncate($this->component($components, 'locality') ?? ($address['city'] ?? null), 120),
            'region' => $this->truncate($this->component($components, 'administrative_area_level_1') ?? ($address['region'] ?? null), 120),
            'postal_code' => $this->truncate($this->component($components, 'postal_code') ?? ($address['postal_code'] ?? null), 24),
            'country_code' => $this->countryCode($geo, $address),
            'google_place_id' => $geo->googlePlaceId,
            'cuisine_primary' => $this->truncate($cuisines[0] ?? null, 64),
            'price_range' => $this->priceRange($place['price_range'] ?? null),
            'phone' => $this->truncate($place['phone'] ?? null, 32),
            'website' => $this->truncate($place['website'] ?? null, 2048),
            'google_rating' => $geo->rating,
            'google_rating_count' => $geo->ratingCount,
            // NULL (not '[]') when no snippets came back — the ToS refresh
            // sweep keys on whereNotNull and must not treat "rating, no
            // snippets" as forever-stale cached content.
            'google_reviews_json' => $geo->reviews !== [] ? $geo->reviews : null,
            // ToS clock: cached Places review content must be refreshed or
            // dropped ~30 days after capture (reelmap:google:refresh-stale).
            'google_reviews_synced_at' => $geo->reviews !== [] ? now() : null,
            'status' => PlaceStatus::Pending,
        ]);
        $model->setPoint($geo->lat, $geo->lng);
        $model->save();
        $model->refresh();

        return $model;
    }

    /**
     * Set Google's rating/review signal on a place that lacks it (in memory —
     * caller saves). Returns whether anything changed. Used on both attach paths
     * so an existing place created before we captured reviews gets backfilled.
     */
    private function backfillGoogleSignal(Place $place, GeocodeResult $geo): bool
    {
        if ($place->google_rating !== null || $geo->rating === null) {
            return false;
        }

        $place->google_rating = (string) $geo->rating;
        $place->google_rating_count = $geo->ratingCount;
        $place->google_reviews_json = $geo->reviews !== [] ? $geo->reviews : null;
        $place->google_reviews_synced_at = $geo->reviews !== [] ? now() : null;

        return true;
    }

    /**
     * Attach a share's extraction to a place as a (idempotent) place_source.
     * firstOrCreate keys on (place_id, share_id); the table also has a global
     * unique(share_id), so this only stays exception-free because
     * ResolvePlace::run() early-returns when the share already has a source —
     * a share never resolves to two different places.
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

    /**
     * The payload to resolve against: a user's corrected extraction (captured in
     * review) when present, else the winning run's raw result. The place_source
     * still links to the winning run — the model provenance is preserved.
     *
     * @return array<string, mixed>
     */
    private function payload(Share $share, ?AnalysisRun $run): array
    {
        if (is_array($share->corrected_extraction_json)) {
            return $share->corrected_extraction_json;
        }

        return $run !== null ? ($run->result_json ?? []) : [];
    }

    /**
     * The existing place a reviewer selected from the ambiguous-candidate picker
     * (`review_meta_json.picked_place_id`, already constrained to the offered set
     * by the controller), or null when none was chosen.
     */
    private function pickedPlace(Share $share): ?Place
    {
        $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];
        $pickedId = is_numeric($meta['picked_place_id'] ?? null) ? (int) $meta['picked_place_id'] : null;

        if ($pickedId === null) {
            return null;
        }

        return Place::query()
            ->whereKey($pickedId)
            ->whereIn('status', PlaceStatus::matchable())
            ->first();
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
    /** Clamp an extracted price band to the 1–4 CHECK, else null. */
    private function priceRange(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return ($int >= 1 && $int <= 4) ? $int : null;
    }

    private function truncate(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
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
