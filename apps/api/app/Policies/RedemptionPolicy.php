<?php

namespace App\Policies;

use App\Models\Redemption;
use App\Models\User;

/**
 * Who may see a redemption (T-043).
 *
 * Two parties legitimately have an interest in one row, and they are not the
 * same party: the DINER holding the code, and the OPERATOR of the venue it can
 * be spent at. Both are granted `view`, and nobody else is — a redemption
 * carries a live bearer token plus an attribution chain, so a third party
 * reading one gets both a free meal and someone's earnings data.
 */
class RedemptionPolicy
{
    public function view(User $user, Redemption $redemption): bool
    {
        if ((int) $redemption->user_id === (int) $user->id) {
            return true;
        }

        $place = $redemption->offer?->place;

        return $place !== null && $user->ownsPlace($place);
    }
}
