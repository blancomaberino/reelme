<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `GET /places/{place}/sources` (T-030, 03 §2.6) — pagination only.
 */
class PlaceSourcesRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
