<?php

namespace App\Http\Requests\Places;

use App\Enums\PlaceClaimMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /places/{place}/claim — begin restaurant-owner verification (T-041).
 *
 * The claimant chooses a METHOD and nothing else. Notably there is no phone or
 * domain field: both come from the place record, because a claimant who could
 * nominate either could verify any venue on the map.
 */
class StartPlaceClaimRequest extends FormRequest
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
            'method' => ['required', Rule::enum(PlaceClaimMethod::class)],
        ];
    }
}
