<?php

namespace App\Enums;

/**
 * Which side of a double-entry line this is (T-044, 02 §3.15).
 *
 * Every transaction's debits must equal its credits per currency — that is the
 * whole invariant the ledger rests on, and it is checked before any row is
 * written.
 */
enum LedgerDirection: string
{
    case Debit = 'debit';

    case Credit = 'credit';

    /**
     * The other side.
     *
     * Used to build reversing entries: a correction is never an UPDATE or a
     * DELETE (02 §3.15 — the ledger is append-only), it is the same lines with
     * both directions flipped, so the audit trail keeps both the mistake and
     * its undoing.
     */
    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
