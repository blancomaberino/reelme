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
use Illuminate\Support\Facades\Log;

/**
 * The dedup decision tree (04 §6), pure and injectable. Given a share's winning
 * extraction it geocodes the place name, then resolves to an existing place
 * (by google_place_id, or geo+name fuzzy match) or creates a new pending pin —
 * all under a per-canonical lock so concurrent shares can't duplicate a place.
 */
class PlaceResolver
{
    public function __construct(
        private readonly Geocoder $geocoder,
        private readonly InstagramProfileLocator $profileLocator,
    ) {}

    /**
     * Resolve a single extracted place — the first of the post's places[].
     * Back-compat entry point for callers/tests that expect one outcome.
     */
    public function resolve(Share $share): ResolutionOutcome
    {
        $all = $this->resolveAll($share);

        return $all[0]['outcome'] ?? ResolutionOutcome::geocodeFailed();
    }

    /**
     * Resolve EVERY extracted place (a multi-place post reviews several venues),
     * one outcome per place, in source order. Each place is resolved and attached
     * independently so a miss on one never blocks the others (partial publish).
     *
     * @return list<array{index: int, name: string, outcome: ResolutionOutcome}>
     */
    public function resolveAll(Share $share): array
    {
        $run = $this->winningRun($share);
        $result = $this->payload($share, $run);
        $places = $this->extractedPlaces($result);
        // The detected post language is post-level; stash it onto each place so
        // clients can label the menu language (dishes stay verbatim).
        $language = is_string($result['post']['language'] ?? null) ? $result['post']['language'] : null;
        // Review picker: a reviewer chose an existing place (validated at PATCH
        // time). It targets a single-place re-resolve, so only apply it when there
        // is exactly one place to resolve.
        $pickedId = count($places) === 1 ? $this->pickedPlaceId($share) : null;

        $out = [];
        $attachedAny = false;
        foreach ($places as $index => $place) {
            if ($language !== null) {
                $place['language'] = $language;
            }
            $name = trim((string) ($place['name'] ?? ''));

            try {
                $outcome = $this->resolveOne($share, $run, $place, $name, $pickedId);
            } catch (\Throwable $e) {
                // Per-place error (transient geocode, DB, timeout). If NOTHING has
                // attached yet, let it propagate so the job retries the whole batch
                // cleanly. But once a sibling has attached, a retry would hit the
                // "share already has a source" guard and skip re-resolution — which
                // would strand this place (not resolved, not recorded). So contain
                // it here as a per-place miss parked for review (partial publish).
                if (! $attachedAny) {
                    throw $e;
                }
                Log::warning('resolve.place_error', ['share_id' => $share->id, 'name' => $name, 'error' => $e->getMessage()]);
                $outcome = ResolutionOutcome::geocodeFailed();
            }

            if (in_array($outcome->type, [ResolutionOutcome::ATTACHED, ResolutionOutcome::CREATED], true)) {
                $attachedAny = true;
            }
            $out[] = ['index' => $index, 'name' => $name, 'outcome' => $outcome];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function resolveOne(Share $share, ?AnalysisRun $run, array $place, string $name, ?int $pickedId): ResolutionOutcome
    {
        if ($name === '') {
            return ResolutionOutcome::geocodeFailed();
        }

        // Review picker: attach straight to the chosen place, short-circuiting geocode.
        if ($pickedId !== null && ($picked = $this->pickedPlace($pickedId)) !== null) {
            $target = $this->terminal($picked);

            return ResolutionOutcome::attached($target, $this->attach($target, $share, $run, $place));
        }

        // A transient provider error throws GeocodeFailed; resolveAll() decides
        // whether to propagate it (retry) or contain it (a later place in a batch).
        $geo = $this->geocoder->findPlace($name, $this->hints($place));

        if ($this->accepted($geo)) {
            return $this->resolveWithGeo($share, $run, $place, $geo, $name);
        }

        // T-075: the geocoder found nothing. If the caption named this venue by
        // an @handle, mine its Instagram profile for a location before parking
        // the share as geocode_failed.
        return $this->resolveViaProfile($share, $run, $place, $name);
    }

    /** A geocode hit at or above the publish-confidence floor (else "not found"). */
    private function accepted(?GeocodeResult $geo): bool
    {
        return $geo !== null && $geo->score >= (float) config('places.geocode.min_score', 0.5);
    }

    /**
     * Run the dedup decision tree for an accepted geocode under a per-canonical
     * lock so concurrent shares can't duplicate a place.
     *
     * @param  array<string, mixed>  $place
     */
    private function resolveWithGeo(Share $share, ?AnalysisRun $run, array $place, GeocodeResult $geo, string $name): ResolutionOutcome
    {
        $lockKey = 'resolve:'.md5($geo->googlePlaceId !== '' ? $geo->googlePlaceId : $name.'|'.($place['address']['city'] ?? ''));

        return Cache::lock($lockKey, (int) config('places.lock_seconds', 30))
            ->block(5, fn () => $this->resolveLocked($share, $run, $place, $geo, $name));
    }

    /**
     * Instagram-profile fallback (T-075): when the geocoder missed but the place
     * carries an `@handle`, fetch that profile and try again. Priority: re-run the
     * geocoder with the profile-enriched query (so a google_place_id / dedup key
     * is still obtained and `full_name` upgrades a bare handle) → only when THAT
     * also misses, fall back to the profile's raw business-address coordinates (a
     * pending pin with no google_place_id). Any miss ends as geocode_failed.
     *
     * @param  array<string, mixed>  $place
     */
    private function resolveViaProfile(Share $share, ?AnalysisRun $run, array $place, string $name): ResolutionOutcome
    {
        if (! (bool) config('places.ig_profile.enabled', true)) {
            return ResolutionOutcome::geocodeFailed();
        }

        $handle = trim((string) ($place['handle'] ?? ''));
        if ($handle === '') {
            return ResolutionOutcome::geocodeFailed();
        }

        // Never throws — a dead/private profile just yields null.
        $location = $this->profileLocator->locate($handle);
        if ($location === null) {
            return ResolutionOutcome::geocodeFailed();
        }

        // full_name upgrades a bare @handle to the real venue name (for both the
        // geocode query and the stored snapshot/pin).
        $venueName = $location->name ?? $name;
        $place['name'] = $venueName;

        $geo = $this->geocoder->findPlace($venueName, $this->enrichHints($place, $location));
        if ($this->accepted($geo)) {
            return $this->resolveWithGeo($share, $run, $place, $geo, $venueName);
        }

        // Geocoder still missed. Use the profile's own coordinates when present,
        // rather than dropping the venue entirely.
        if ($location->hasCoordinates()) {
            return $this->resolveFromCoords($share, $run, $place, $venueName, $location);
        }

        return ResolutionOutcome::geocodeFailed();
    }

    /**
     * Attach/create from a profile's raw business-address coordinates (no
     * google_place_id) when the geocoder can't resolve the enriched query. Still
     * runs the geo+name fuzzy dedup at those coordinates so it never duplicates an
     * existing pin, under the same per-canonical lock as the geocoded path.
     *
     * @param  array<string, mixed>  $place
     */
    private function resolveFromCoords(Share $share, ?AnalysisRun $run, array $place, string $name, ProfileLocation $location): ResolutionOutcome
    {
        $lockKey = 'resolve:'.md5('ig_profile|'.$name.'|'.($location->city ?? ''));

        return Cache::lock($lockKey, (int) config('places.lock_seconds', 30))->block(5, function () use ($share, $run, $place, $name, $location) {
            $lat = (float) $location->lat;
            $lng = (float) $location->lng;
            $matches = $this->fuzzyMatches($lat, $lng, $name);

            if (count($matches) === 1) {
                /** @var Place $existing */
                $existing = Place::query()->findOrFail($matches[0]['place_id']);

                return ResolutionOutcome::attached($existing, $this->attach($existing, $share, $run, $place));
            }

            if (count($matches) > 1) {
                return ResolutionOutcome::ambiguous($matches);
            }

            $created = $this->createFromProfile($name, $place, $location);

            return ResolutionOutcome::created($created, $this->attach($created, $share, $run, $place));
        });
    }

    /**
     * The extracted place object at a given index of the share's payload — the
     * snapshot to attach when a reviewer later resolves a pending venue (T-071).
     * Uses the same payload + places[] view as resolveAll, so the index lines up
     * with the `index` recorded in `review_meta_json.pending[]`.
     *
     * @return array<string, mixed>|null
     */
    public function extractedPlaceAt(Share $share, int $index): ?array
    {
        $places = $this->extractedPlaces($this->payload($share, $this->winningRun($share)));

        return $places[$index] ?? null;
    }

    /**
     * The extracted place objects to resolve. Reads places[] (v6+); falls back to
     * a single place object for any pre-v6 payload still in flight.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    private function extractedPlaces(array $result): array
    {
        if (is_array($result['places'] ?? null)) {
            return array_values(array_filter($result['places'], 'is_array'));
        }

        return is_array($result['place'] ?? null) ? [$result['place']] : [];
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
        $matches = $this->fuzzyMatches($geo->lat, $geo->lng, $name);

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
     * Dedup matches at a point: the PostGIS candidate scan filtered to those
     * within the dedup radius AND above the name-similarity threshold. Shared by
     * the geocoded path and the IG-profile-coordinates fallback (T-075).
     *
     * @return list<array<string, mixed>>
     */
    private function fuzzyMatches(float $lat, float $lng, string $name): array
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
     * Create a pending pin from an IG profile's raw business-address coordinates
     * (T-075) — no google_place_id (the geocoder couldn't resolve it), so it lands
     * for review/enrichment. Extraction fields are still clamped to the column
     * limits (untrusted), with the profile's structured address preferred.
     *
     * @param  array<string, mixed>  $place
     */
    private function createFromProfile(string $name, array $place, ProfileLocation $location): Place
    {
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $cuisines = is_array($place['cuisines'] ?? null) ? $place['cuisines'] : [];

        $model = new Place([
            'name' => $this->truncate($name, 255),
            'address_line1' => $this->truncate($location->street ?? ($address['street'] ?? null), 255),
            'city' => $this->truncate($location->city ?? ($address['city'] ?? null), 120),
            'region' => $this->truncate($location->region ?? ($address['region'] ?? null), 120),
            'postal_code' => $this->truncate($location->postalCode ?? ($address['postal_code'] ?? null), 24),
            'country_code' => $this->countryFromAddress($address),
            'google_place_id' => null,
            'cuisine_primary' => $this->truncate($cuisines[0] ?? null, 64),
            'price_range' => $this->priceRange($place['price_range'] ?? null),
            'phone' => $this->truncate($place['phone'] ?? null, 32),
            'website' => $this->truncate($place['website'] ?? null, 2048),
            'status' => PlaceStatus::Pending,
        ]);
        $model->setPoint((float) $location->lat, (float) $location->lng);
        $model->save();
        $model->refresh();

        return $model;
    }

    /**
     * Merge an IG profile's location signal over the extraction's own hints —
     * profile values win (they're the reason we're retrying), extraction fills
     * the gaps. Feeds the second, enriched geocode attempt.
     *
     * @param  array<string, mixed>  $place
     */
    private function enrichHints(array $place, ProfileLocation $location): GeoHints
    {
        $base = $this->hints($place);

        return new GeoHints(
            street: $location->street ?? $base->street,
            city: $location->city ?? $base->city,
            region: $location->region ?? $base->region,
            postalCode: $location->postalCode ?? $base->postalCode,
            country: $base->country,
            lat: $location->lat ?? $base->lat,
            lng: $location->lng ?? $base->lng,
            language: $base->language,
        );
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
     * firstOrCreate keys on (place_id, share_id) — the surviving unique index —
     * so a place repeated across the post's places[] collapses to one source and
     * a re-resolve never duplicates. A share MAY now attach to several places.
     * Sources start unpublished (published_at null); PublishShare marks them live.
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
    private function pickedPlaceId(Share $share): ?int
    {
        $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];

        return is_numeric($meta['picked_place_id'] ?? null) ? (int) $meta['picked_place_id'] : null;
    }

    private function pickedPlace(int $pickedId): ?Place
    {
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
     * @param  array<string, mixed>  $place  a single extracted place (with `language` stashed in)
     */
    private function hints(array $place): GeoHints
    {
        $address = is_array($place['address'] ?? null) ? $place['address'] : [];
        $geo = is_array($place['geo'] ?? null) ? $place['geo'] : [];

        return new GeoHints(
            street: $address['street'] ?? null,
            city: $address['city'] ?? null,
            region: $address['region'] ?? null,
            postalCode: $address['postal_code'] ?? null,
            country: $address['country'] ?? null,
            lat: isset($geo['lat']) ? (float) $geo['lat'] : null,
            lng: isset($geo['lng']) ? (float) $geo['lng'] : null,
            language: is_string($place['language'] ?? null) ? $place['language'] : null,
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

        return $this->countryFromAddress($address);
    }

    /**
     * A 2-letter country code from the extraction address alone (no geocoder
     * components), else the 'XX' unknown sentinel. Used by the IG-profile-coords
     * path, which has no GeocodeResult.
     *
     * @param  array<string, mixed>  $address
     */
    private function countryFromAddress(array $address): string
    {
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
