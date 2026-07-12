<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates `GET /places/{place}` (T-030, 03 §2.6). `include` is a comma list;
 * unknown members are rejected (422) rather than silently ignored so typos
 * surface during client development.
 */
class PlaceShowRequest extends FormRequest
{
    private const ALLOWED_INCLUDES = ['sources', 'offers', 'reviews'];

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
            'include' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $unknown = array_diff($this->includes(), self::ALLOWED_INCLUDES);
            if ($unknown !== []) {
                $v->errors()->add('include', 'Unknown include: '.implode(', ', $unknown).'.');
            }
        });
    }

    /**
     * The requested include members (deduped, empty when absent).
     *
     * @return list<string>
     */
    public function includes(): array
    {
        $raw = (string) $this->query('include', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }
}
