<?php

namespace App\Http\Requests;

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
        if ($this->query('near') !== null) {
            $parts = array_map('trim', explode(',', (string) $this->query('near')));
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
            if ($this->input('sort') === 'distance' && $this->query('near') === null) {
                $v->errors()->add('sort', 'sort=distance requires the near parameter.');
            }
            if ($this->query('near') !== null && ! $this->has('nearLat')) {
                $v->errors()->add('near', 'near must be "lat,lng".');
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
        if ($this->query('near') === null) {
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
