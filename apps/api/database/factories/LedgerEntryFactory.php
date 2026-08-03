<?php

namespace Database\Factories;

use App\Enums\LedgerAccount;
use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 *
 * Deliberately spare. A ledger row on its own is meaningless — a valid ledger is
 * a BALANCED SET — so tests should post through `LedgerService::record()`, which
 * is the thing that enforces that. This factory exists for the cases that need
 * a raw row: the append-only guards, and the invariant checker, which has to be
 * given a deliberately broken transaction to have anything to find.
 */
class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_uuid' => (string) Str::uuid(),
            'account' => LedgerAccount::PlatformRevenue,
            'direction' => LedgerDirection::Credit,
            'amount' => 300,
            'currency' => 'EUR',
            'reference_type' => null,
            'reference_id' => null,
            'user_id' => null,
            'idempotency_key' => 'test:'.Str::uuid().':0',
            'memo' => null,
            'created_at' => now(),
        ];
    }
}
