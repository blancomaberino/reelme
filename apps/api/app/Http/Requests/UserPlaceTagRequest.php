<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a private tag to a place (T-064). `label` is required and bounded to the
 * column width; the owner is the authed user (route-guarded). Whitespace is
 * collapsed before validation so " visitar  a las 5 " and its trimmed form are
 * the same tag.
 */
class UserPlaceTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $label = $this->input('label');
        if (is_string($label)) {
            $this->merge(['label' => trim((string) preg_replace('/\s+/u', ' ', $label))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'min:1', 'max:60'],
        ];
    }
}
