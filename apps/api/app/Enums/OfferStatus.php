<?php

namespace App\Enums;

use App\Models\Offer;

/**
 * Lifecycle of a restaurant offer (T-042, 06 §2.2): `draft` → `active` →
 * `paused` | `expired` | `archived`.
 *
 * Only `active` is a claim about intent — whether the offer is REDEEMABLE right
 * now also depends on the validity window and the quotas, which is why
 * {@see Offer::isRedeemable()} is the single gate and this column is
 * never read alone. `expired` in particular is not maintained by the database:
 * an offer whose `ends_at` has passed keeps `status = active` until something
 * writes to it, so the window is always evaluated, never assumed.
 *
 * `archived` is the terminal state DELETE moves an offer to. Rows are never hard
 * -deleted: redemptions (T-043) and ledger entries (T-044) point at them, and a
 * fee owed against a vanished offer is unauditable.
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Expired = 'expired';
    case Archived = 'archived';

    /** Can the operator still edit or re-activate this offer? */
    public function isEditable(): bool
    {
        return $this !== self::Archived;
    }
}
