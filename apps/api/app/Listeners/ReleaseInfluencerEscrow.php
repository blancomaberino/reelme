<?php

namespace App\Listeners;

use App\Enums\LedgerAccount;
use App\Events\InfluencerClaimed;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use Illuminate\Support\Facades\Log;

/**
 * Hands an influencer the money that was waiting for them (T-044, 06 §5.3).
 *
 * Until an identity is claimed, its share of every redemption accrues as ESCROW:
 * a credit to `influencer_earnings` with `user_id = null`. The money is owed —
 * we simply do not know to whom, so there is no subledger to put it in. When
 * someone proves they are that influencer (T-038), this moves the whole balance
 * into their subledger in one balanced transaction:
 *
 *     debit  influencer_earnings (user_id null)  ← close the escrow
 *     credit influencer_earnings (user_id = them) ← open their payable
 *
 * A transfer, not a re-write. The original credits stay exactly as posted, which
 * is what lets an audit still see that the money accrued in November and was
 * released in January — a correction that edited the old rows would lose that,
 * and the ledger is append-only anyway (02 §3.15).
 *
 * NOT queued. T-038 already dispatches this AFTER its claim transaction commits
 * (and only on a genuinely fresh claim, never an idempotent re-claim), so the
 * ownership change is durable before a cent moves. Queuing would add a window in
 * which the identity is claimed and the money is not yet theirs, for no benefit.
 */
class ReleaseInfluencerEscrow
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function handle(InfluencerClaimed $event): void
    {
        $currency = (string) config('monetization.currency');
        $escrowed = $this->ledger->escrowBalance($event->influencer, $currency);

        // Nothing waiting is the common case — most identities are claimed
        // before they ever earn. Not an error, and not worth a log line.
        if ($escrowed <= 0) {
            return;
        }

        $this->ledger->record(
            // Keyed on the influencer, so a replayed claim moves the balance
            // once. If they somehow accrue escrow AGAIN after claiming, that is
            // a bug upstream (the posting path reads claimed_by_user_id), and a
            // second release under the same key must not paper over it.
            'influencer:'.$event->influencer->id.':claim-escrow',
            [
                LedgerLine::debit(LedgerAccount::InfluencerEarnings, $escrowed, $currency, userId: null),
                LedgerLine::credit(LedgerAccount::InfluencerEarnings, $escrowed, $currency, userId: $event->user->id),
            ],
            // Referenced to the INFLUENCER, not a redemption: this settles every
            // redemption that accrued to the identity at once, and it is what
            // lets escrowBalance() net the release against the accruals.
            reference: $event->influencer,
            memo: 'Escrow released to @'.$event->influencer->handle.' on claim',
        );

        Log::info('ledger.escrow_released', [
            'influencer_id' => $event->influencer->id,
            'user_id' => $event->user->id,
            'amount_minor' => $escrowed,
            'currency' => $currency,
        ]);
    }
}
