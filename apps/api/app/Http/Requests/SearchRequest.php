<?php

namespace App\Http\Requests;

use App\Support\CsvList;
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
        // Absent, blank or non-string input (types[]=…) falls back to the default
        // here; the `string` rule independently rejects the last with a 422.
        return CsvList::parse($this->query('types')) ?? self::ALLOWED_TYPES;
    }
}
