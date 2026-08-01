<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-authentication for a destructive two-factor change — disable, view or
 * regenerate recovery codes (T-068).
 *
 * A bearer token alone must not be enough to strip the second factor off an
 * account; if it were, stealing the token would defeat the factor entirely.
 */
class PasswordConfirmationRequest extends FormRequest
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
            'password' => ['required', 'string'],
        ];
    }
}
