<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body for `PATCH /shares/{id}` (T-024). Structural validation only — the merged
 * `extraction` is validated against the canonical JSON Schema in the controller,
 * where per-field failures surface as `validation_failed.details`.
 */
class UpdateShareRequest extends FormRequest
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
            'extraction' => ['nullable', 'array'],
            'place_candidate' => ['nullable', 'array'],
            // A picked candidate is identified by its place_id (validated in the
            // controller against the candidate set the review actually offered).
            'place_candidate.place_id' => ['nullable', 'integer'],
            'place_candidate.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'place_candidate.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'action' => ['nullable', 'in:publish'],
        ];
    }
}
