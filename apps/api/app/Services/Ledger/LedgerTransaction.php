<?php

namespace App\Services\Ledger;

use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;
use Illuminate\Support\Collection;

/**
 * A balanced set of entries sharing one `transaction_uuid` (T-044).
 *
 * `replayed` is the part that matters to callers: a posting attempted twice
 * with the same idempotency key returns the ORIGINAL transaction with this set,
 * rather than writing a second one or throwing. The redemption verify path is
 * retried by clients and by queues, so "this fee was already posted" has to be
 * a normal, quiet outcome — but a caller that needs to know (a Filament action
 * reporting what it did) must still be able to tell.
 */
final readonly class LedgerTransaction
{
    /**
     * @param  Collection<int, LedgerEntry>  $entries
     */
    public function __construct(
        public string $uuid,
        public Collection $entries,
        public bool $replayed = false,
    ) {}

    /** Total moved, per currency — the debit side, which equals the credit side. */
    public function total(string $currency): int
    {
        return (int) $this->entries
            ->where('currency', $currency)
            ->where('direction', LedgerDirection::Debit)
            ->sum('amount');
    }
}
