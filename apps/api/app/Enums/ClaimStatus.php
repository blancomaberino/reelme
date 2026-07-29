<?php

namespace App\Enums;

/**
 * Lifecycle of an influencer claim (T-038). A claim starts `pending` (a bio-code
 * awaiting verification), becomes `verified` when the identity is proven, or
 * `rejected` — by an admin, or automatically when another user wins the identity.
 */
enum ClaimStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
