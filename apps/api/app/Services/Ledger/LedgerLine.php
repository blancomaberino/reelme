<?php

namespace App\Services\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;

/**
 * One side of a posting, before it becomes a row (T-044).
 *
 * A readonly DTO rather than an array so a caller cannot omit the currency or
 * misspell a key — this is the last point at which a money posting is still
 * plain data, and an untyped array here would push every mistake to a Postgres
 * error six frames later.
 *
 * `amount` is MINOR units and always POSITIVE. Sign is carried by `direction`,
 * because a negative amount would make one row read as a debit or a credit
 * depending on the caller, and would quietly satisfy the balance check.
 */
final readonly class LedgerLine
{
    public function __construct(
        public LedgerAccount $account,
        public LedgerDirection $direction,
        public int $amount,
        public string $currency,
        /** Subledger owner; null on `influencer_earnings` means escrow (06 §5.3). */
        public ?int $userId = null,
        public ?string $memo = null,
    ) {}

    public static function debit(
        LedgerAccount $account,
        int $amount,
        string $currency,
        ?int $userId = null,
        ?string $memo = null,
    ): self {
        return new self($account, LedgerDirection::Debit, $amount, $currency, $userId, $memo);
    }

    public static function credit(
        LedgerAccount $account,
        int $amount,
        string $currency,
        ?int $userId = null,
        ?string $memo = null,
    ): self {
        return new self($account, LedgerDirection::Credit, $amount, $currency, $userId, $memo);
    }
}
