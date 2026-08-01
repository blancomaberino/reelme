<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /auth/two-factor-challenge — exchange the challenge token issued by a
 * correct password for a real session token (T-068).
 *
 * Exactly one of `code` / `recovery_code` is required: `required_without` both
 * ways, so an empty request is rejected rather than falling through to a
 * verification that quietly compares against nothing.
 */
class TwoFactorChallengeRequest extends FormRequest
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
            'challenge_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Same reason as ConfirmTwoFactorRequest: authenticators render codes
        // with a space in the middle and people paste them verbatim.
        if (is_string($this->code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $this->code)]);
        }

        if (is_string($this->recovery_code)) {
            $this->merge(['recovery_code' => mb_strtoupper(trim($this->recovery_code))]);
        }
    }
}
