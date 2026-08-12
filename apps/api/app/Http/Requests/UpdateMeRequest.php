<?php

namespace App\Http\Requests;

use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The authed user editing their own profile (PATCH /me). Every field is
 * optional (partial update); absent keys are left untouched. `username` uniqueness
 * is case-insensitive and ignores the user's own row.
 */
class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Uppercase `country_code` BEFORE validation, so `uy` is accepted and stored
     * as `UY` rather than rejected by the allow-list and then written in a casing
     * that would never match `places.country_code`. Normalizing after validation
     * would be too late for the `in:` check; normalizing in the controller would
     * be too late for the DB.
     */
    protected function prepareForValidation(): void
    {
        $code = $this->input('country_code');

        if (is_string($code)) {
            $this->merge(['country_code' => Countries::normalize($code)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->user()?->id;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'username' => [
                'sometimes', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/',
                Rule::unique('users', 'username')->ignore($id),
            ],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            // A plausible DOB: on or before today, not absurdly old.
            'birthdate' => ['sometimes', 'nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            // Items are nullable: the global ConvertEmptyStringsToNull middleware
            // turns a blank entry into null, and the controller drops empties.
            'favorite_topics' => ['sometimes', 'nullable', 'array', 'max:20'],
            'favorite_topics.*' => ['nullable', 'string', 'max:40'],
            'favorite_foods' => ['sometimes', 'nullable', 'array', 'max:20'],
            'favorite_foods.*' => ['nullable', 'string', 'max:40'],
            'is_public' => ['sometimes', 'boolean'],
            // Against the real ISO 3166-1 alpha-2 list, NOT `max:2` — the Filament
            // place form's loose length check is what lets `ZZ` into places, and
            // ICU would happily render a bogus code back as its own name.
            // Nullable so the user can un-say where they are.
            'country_code' => ['sometimes', 'nullable', 'string', Rule::in(Countries::CODES)],
        ];
    }
}
