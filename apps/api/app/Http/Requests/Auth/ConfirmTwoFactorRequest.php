<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /two-factor/confirm — prove the authenticator is set up correctly before
 * enforcement is switched on (T-068). Authenticated; the user is the actor.
 */
class ConfirmTwoFactorRequest extends FormRequest
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
            'code' => ['required', 'string', 'digits:6'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Authenticator apps display codes as "123 456"; users paste what they
        // see. Stripping whitespace here beats rejecting a correct code.
        if (is_string($this->code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $this->code)]);
        }
    }
}
