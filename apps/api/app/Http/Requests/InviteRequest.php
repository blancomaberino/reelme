<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /invites — email a batch of friends to join Reelmap (T-069). Capped at 20
 * addresses per request (the route is also rate-limited). Emails are normalized
 * to match how accounts are stored so the "already a user" skip is reliable.
 */
class InviteRequest extends FormRequest
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
        return [
            'emails' => ['required', 'array', 'min:1', 'max:20'],
            'emails.*' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->emails)) {
            $this->merge([
                'emails' => array_values(array_map(
                    fn ($e) => is_string($e) ? mb_strtolower(trim($e)) : $e,
                    $this->emails,
                )),
            ]);
        }
    }
}
