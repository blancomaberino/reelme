<?php

namespace App\Http\Requests;

use App\Models\Dish;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates a map viewport query (T-029, 03 §3.3). `bbox` arrives as a
 * comma-joined `minLng,minLat,maxLng,maxLat`; it is split here into named,
 * range-checked fields. A bbox crossing the antimeridian (minLng > maxLng) is
 * rejected for M2 — see MapController.
 */
class MapPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Same guard as `near` above: an array `?bbox[]=…` would be cast to
        // string here, before the `string` rule can reject it, and the warning
        // surfaces as a 500 rather than a 422.
        $bbox = $this->query('bbox');
        $parts = is_string($bbox) ? array_map('trim', explode(',', $bbox)) : [];
        if (count($parts) === 4) {
            $this->merge([
                'minLng' => $parts[0],
                'minLat' => $parts[1],
                'maxLng' => $parts[2],
                'maxLat' => $parts[3],
            ]);
        }

        // The viewer point, split exactly as PlaceIndexRequest splits it — same
        // spelling, same `is_string` guard against `?near[]=1&near[]=2` becoming
        // a 500 before the rules can 422 it.
        $near = $this->query('near');
        if (is_string($near)) {
            $nearParts = array_map('trim', explode(',', $near));
            if (count($nearParts) === 2) {
                $this->merge(['nearLat' => $nearParts[0], 'nearLng' => $nearParts[1]]);
            }
        }
    }

    /**
     * The viewer's position, or null when they did not share one.
     *
     * Null is the load-bearing case: every field derived from it is omitted from
     * the pin rather than defaulted, because a distance of 0 and a "Closed" badge
     * are both lies that look like data.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function nearPoint(): ?array
    {
        if (! is_string($this->query('near')) || $this->validated('nearLat') === null) {
            return null;
        }

        return [
            'lat' => (float) $this->validated('nearLat'),
            'lng' => (float) $this->validated('nearLng'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bbox' => ['required', 'string'],
            'minLng' => ['required', 'numeric', 'between:-180,180'],
            'minLat' => ['required', 'numeric', 'between:-90,90', 'lt:maxLat'],
            'maxLng' => ['required', 'numeric', 'between:-180,180', 'gt:minLng'],
            'maxLat' => ['required', 'numeric', 'between:-90,90'],
            'zoom' => ['required', 'integer', 'between:1,20'],
            'cuisine' => ['nullable', 'string', 'max:64'],
            'price_range' => ['nullable', 'integer', 'between:1,4'],
            'card' => ['nullable', 'string', 'max:64'],
            // The viewer's own position (T-156). Optional: the map works without
            // it, and every viewer-relative field on a pin is ABSENT rather than
            // faked when it is missing.
            'near' => ['nullable', 'string'],
            'nearLat' => ['required_with:near', 'numeric', 'between:-90,90'],
            'nearLng' => ['required_with:near', 'numeric', 'between:-180,180'],
            // "…that do pasta", on the surface where that question is actually
            // asked (T-157). A FormRequest ignores unknown parameters, so
            // omitting this rule would not 422 `?dish=` on the map — it would
            // return the whole viewport with a 200, which is precisely the
            // "the caller believes they filtered" failure servingDish() guards
            // against, relocated to the surface that forgot the filter.
            'dish' => ['nullable', 'string', 'min:'.Dish::MIN_QUERY, 'max:'.Dish::MAX_NAME],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:96'],
            'filter' => ['nullable', Rule::in(['all', 'following', 'mine'])],
            // Restrict the map to a single owned place list (T-062 → map filter).
            'list' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'maxLng.gt' => 'A bbox crossing the antimeridian is not supported.',
            'minLat.lt' => 'minLat must be south of maxLat.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // A globe-spanning bbox makes ST_MakeEnvelope(...)::geography an invalid
        // (too-large) polygon → a DB error. The map is for local browsing, so cap
        // the span well below a hemisphere.
        $validator->after(function ($v) {
            if (! $v->errors()->isEmpty()) {
                return;
            }
            $lngSpan = abs((float) $this->input('maxLng') - (float) $this->input('minLng'));
            $latSpan = abs((float) $this->input('maxLat') - (float) $this->input('minLat'));
            if ($lngSpan > 90 || $latSpan > 90) {
                $v->errors()->add('bbox', 'The viewport is too large; zoom in.');
            }

            // A malformed `near` is a 422, never a silently ignored parameter.
            // The alternative is a map that answers 200 with no distances while
            // the caller believes it sent a position — the same "you think you
            // filtered" failure the dish filter guards against.
            if (is_string($this->query('near')) && ! $this->has('nearLat')) {
                $v->errors()->add('near', 'near must be "lat,lng".');
            }
        });
    }
}
