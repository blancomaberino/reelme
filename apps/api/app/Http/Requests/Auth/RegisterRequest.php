<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_]+$/', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            'device_name' => ['required', 'string', 'max:255'],
            /*
             * The age gate (T-113). A neutral age screen: asked for, checked,
             * and then discarded — see the migration for why nothing is stored.
             *
             * `before_or_equal:today` rejects a future date, which would
             * otherwise produce a NEGATIVE age and sail through a naive
             * "age >= 13" comparison as ineligible-looking nonsense rather than
             * a validation error.
             *
             * The AGE itself is checked in the controller via `AgeCheck`, not
             * here: it raises a distinct `age_restricted` code so the client can
             * say "you need to be at least N" in the user's own language, and
             * it has to be reachable from social sign-in (T-067) too, which
             * never passes through this request class at all.
             */
            'date_of_birth' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalize so citext uniqueness behaves predictably and handles stay clean.
        $this->merge(array_filter([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'username' => is_string($this->username) ? trim($this->username) : $this->username,
        ], fn ($v) => $v !== null));
    }
}
