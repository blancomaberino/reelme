<?php

namespace App\Services\Payments;

use App\Enums\LedgerAccount;
use App\Enums\PayoutStatus;
use App\Exceptions\PayoutFailed;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turning ledger balances into real money (T-045, 06 §4.3).
 *
 * The sequence is deliberate and its order is the whole safety argument:
 *
 * 1. Check the LIVE Connect status. Never the cached
 *    `stripe_connect_onboarded_at` — Stripe re-verifies, and
 *    `requirements.currently_due` can reappear months after onboarding, so a
 *    cached "onboarded" is a promise Stripe has since withdrawn.
 * 2. Take the ledger HOLD and create the payout row **in one transaction**. The
 *    influencer's available balance drops immediately, which is what stops a
 *    second request from spending the same euros while the first is in flight.
 * 3. Only then call Stripe. If it refuses, the hold is released by reversal —
 *    the money goes back to being payable, and the books stay balanced.
 *
 * Doing 3 before 2 would mean a transfer whose hold never landed; doing 2
 * without a transaction would mean a hold with no payout to release it.
 */
class PayoutService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly StripeConnect $stripe,
    ) {}

    /** 06 §4.3: €25.00 payable before anything moves; below it, the balance rolls over. */
    public function threshold(): int
    {
        return (int) config('monetization.payout_threshold_minor');
    }

    /**
     * What this user could cash out right now.
     *
     * The SIGNED ledger balance, so a void after a payout (06 §4.4) leaves a
     * negative that is carried against future earnings — there are no clawback
     * transfers in v1, and the threshold check must see the real number rather
     * than a floor of zero.
     */
    public function availableBalance(User $user, ?string $currency = null): int
    {
        return $this->ledger->balance(LedgerAccount::InfluencerEarnings, $user, $currency);
    }

    /**
     * Request a payout of the full available balance.
     *
     * @throws PayoutFailed
     */
    public function request(User $user, ?string $currency = null): Payout
    {
        $currency ??= (string) config('monetization.currency');

        if ($currency !== (string) config('monetization.currency')) {
            throw PayoutFailed::unsupportedCurrency($currency);
        }

        $status = $this->stripe->accountStatus($user);

        if ($status->accountId === null) {
            throw PayoutFailed::notOnboarded();
        }

        if (! $status->canReceiveTransfers()) {
            throw PayoutFailed::payoutsNotEnabled();
        }

        $available = $this->availableBalance($user, $currency);

        if ($available < $this->threshold()) {
            throw PayoutFailed::insufficientBalance($available, $this->threshold());
        }

        $payout = $this->openWithHold($user, $available, $currency);

        return $this->send($payout, $status->accountId);
    }

    /**
     * Create the row and take the hold atomically.
     *
     * The hold is `debit influencer_earnings / credit payout_clearing` — the
     * money stops being payable and becomes money in flight. Both halves land
     * together or neither does; a hold without a payout row would silently
     * reduce someone's balance with nothing to explain it.
     *
     * @throws PayoutFailed
     */
    private function openWithHold(User $user, int $amount, string $currency): Payout
    {
        $periodStart = Carbon::now()->startOfMonth()->toDateString();
        $periodEnd = Carbon::now()->endOfMonth()->toDateString();

        try {
            return DB::transaction(function () use ($user, $amount, $currency, $periodStart, $periodEnd): Payout {
                $payout = new Payout;
                $payout->forceFill([
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => PayoutStatus::Pending,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                ])->save();

                $this->ledger->record(
                    'payout:'.$payout->id.':hold',
                    [
                        LedgerLine::debit(LedgerAccount::InfluencerEarnings, $amount, $currency, userId: $user->id),
                        LedgerLine::credit(LedgerAccount::PayoutClearing, $amount, $currency),
                    ],
                    $payout,
                    'Payout requested',
                );

                return $payout;
            });
        } catch (UniqueConstraintViolationException) {
            // One payout per user per period (02 §3.16). A second request in the
            // same month is not an error to the caller — their balance is
            // already committed.
            throw PayoutFailed::insufficientBalance($this->availableBalance($user, $currency), $this->threshold());
        }
    }

    /**
     * Hand it to Stripe, releasing the hold if they refuse.
     *
     * @throws PayoutFailed
     */
    private function send(Payout $payout, string $destinationAccountId): Payout
    {
        try {
            $transferId = $this->stripe->createTransfer($payout, $destinationAccountId);
        } catch (PayoutFailed $e) {
            $this->fail($payout, $e->getMessage());

            throw $e;
        }

        $payout->forceFill([
            'stripe_transfer_id' => $transferId,
            'status' => PayoutStatus::Processing,
        ])->save();

        return $payout;
    }

    /**
     * Mark a payout failed and give the money back.
     *
     * Called from the Stripe error path AND from the `payout.failed` webhook, so
     * it is idempotent on the hold: reversing twice would credit the influencer
     * money they never lost, and the ledger's own idempotency key is what stops
     * that.
     */
    public function fail(Payout $payout, string $reason): void
    {
        DB::transaction(function () use ($payout, $reason): void {
            $hold = $this->ledger->findByPrefix('payout:'.$payout->id.':hold');

            if ($hold !== null) {
                $this->ledger->reverse($hold, 'payout:'.$payout->id.':release', "Payout failed: {$reason}");
            }

            $payout->forceFill([
                'status' => PayoutStatus::Failed,
                'failure_reason' => $reason,
            ])->save();
        });

        Log::warning('payout.failed', [
            'payout_id' => $payout->id,
            'user_id' => $payout->user_id,
            'amount_minor' => $payout->amount,
            'reason' => $reason,
        ]);
    }

    /**
     * Stripe confirmed the money moved.
     *
     * The hold is NOT reversed — it becomes permanent. `payout_clearing` holds
     * the amount until Stripe's own payout to the bank clears, which is a
     * platform-cash concern outside v1's scope (06 §4.2 entries 4–5).
     */
    public function markPaid(Payout $payout): void
    {
        if ($payout->status === PayoutStatus::Paid) {
            return;
        }

        // Out-of-order webhooks are normal: a `paid` for an already-failed
        // payout means the release and the transfer disagree, which a human
        // must look at — applying it silently would pay money we already
        // returned to the balance.
        if ($payout->status === PayoutStatus::Failed || $payout->status === PayoutStatus::Reversed) {
            Log::error('payout.paid_after_terminal', [
                'payout_id' => $payout->id,
                'status' => $payout->status->value,
            ]);

            return;
        }

        $payout->forceFill(['status' => PayoutStatus::Paid, 'paid_at' => now()])->save();
    }
}
