<?php

namespace App\Services\Ledger;

/**
 * The nightly ledger check's verdict (T-044, 02 §3.15).
 *
 * Two failure shapes, kept apart because they mean different things:
 * `unbalanced` is a transaction whose debits and credits disagree — money
 * appeared or vanished. `singleEntryTransactions` is a uuid with one row, which
 * is a HALF-WRITTEN transaction: it may happen to balance nothing at all, so a
 * sum-based check would miss it entirely.
 */
final readonly class InvariantReport
{
    /**
     * @param  list<array{transaction_uuid: string, currency: string, debits: int, credits: int}>  $unbalanced
     * @param  list<string>  $singleEntryTransactions
     */
    public function __construct(
        public int $checked,
        public array $unbalanced,
        public array $singleEntryTransactions,
    ) {}

    public function isHealthy(): bool
    {
        return $this->unbalanced === [] && $this->singleEntryTransactions === [];
    }

    /** One line for a log or an alert — the thing an on-call person reads first. */
    public function summary(): string
    {
        if ($this->isHealthy()) {
            return "Ledger healthy: {$this->checked} transaction(s) balance.";
        }

        return sprintf(
            'LEDGER INVARIANT VIOLATED — %d unbalanced, %d single-entry, out of %d checked.',
            count($this->unbalanced),
            count($this->singleEntryTransactions),
            $this->checked,
        );
    }
}
