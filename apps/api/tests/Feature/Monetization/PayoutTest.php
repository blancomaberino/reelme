<?php

use App\Enums\LedgerAccount;
use App\Enums\PayoutStatus;
use App\Exceptions\PayoutFailed;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\User;
use App\Services\Ledger\LedgerLine;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\FakeStripeConnect;
use App\Services\Payments\PayoutService;
use App\Services\Payments\StripeConnect;

/**
 * Turning ledger balances into money (T-045, 06 §4.3).
 *
 * The organising property: **the hold is taken before Stripe is called, and
 * released if Stripe refuses.** That ordering is the whole safety argument — it
 * is what stops a second request spending the same euros while the first is in
 * flight, and what keeps the books balanced when a transfer fails.
 */
function fakeStripe(): FakeStripeConnect
{
    /** @var FakeStripeConnect $fake */
    $fake = app(StripeConnect::class);

    return $fake;
}

/** An influencer with `$amount` payable and a fully-verified Connect account. */
function earnerWith(int $amount, bool $onboarded = true): User
{
    $user = User::factory()->create();

    app(LedgerService::class)->record('seed:'.$user->id, [
        LedgerLine::debit(LedgerAccount::RestaurantReceivable, $amount, 'EUR'),
        LedgerLine::credit(LedgerAccount::InfluencerEarnings, $amount, 'EUR', userId: $user->id),
    ]);

    if ($onboarded) {
        fakeStripe()->enablePayouts($user);
    }

    return $user->refresh();
}

describe('requesting a payout', function () {
    it('takes the hold, creates the transfer, and drops the available balance', function () {
        $user = earnerWith(5000);

        $payout = app(PayoutService::class)->request($user);

        expect($payout->status)->toBe(PayoutStatus::Processing)
            ->and($payout->amount)->toBe(5000)
            ->and($payout->stripe_transfer_id)->toStartWith('tr_fake');

        // The money left the payable balance the moment it was requested — not
        // when Stripe eventually answers.
        expect(app(PayoutService::class)->availableBalance($user))->toBe(0)
            ->and(app(LedgerService::class)->balance(LedgerAccount::PayoutClearing))->toBe(5000);

        expect(fakeStripe()->transfers)->toHaveCount(1)
            ->and(fakeStripe()->transfers[0]['amount'])->toBe(5000);
    });

    it('refuses below the €25 threshold and leaves the balance untouched', function () {
        $user = earnerWith(2499);

        $details = expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'insufficient_balance');

        expect($details['available_minor'])->toBe(2499)
            ->and($details['threshold_minor'])->toBe(2500)
            // Nothing was held — the balance rolls over (06 §4.3).
            ->and(app(PayoutService::class)->availableBalance($user))->toBe(2499)
            ->and(Payout::query()->count())->toBe(0);
    });

    it('pays out at exactly the threshold', function () {
        $user = earnerWith(2500);

        expect(app(PayoutService::class)->request($user)->amount)->toBe(2500);
    });

    /*
     * 06 §4.3 is explicit: no transfer until `payouts_enabled`. The trap is
     * `details_submitted` — the influencer finished the form, Stripe has not
     * finished verifying, and a gate on the wrong flag sends money that bounces.
     */
    it('refuses an account that submitted details but is not yet payouts-enabled', function () {
        $user = earnerWith(5000, onboarded: false);
        fakeStripe()->submitDetailsOnly($user);

        expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'payouts_not_enabled');

        expect(Payout::query()->count())->toBe(0)
            ->and(app(PayoutService::class)->availableBalance($user))->toBe(5000);
    });

    it('refuses when onboarding never started', function () {
        $user = earnerWith(5000, onboarded: false);

        expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'not_onboarded');
    });

    it('refuses a currency other than EUR', function () {
        $user = earnerWith(5000);

        expectPayoutRefused(fn () => app(PayoutService::class)->request($user, 'USD'), 'unsupported_currency');
    });

    /*
     * The hold is what makes this true: the second request finds the balance
     * already spent, rather than sending the same money twice while the first
     * transfer is in flight.
     */
    it('finds nothing left on an immediate second request', function () {
        $user = earnerWith(5000);
        app(PayoutService::class)->request($user);

        expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'insufficient_balance');

        expect(Payout::query()->count())->toBe(1)
            ->and(fakeStripe()->transfers)->toHaveCount(1);
    });

    it('releases the hold when Stripe refuses the transfer', function () {
        $user = earnerWith(5000);
        fakeStripe()->failNextTransfer('Insufficient funds in the platform balance.');

        expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'transfer_rejected');

        $payout = Payout::firstOrFail();
        expect($payout->status)->toBe(PayoutStatus::Failed)
            ->and($payout->failure_reason)->toContain('Insufficient funds')
            // The money is payable again, and the books still balance.
            ->and(app(PayoutService::class)->availableBalance($user))->toBe(5000)
            ->and(app(LedgerService::class)->balance(LedgerAccount::PayoutClearing))->toBe(0);

        expect(app(LedgerService::class)->verifyInvariants()->isHealthy())->toBeTrue();
    });

    /*
     * 06 §4.4: after a void, a negative balance is carried against future
     * earnings — there are no clawback transfers in v1. The threshold check has
     * to see the SIGNED balance, or a negative would read as zero and the next
     * euro earned would look payable.
     */
    it('carries a negative balance rather than flooring it at zero', function () {
        $user = earnerWith(5000);
        app(PayoutService::class)->request($user);

        // A redemption is voided after the payout went out.
        app(LedgerService::class)->record('void:after-payout', [
            LedgerLine::debit(LedgerAccount::InfluencerEarnings, 300, 'EUR', userId: $user->id),
            LedgerLine::credit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
        ]);

        expect(app(PayoutService::class)->availableBalance($user))->toBe(-300);

        // Earning it back gets them to zero, not to a fresh payout.
        app(LedgerService::class)->record('earn:later', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 300, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 300, 'EUR', userId: $user->id),
        ]);

        expect(app(PayoutService::class)->availableBalance($user))->toBe(0);
    });
});

