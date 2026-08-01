<?php

namespace App\Http\Requests\Places;

use App\Enums\PlaceClaimMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /places/{place}/claim/verify — settle an automatic claim (T-041).
 *
 * `document` is excluded at the rule level, not handled in the controller: a
 * document claim is verified by a human in Filament, and letting it reach the
 * service would surface as "no pending claim", which is both wrong and
 * misleading to whoever is debugging it.
 */
class VerifyPlaceClaimRequest extends FormRequest
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
            'method' => [
                'required',
                Rule::enum(PlaceClaimMethod::class)
                    ->only([PlaceClaimMethod::Phone, PlaceClaimMethod::Website]),
            ],
            // Required for the phone method only — the website method is verified
            // by the backend fetching the claimant's own domain.
            'code' => ['required_if:method,phone', 'nullable', 'string', 'digits:6'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Operators paste the code out of an SMS, spaces and all.
        if (is_string($this->code)) {
            $this->merge(['code' => preg_replace('/\s+/', '', $this->code)]);
        }
    }
}
