<?php

namespace App\Support;

/**
 * The `{amount, currency}` pair every money field in the API answers in
 * (03 §3.5, 06 §4).
 *
 * It was a private `money()` on the wallet controller, then a second copy on
 * the influencer dashboard controller, then a third in the metrics service —
 * which is how a currency starts being formatted two different ways on two
 * screens that show the same balance. One shape, one place.
 *
 * `amount` is always MINOR units and always an int. Never a float and never
 * pre-divided: these numbers get multiplied by basis points and summed across a
 * month's invoice, and a float drifts a cent per thousand redemptions.
 */
final class Money
{
    /**
     * @return array{amount: int, currency: string}
     */
    public static function minor(int $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }
}
