<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `GET /tags` (T-031, 03 §2.11).
 */
class TagIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:96'],
            'popular' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function popular(): bool
    {
        return (bool) ($this->validated('popular') ?? false);
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