describe('the monthly run', function () {
    it('pays everyone eligible and skips the rest without aborting', function () {
        $ready = earnerWith(5000);
        $tooSmall = earnerWith(100);
        $notOnboarded = earnerWith(9000, onboarded: false);

        $this->artisan('reelmap:payouts:run')->assertSuccessful();

        expect(Payout::query()->where('user_id', $ready->id)->count())->toBe(1)
            ->and(Payout::query()->where('user_id', $tooSmall->id)->count())->toBe(0)
            // The un-onboarded earner is skipped, NOT allowed to abort the run.
            ->and(Payout::query()->where('user_id', $notOnboarded->id)->count())->toBe(0);
    });

    it('never sweeps escrow that belongs to nobody yet', function () {
        // Escrow: an unclaimed influencer's earnings, `user_id` null.
        app(LedgerService::class)->record('escrow:seed', [
            LedgerLine::debit(LedgerAccount::RestaurantReceivable, 9000, 'EUR'),
            LedgerLine::credit(LedgerAccount::InfluencerEarnings, 9000, 'EUR'),
        ]);

        $this->artisan('reelmap:payouts:run')->assertSuccessful();

        expect(Payout::query()->count())->toBe(0)
            ->and(app(LedgerService::class)->balance(LedgerAccount::InfluencerEarnings))->toBe(9000);
    });

    it('sends nothing on a dry run', function () {
        earnerWith(5000);

        $this->artisan('reelmap:payouts:run --dry-run')->assertSuccessful();

        expect(Payout::query()->count())->toBe(0)
            ->and(fakeStripe()->transfers)->toHaveCount(0);
    });
});

describe('the ledger stays balanced', function () {
    it('holds after a request, a failure, and a fresh request', function () {
        $user = earnerWith(5000);

        fakeStripe()->failNextTransfer();
        try {
            app(PayoutService::class)->request($user);
        } catch (PayoutFailed) {
            // expected
        }

        // The per-period unique index means the retry is a second row only if
        // the period differs; here it collides and is reported as no balance.
        expectPayoutRefused(fn () => app(PayoutService::class)->request($user), 'insufficient_balance');

        expect(app(LedgerService::class)->verifyInvariants()->isHealthy())->toBeTrue();

        $debits = (int) LedgerEntry::query()->where('direction', 'debit')->sum('amount');
        $credits = (int) LedgerEntry::query()->where('direction', 'credit')->sum('amount');
        expect($debits)->toBe($credits);
    });
});

/**
 * @return array<string, mixed>
 */
function expectPayoutRefused(Closure $call, string $reason): array
{
    try {
        $call();
    } catch (PayoutFailed $e) {
        expect($e->details()['reason'])->toBe($reason);

        return $e->details();
    }

    throw new RuntimeException("Expected a PayoutFailed with reason '{$reason}', but nothing was thrown.");
}
