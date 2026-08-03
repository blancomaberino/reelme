<?php

namespace App\Policies;

use App\Models\Offer;
use App\Models\Place;
use App\Models\User;

/**
 * Who may manage a restaurant's offers (T-042, 06 §2.1–2.2).
 *
 * One rule, applied three times: you must hold the place's VERIFIED claim. It is
 * re-derived from `place_claims` on every check rather than read off the offer's
 * `created_by_user_id` — an operator whose claim was revoked (a disputed venue,
 * a sold restaurant) must lose control of the offers they created, and the
 * ownership transfer that follows must hand those same offers to the new
 * operator without rewriting a column on every row.
 *
 * Admins are deliberately NOT granted write access here: moderation is post-hoc
 * and goes through the Filament pause action (06 §2.2), which is auditable, not
 * through the operator's own API surface.
 */
class OfferPolicy
{
    /** Creating an offer for a place the caller must operate. */
    public function create(User $user, Place $place): bool
    {
        return $user->ownsPlace($place);
    }

    /**
     * Reading a non-public offer (a draft, a paused one) — the operator's own
     * view. The public read path never consults the policy.
     */
    public function view(User $user, Offer $offer): bool
    {
        return $this->operates($user, $offer);
    }

    public function update(User $user, Offer $offer): bool
    {
        return $this->operates($user, $offer);
    }

    public function delete(User $user, Offer $offer): bool
    {
        return $this->operates($user, $offer);
    }

    /**
     * An offer whose place has been deleted out from under it has no operator —
     * fail closed rather than let a null place read as "unclaimed, so allowed".
     */
    private function operates(User $user, Offer $offer): bool
    {
        $place = $offer->place;

        return $place !== null && $user->ownsPlace($place);
    }
}
