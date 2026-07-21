<?php

namespace App\Services\Places;

use App\Models\Share;
use Illuminate\Validation\ValidationException;

/**
 * The review-correction merge/diff engine (T-097), lifted out of
 * `ShareController::update()` so it's unit-testable without the HTTP layer and
 * reusable by any path that applies a corrected extraction (e.g. a Filament
 * "reprocess" action).
 *
 * Given a share and a reviewer's partial correction, it overlays the correction
 * onto the original extraction (deep-merge, places[] element-by-element), folds
 * in a place candidate (manual pin or a validated `place_id` pick), and records
 * one `share_corrections` row per changed leaf. It never persists the share —
 * the caller owns validation, saving, and the publish transition.
 */
class ExtractionCorrector
{
    /**
     * The share's pre-correction extraction: the latest run's result, or [].
     *
     * @return array<string, mixed>
     */
    public function original(Share $share): array
    {
        $run = $share->analysisRun;

        return $run !== null ? ($run->result_json ?? []) : [];
    }

    /**
     * Merge a reviewer's correction (+ optional place candidate) onto the
     * original extraction and return the full merged payload. Mutates the share's
     * in-memory `review_meta_json` when a `place_id` candidate is picked (the
     * caller persists); throws {@see ValidationException} for a candidate the
     * review never offered.
     *
     * @param  array<string, mixed>|null  $extraction  the reviewer's partial correction
     * @param  array<string, mixed>|null  $candidate  a manual pin or place_id pick
     * @return array<string, mixed>
     */
    public function applyCorrection(Share $share, ?array $extraction, ?array $candidate): array
    {
        $merged = $extraction !== null ? $this->deepMerge($this->original($share), $extraction) : $this->original($share);

        if ($candidate !== null) {
            $merged = $this->applyCandidate($share, $merged, $candidate);
        }

        return $merged;
    }

    /**
     * Diff the original vs corrected payload per dotted leaf path and persist one
     * `share_corrections` row per changed leaf (jsonb model/user values). Replaces
     * any prior corrections for the share so a repeated correction stays idempotent.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $merged
     */
    public function recordCorrections(Share $share, array $original, array $merged): void
    {
        $before = $this->flattenLeaves($original);
        $after = $this->flattenLeaves($merged);

        $rows = [];
        foreach ($after as $path => $value) {
            if (mb_strlen($path) > 120) {
                continue; // beyond the field_path column — skip rather than truncate the key
            }
            if (! array_key_exists($path, $before) || $before[$path] !== $value) {
                $rows[] = [
                    'field_path' => $path,
                    'model_value' => $before[$path] ?? null,
                    'user_value' => $value,
                ];
            }
        }

        $share->corrections()->delete();
        foreach ($rows as $row) {
            $share->corrections()->create($row);
        }
    }

    /**
     * Fold a candidate override into the payload. A manual `{lat,lng}` pin becomes
     * `place.geo` (valid per schema); a `place_id` pick is stashed on
     * `review_meta_json` so ResolvePlace attaches straight to that place — but only
     * after checking the id is one the review actually offered, so a share can't be
     * attached to (and skew the counters of) an arbitrary canonical place.
     *
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    private function applyCandidate(Share $share, array $merged, array $candidate): array
    {
        if (isset($candidate['lat'], $candidate['lng'])) {
            // A manual pin corrects the first (review is single-place today).
            $places = is_array($merged['places'] ?? null) ? $merged['places'] : [];
            $first = is_array($places[0] ?? null) ? $places[0] : [];
            $first['geo'] = ['lat' => (float) $candidate['lat'], 'lng' => (float) $candidate['lng']];
            $places[0] = $first;
            $merged['places'] = $places;
        }

        if (isset($candidate['place_id'])) {
            $pickedId = (int) $candidate['place_id'];
            $meta = is_array($share->review_meta_json) ? $share->review_meta_json : [];
            $offered = array_map(
                fn ($c): int => (int) ($c['place_id'] ?? 0),
                is_array($meta['candidates'] ?? null) ? $meta['candidates'] : [],
            );

            if (! in_array($pickedId, $offered, true)) {
                throw ValidationException::withMessages([
                    'place_candidate.place_id' => ['The selected place is not among the review candidates.'],
                ]);
            }

            $meta['picked_place_id'] = $pickedId;
            $share->review_meta_json = $meta;
        }

        return $merged;
    }

    /**
     * Recursively overlay `$override` on `$base`: nested objects merge key-by-key,
     * while scalars and lists (dishes, tags) replace wholesale.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            // `places` is a list of objects — merge element-by-element so a
            // partial correction to one venue keeps its (and its siblings') other
            // fields, instead of the wholesale-list replace below.
            if ($key === 'places' && is_array($value) && array_is_list($value)
                && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergePlaces($base[$key], $value);
            } elseif (is_array($value) && ! array_is_list($value)
                && isset($base[$key]) && is_array($base[$key])) {
                // A non-list array is a non-empty associative map → merge recursively;
                // scalars, lists (dishes, tags), and empty arrays replace wholesale.
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Merge a corrected places[] onto the original element-by-element (each entry
     * is an object, merged recursively), preserving any places the reviewer left
     * untouched.
     *
     * @param  list<mixed>  $base
     * @param  list<mixed>  $override
     * @return list<mixed>
     */
    private function mergePlaces(array $base, array $override): array
    {
        foreach ($override as $index => $place) {
            if (is_array($place) && ! array_is_list($place)
                && isset($base[$index]) && is_array($base[$index])) {
                $base[$index] = $this->deepMerge($base[$index], $place);
            } else {
                $base[$index] = $place;
            }
        }

        return $base;
    }

    /**
     * Flatten a decoded payload into `dotted.path => leaf`. Associative maps
     * recurse; scalars, lists, and empty arrays are leaves (compared wholesale).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flattenLeaves(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            // `places` is a list of objects — recurse into each indexed entry so a
            // correction diffs at `places.0.name`, not the whole array.
            if ($key === 'places' && is_array($value) && array_is_list($value)) {
                foreach ($value as $index => $entry) {
                    if (is_array($entry) && ! array_is_list($entry)) {
                        $out += $this->flattenLeaves($entry, "{$path}.{$index}");
                    } else {
                        $out["{$path}.{$index}"] = $entry;
                    }
                }
            } elseif (is_array($value) && ! array_is_list($value)) {
                // Recurse into associative maps only; other lists and scalars stay leaves.
                $out += $this->flattenLeaves($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
