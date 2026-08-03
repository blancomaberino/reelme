<?php

namespace App\Services\Redemptions;

use App\Models\Redemption;

/**
 * The outcome of a verification (T-043).
 *
 * Carries `replayed` so the restaurant UI can tell a first scan from a repeat of
 * one it already made. Both are SUCCESS — 03 §1's idempotency semantics — but
 * they are not the same thing to say out loud: "redeemed" versus "you already
 * scanned this one". Collapsing them into a bare success is how staff end up
 * honouring the same code twice because the screen looked identical.
 */
final readonly class VerifyResult
{
    private function __construct(
        public Redemption $redemption,
        public bool $replayed,
    ) {}

    /** This call is the one that flipped it. */
    public static function fresh(Redemption $redemption): self
    {
        return new self($redemption, replayed: false);
    }

    /** Already redeemed — the prior result, returned again rather than an error. */
    public static function replay(Redemption $redemption): self
    {
        return new self($redemption, replayed: true);
    }
}
