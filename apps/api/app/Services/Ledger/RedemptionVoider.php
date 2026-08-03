<?php

namespace App\Services\Ledger;

use App\Enums\RedemptionStatus;
use App\Models\Redemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Undoes a redemption a restaurant successfully disputed (T-044, 06 §4.4).
 *
 * A wrong scan, or a no-show after the code was honoured. Within the dispute
 * window an admin voids it: the fee stops being owed, and the influencer's share
 * stops being payable.
 *
 * **By reversal, never by deletion.** The original three entries stay exactly as
 * posted and a mirrored set is added, so the books show both that the fee was
 * charged and that it was reversed — which is what a restaurant asking "why is
 * this on my invoice" actually needs to see. Deleting the entries would leave a
 * clean-looking ledger that cannot explain itself.
 *
 * 06 §4.4 is explicit about the case where the influencer was already paid: the
 * negative balance is carried against future earnings. There is no clawback
 * transfer in v1, which falls out of this design for free — the reversal simply
 * makes their payable balance smaller, possibly negative, and the payout run
 * (T-045) pays what is there.
 */
class RedemptionVoider
{
    public function __construct(private readonly LedgerService $ledger) {}

    /**
     * @throws RuntimeException when the redemption is not in a voidable state
     */
    public function void(Redemption $redemption, string $reason): ?LedgerTransaction
    {
        if ($redemption->status !== RedemptionStatus::Redeemed) {
            throw new RuntimeException(
                "Only a redeemed redemption can be voided; #{$redemption->id} is {$redemption->status->value}."
            );
        }

        return DB::transaction(function () use ($redemption, $reason): ?LedgerTransaction {
            $original = $this->ledger->findByPrefix('redemption:'.$redemption->id.':capture');

            // The status flip happens whether or not there is a posting to
            // reverse: a redemption verified before T-044 shipped has no ledger
            // entries, and refusing to void it would strand it as billable
            // forever.
            $reversal = $original === null
                ? null
                : $this->ledger->reverse(
                    $original,
                    'redemption:'.$redemption->id.':void',
                    "Void: {$reason}",
                );

            // `fee_amount` is deliberately LEFT AS IT WAS. It records what was
            // charged, and the reversal records that it was given back; blanking
            // it would erase the fact a fee ever applied.
            $redemption->forceFill(['status' => RedemptionStatus::Void])->save();

            Log::info('redemption.voided', [
                'redemption_id' => $redemption->id,
                'reason' => $reason,
                'reversed_transaction' => $reversal?->uuid,
            ]);

            return $reversal;
        });
    }
}
