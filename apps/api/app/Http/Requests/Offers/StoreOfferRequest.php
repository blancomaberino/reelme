<?php

namespace App\Http\Requests\Offers;

use App\Models\Offer;
use App\Models\Place;

/**
 * `POST /api/v1/offers` (T-042, 03 §2.12).
 *
 * The route is flat rather than nested under the place (the API spec is
 * canonical), so the place arrives in the BODY — which means authorization has
 * to resolve it before it can ask the policy anything. That happens here rather
 * than in the controller so an unauthorized create never reaches a line of
 * business logic.
 */
class StoreOfferRequest extends OfferWriteRequest
{
    private ?Place $place = null;

    /**
     * A place that does not exist, and a place the caller does not operate, both
     * answer 403 — deliberately indistinguishable. Places are a public surface,
     * so this leaks nothing; the value is that "which venues have a claimable
     * owner" cannot be probed by watching the status code change.
     */
    public function authorize(): bool
    {
        $place = $this->place();

        return $place !== null && $this->user()?->can('create', [Offer::class, $place]) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['place_id' => ['required', 'integer', 'min:1']] + $this->bodyRules(creating: true);
    }

    /** The resolved target place; null when the id is absent or unknown. */
    public function place(): ?Place
    {
        if ($this->place !== null) {
            return $this->place;
        }

        $id = $this->input('place_id');

        if (! is_numeric($id)) {
            return null;
        }

        return $this->place = Place::query()->find((int) $id);
    }
}
