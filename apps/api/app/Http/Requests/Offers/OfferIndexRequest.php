<?php

namespace App\Http\Requests\Offers;

use App\Http\Requests\Concerns\ParsesNearPoint;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the public offer browse (T-042, 03 §2.12):
 * `?place_id=&near=lat,lng&radius_m=&active=1`.
 *
 * `near` arrives comma-joined and is split into range-checked fields by
 * {@see ParsesNearPoint} — the same object `/places` and `/map/places` use, so
 * one wire format cannot mean three things. It was a third hand-written copy
 * until T-156, and the copy had the hole the other two were fixed for.
 */
class OfferIndexRequest extends FormRequest
{
    use ParsesNearPoint;

    public const DEFAULT_RADIUS_M = 2000;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->mergeNearPoint();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'place_id' => ['nullable', 'integer', 'min:1'],
            ...$this->nearRules(),
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
        return $this->nearMessages();
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
