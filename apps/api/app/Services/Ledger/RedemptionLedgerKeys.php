<?php

namespace App\Services\Ledger;

use App\Models\Redemption;

/**
 * The idempotency keys a redemption's postings live under (T-044).
 *
 * One place, because two of them are written by different classes — the
 * listener posts the capture, the voider reverses it — and a key that disagreed
 * by a character would mean the void silently reversed nothing while reporting
 * success. Keys are the identity of a posting; they are not strings to retype.
 */
final class RedemptionLedgerKeys
{
    /** The fee charged when the code was honoured. */
    public static function capture(Redemption $redemption): string
    {
        return 'redemption:'.$redemption->id.':capture';
    }

    /** Its reversal, when a dispute is upheld (06 §4.4). */
    public static function void(Redemption $redemption): string
    {
        return 'redemption:'.$redemption->id.':void';
    }
}
