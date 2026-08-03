<?php

namespace App\Http\Requests\Offers;

/**
 * `PATCH /api/v1/offers/{offer}` (T-042, 03 §2.12) — edits and pause/resume.
 *
 * Authorization is the controller's `authorize('update', $offer)`, not this
 * class: the offer is a route-model binding, so the policy has a real object and
 * a missing row 404s before anything here runs.
 *
 * `place_id` is absent from the rules on purpose — an offer cannot be moved to
 * another venue. Re-pointing it would hand a second place's fees to whoever
 * happens to own the first, and there is no product reason to allow it: archive
 * and create.
 */
class UpdateOfferRequest extends OfferWriteRequest
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
        return $this->bodyRules(creating: false);
    }
}
