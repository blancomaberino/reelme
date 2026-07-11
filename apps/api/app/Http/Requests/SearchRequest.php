<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates `GET /search` (T-031, 03 §2.11). `types` is a comma list;
 * unknown members 422 (same posture as place includes).
 */
class SearchRequest extends FormRequest
{
    private const ALLOWED_TYPES = ['places', 'users', 'influencers', 'tags'];

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
            'q' => ['required', 'string', 'min:1', 'max:120'],
            'types' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $unknown = array_diff($this->types(), self::ALLOWED_TYPES);
            if ($unknown !== []) {
                $v->errors()->add('types', 'Unknown type: '.implode(', ', $unknown).'.');
            }
        });
    }

    /**
     * Requested result types (default: all).
     *
     * @return list<string>
     */
    public function types(): array
    {
        // Non-string input (types[]=…) falls back to the default here; the
        // `string` rule independently rejects it with a 422.
        $raw = $this->query('types');
        if (! is_string($raw) || trim($raw) === '') {
            return self::ALLOWED_TYPES;
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }
}
