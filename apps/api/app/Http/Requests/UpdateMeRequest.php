<?php

namespace App\Http\Requests;

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
        ];
    }
}
