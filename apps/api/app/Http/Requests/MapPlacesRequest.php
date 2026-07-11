<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        $parts = array_map('trim', explode(',', (string) $this->query('bbox')));
        if (count($parts) === 4) {
            $this->merge([
                'minLng' => $parts[0],
                'minLat' => $parts[1],
                'maxLng' => $parts[2],
                'maxLat' => $parts[3],
            ]);
        }
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
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:96'],
            'filter' => ['nullable', Rule::in(['all', 'following', 'mine'])],
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
}
