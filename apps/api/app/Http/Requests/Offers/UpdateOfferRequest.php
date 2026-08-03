<?php

namespace App\Http\Requests\Offers;

use App\Models\Offer;

/**
 * `PATCH /api/v1/offers/{offer}` (T-042, 03 §2.12) — edits and pause/resume.
 *
 * `place_id` is absent from the rules on purpose — an offer cannot be moved to
 * another venue. Re-pointing it would hand a second place's fees to whoever
 * happens to own the first, and there is no product reason to allow it: archive
 * and create.
 */
class UpdateOfferRequest extends OfferWriteRequest
{
    /**
     * Authorized HERE, not in the controller — and the difference is a
     * disclosure, not a style preference.
     *
     * Laravel runs `authorize()` → `rules()` → the controller. The cross-field
     * rules in {@see OfferWriteRequest} read the STORED offer (that is the whole
     * point of `effective()`), so a controller-side check would let any
     * authenticated caller PATCH a lone `ends_at` and read the stored
     * `starts_at` back out of the error message: "must end after it starts"
     * brackets it from below, "at most 90 days" from above. Two probes recover
     * the date of a draft the caller cannot even GET — {@see
     * \App\Http\Controllers\Api\V1\OfferController::show()} 404s exactly those
     * offers to prevent this. Failing here means validation never runs for a
     * non-operator.
     *
     * A missing offer resolves to null and returns false → 403 rather than 404;
     * route-model binding has already 404'd a genuinely unknown id before this
     * point, so the null case is unreachable in practice and fails closed.
     */
    public function authorize(): bool
    {
        $offer = $this->currentOffer();

        return $offer instanceof Offer && $this->user()?->can('update', $offer) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->bodyRules(creating: false);
    }
}
