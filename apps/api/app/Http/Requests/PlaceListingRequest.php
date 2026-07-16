<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the personal + per-user place listings (T-071, ADR-071): the "my
 * places" listing (`GET /me/places`) and a user's public places listing
 * (`GET /users/{username}/places`). Both take the same faceted filters —
 * country, type (cuisine), tags — over the same dataset as the corresponding
 * map, plus keyset pagination. (Distinct from {@see PlaceListRequest}, which
 * creates/edits a saved place *list* — this filters a *listing* of places.)
 * Auth is enforced by route middleware, not here.
 */
class PlaceListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // country codes are case-insensitive; canonicalise to upper so the
        // char(2) column comparison matches regardless of client casing.
        if (is_string($country = $this->query('country'))) {
            $this->merge(['country' => strtoupper($country)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'type' => ['nullable', 'string', 'max:64'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:96'],
            'sort' => ['nullable', Rule::in(['recent', 'popular'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
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
