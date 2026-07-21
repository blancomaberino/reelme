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
        private readonly GooglePlaceRefresher $googleRefresher,
        private readonly PlaceDedupMatcher $matcher,
        private readonly PlaceFactory $factory,
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
            $matches = $this->matcher->fuzzyMatches($lat, $lng, $name);

            if (count($matches) === 1) {
                /** @var Place $existing */
                $existing = Place::query()->findOrFail($matches[0]['place_id']);

                return ResolutionOutcome::attached($existing, $this->attach($existing, $share, $run, $place));
            }

            if (count($matches) > 1) {
                return ResolutionOutcome::ambiguous($matches);
            }

            $created = $this->factory->createFromProfile($name, $place, $location);

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
            // Exact google_place_id re-match (a re-share of a known place):
            // backfill a place that predates our capture, or refresh-or-drop one
            // whose cached content has aged past the ToS window (T-080).
            if ($this->syncGoogleSignal($target, $geo)) {
                $target->save();
            }

            return ResolutionOutcome::attached($target, $this->attach($target, $share, $run, $place));
        }

        // 2. Geo + name fuzzy scan.
        $matches = $this->matcher->fuzzyMatches($geo->lat, $geo->lng, $name);

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
        $created = $this->factory->create($geo, $place);

        return ResolutionOutcome::created($created, $this->attach($created, $share, $run, $place));
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
     * Keep a re-matched place's Google signal current on an exact
     * google_place_id hit (identity is guaranteed here, so a refresh-or-drop is
     * safe — unlike the fuzzy path, which may match a different listing). A place
     * that predates our capture is backfilled; one with cached snippets past the
     * ToS window is refreshed-or-dropped from the in-hand geocode (no extra call).
     * Returns whether the model changed (caller saves).
     */
    private function syncGoogleSignal(Place $place, GeocodeResult $geo): bool
    {
        if ($place->google_rating === null) {
            return $this->backfillGoogleSignal($place, $geo);
        }

        return $this->googleRefresher->isStale($place)
            && $this->googleRefresher->applyGeocode($place, $geo);
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
}
