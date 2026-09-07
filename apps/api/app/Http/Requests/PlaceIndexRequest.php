<?php

namespace App\Http\Requests;

use App\Models\Dish;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validates the public place index query (T-030, 03 §2.6). `near` arrives as a
 * comma-joined `lat,lng`; it is split here into named, range-checked fields.
 * `sort=distance` is only meaningful relative to a point, so it requires `near`.
 */
class PlaceIndexRequest extends FormRequest
{
    public const DEFAULT_RADIUS_M = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // `is_string`, not `!== null`: this runs before the rules, so
        // `?near[]=1&near[]=2` would cast an array to string — a PHP warning
        // Laravel promotes to a 500 on a public route (found by review on T-042).
        $near = $this->query('near');

        if (is_string($near)) {
            $parts = array_map('trim', explode(',', $near));
            if (count($parts) === 2) {
                $this->merge(['nearLat' => $parts[0], 'nearLng' => $parts[1]]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:96'],
            'card' => ['nullable', 'string', 'max:64'],
            // "…that do pasta" (T-157). The minimum gives the caller a 422
            // instead of a silently empty page; the REAL floor is
            // `Dish::MIN_QUERY` on the normalized needle in
            // {@see PlaceQueryBuilder::servingDish()}, because this rule counts
            // raw characters and `?dish=p.` would clear it.
            'dish' => ['nullable', 'string', 'min:'.Dish::MIN_QUERY, 'max:'.Dish::MAX_NAME],
            // "…and open right now" (T-158). A cheap boolean, but the answer is
            // not: a place with no structured hours or no timezone is EXCLUDED,
            // never assumed open — {@see PlaceQueryBuilder::openNow()}.
            'open_now' => ['nullable', 'boolean'],
            'near' => ['nullable', 'string'],
            'nearLat' => ['required_with:near', 'numeric', 'between:-90,90'],
            'nearLng' => ['required_with:near', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:1,50000'],
            'influencer_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['recent', 'popular', 'distance'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nearLat.required_with' => 'near must be "lat,lng".',
            'nearLng.required_with' => 'near must be "lat,lng".',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            if ($this->input('sort') === 'distance' && ! is_string($this->query('near'))) {
                $v->errors()->add('sort', 'sort=distance requires the near parameter.');
            }
            if (is_string($this->query('near')) && ! $this->has('nearLat')) {
                $v->errors()->add('near', 'near must be "lat,lng".');
            }
            // `open_now` has to ride a point, for the same reason `sort=distance`
            // does — except here the reason is cost, not meaning. The filter is a
            // correlated EXISTS over `place_open_periods`, and without
            // `ST_DWithin` to cut the candidate set first there is nothing at all
            // between an unauthenticated `?open_now=1` and the opening periods of
            // every publicly visible place. The personal listings are scoped to
            // one user; this was the only surface with no bound whatsoever.
            //
            // Be precise about how much this buys, because the honest answer is
            // "less than it looks". `radius_m` tops out at 50km — around 7,850
            // km², some fifteen times Montevideo — so for the corpus we actually
            // have, a point inside the city still encloses nearly all of it, and
            // at that selectivity the planner drops the GiST bound for a
            // sequential scan. What genuinely caps the work is the
            // `(created_at, id)` index added in the same release: it lets
            // `sort=recent` walk rows in order and stop at the LIMIT instead of
            // sorting everything the filters left. This rule is the cheap half.
            //
            // The map is NOT covered by this and is not comparably bounded: its
            // bbox is capped at 90° of span, which is a sanity check rather than
            // a viewport, so a hostile caller can ask for the same set there.
            // Left alone deliberately — narrowing the map's span is a product
            // decision about how far a user may zoom out, not a validation fix.
            //
            // Guarded on the base rule so the two messages under this key cannot
            // contradict each other: `?open_now=yes` fails `boolean` above, and
            // `boolean()` here (filter_var) would read the same value as true and
            // add a second, unrelated complaint about `near`.
            if (! $v->errors()->has('open_now')
                && $this->boolean('open_now')
                && ! is_string($this->query('near'))) {
                $v->errors()->add('open_now', 'open_now requires the near parameter.');
            }
        });
    }

    /**
     * The validated near point, if given.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function nearPoint(): ?array
    {
        if (! is_string($this->query('near'))) {
            return null;
        }

        return [
            'lat' => (float) $this->validated('nearLat'),
            'lng' => (float) $this->validated('nearLng'),
        ];
    }

    public function radiusM(): int
    {
        return (int) ($this->validated('radius_m') ?? self::DEFAULT_RADIUS_M);
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'recent');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
