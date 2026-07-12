<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `GET /feed` (T-034, 03 §2.8). `global` is public; `following`
 * requires auth (checked in the controller — this request carries no guard).
 */
class FeedRequest extends FormRequest
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
            'scope' => ['nullable', Rule::in(['global', 'following'])],
            'limit' => ['nullable', 'integer', 'between:1,100'],
            'cursor' => ['nullable', 'string', 'max:1024'],
        ];
    }

    public function scope(): string
    {
        return (string) ($this->validated('scope') ?? 'global');
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? 25);
    }
}
