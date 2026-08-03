<?php

namespace App\Enums;

/**
 * What an offer takes off the bill (T-042, 02 §3.13).
 *
 * The case decides how `discount_value` is READ — the column is one integer
 * serving three units, which is why the meaning lives here rather than in a
 * comment at each call site:
 *
 * - `percent`      → 1–100, a percentage off (business rule narrows it to 5–50)
 * - `fixed_amount` → MINOR units of the place's currency (350 = €3.50). Never a
 *                    float: money in a float is a rounding bug waiting for a
 *                    ledger (T-044) to find it.
 * - `free_item`    → a COUNT of free items; the item itself is described in
 *                    `title`/`description`, since "free dessert" is not a number.
 */
enum OfferDiscountType: string
{
    case Percent = 'percent';
    case FixedAmount = 'fixed_amount';
    case FreeItem = 'free_item';
}
