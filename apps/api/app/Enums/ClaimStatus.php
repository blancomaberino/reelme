<?php

namespace App\Enums;

/**
 * Lifecycle of a claim — shared by influencer identity claims (T-038) and place
 * ownership claims (T-041), which have the same three states and differ only in
 * what is being proven. Generalised rather than duplicated per ADR-041.
 *
 * A claim starts `pending` (awaiting verification), becomes `verified` when the
 * proof lands, or `rejected` — by an admin, or automatically when another user
 * wins the same identity or place.
 */
enum ClaimStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
