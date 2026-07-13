<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create/update a place list (T-062). `name` required on create, optional on a
 * partial update; `is_public` optional. Owner is the authed user (route-guarded).
 */
class PlaceListRequest extends FormRequest
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
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
