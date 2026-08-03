<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A posting whose debits do not equal its credits (T-044, 02 §3.15).
 *
 * Thrown BEFORE anything is written, so an unbalanced set never reaches the
 * table even partially. This is a programming error, not a user-facing one —
 * there is no request a client can send that should produce it, and if one ever
 * does, the correct outcome is a 500 and an alert rather than a polite message.
 */
class UnbalancedTransaction extends RuntimeException
{
    /**
     * @param  array<string, array{debits: int, credits: int}>  $byCurrency
     */
    public static function forCurrencies(array $byCurrency): self
    {
        $detail = collect($byCurrency)
            ->map(fn (array $sums, string $currency) => "{$currency}: debits {$sums['debits']} ≠ credits {$sums['credits']}")
            ->implode('; ');

        return new self("Ledger transaction does not balance — {$detail}. Nothing was written.");
    }

    public static function empty(): self
    {
        return new self('A ledger transaction needs at least two lines; none were given.');
    }

    public static function nonPositiveAmount(int $amount): self
    {
        return new self("Ledger amounts are positive minor units; got {$amount}. Sign belongs to the direction.");
    }
}
