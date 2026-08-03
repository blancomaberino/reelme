<?php

namespace App\Http\Requests\Offers;

use App\Http\Requests\PlaceIndexRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates the public offer browse (T-042, 03 §2.12):
 * `?place_id=&near=lat,lng&radius_m=&active=1`.
 *
 * `near` arrives comma-joined and is split into range-checked fields exactly as
 * in {@see PlaceIndexRequest} — same wire format, same
 * failure message, so a client that got one right gets the other right too.
 */
class OfferIndexRequest extends FormRequest
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
            'place_id' => ['nullable', 'integer', 'min:1'],
            'near' => ['nullable', 'string'],
            'nearLat' => ['required_with:near', 'numeric', 'between:-90,90'],
            'nearLng' => ['required_with:near', 'numeric', 'between:-180,180'],
            'radius_m' => ['nullable', 'integer', 'between:1,50000'],
            'active' => ['nullable', 'boolean'],
            // The operator's management view: every offer, every state, for the
            // venues the caller holds a verified claim on. Requires auth, which
            // the controller enforces (the endpoint itself is public).
            'mine' => ['nullable', 'boolean'],
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
        $validator->after(function (Validator $v): void {
            if ($this->query('near') !== null && ! $this->has('nearLat')) {
                $v->errors()->add('near', 'near must be "lat,lng".');
            }
        });
    }

    /**
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

    /** Restrict to offers redeemable right now (`?active=1`). */
    public function activeOnly(): bool
    {
        return $this->boolean('active');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
