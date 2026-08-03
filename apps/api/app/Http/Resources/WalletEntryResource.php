<?php

namespace App\Http\Resources;

use App\Enums\LedgerDirection;
use App\Models\LedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the wallet statement (T-046, 03 §3.5).
 *
 * `type` is a PRODUCT label, not the ledger account: an influencer reading their
 * statement needs "revenue share" and "payout", not `influencer_earnings`
 * debit/credit. The account plus the direction is what the ledger records; this
 * is what a person can read.
 *
 * @mixin LedgerEntry
 */
class WalletEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type(),
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'memo' => $this->memo,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * Credits are money earned; debits are money leaving — a payout, or a
     * reversal when a redemption is voided (06 §4.4). The reference tells the
     * two debits apart, because "we paid you" and "that visit was disputed" are
     * not the same news.
     */
    private function type(): string
    {
        if ($this->direction === LedgerDirection::Credit) {
            return $this->reference_type === 'influencer' ? 'escrow_release' : 'revenue_share';
        }

        return $this->reference_type === 'payout' ? 'payout' : 'adjustment';
    }
}
