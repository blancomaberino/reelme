<?php

namespace App\Services\Payments;

use App\Exceptions\PayoutFailed;
use App\Models\Payout;
use App\Models\User;

/**
 * Stripe, without Stripe (T-045).
 *
 * Bound whenever no secret is configured — which is every test run and every
 * developer machine that has not been given credentials. CLAUDE.md requires the
 * suite to run with no network, and a payout is not something anyone should be
 * able to fire at a live account by accident while developing.
 *
 * It is a real implementation, not a stub: accounts persist on the user, status
 * is programmable, and transfers mint plausible ids. Tests drive the INTERESTING
 * states — an account that submitted details but is not yet payouts-enabled, a
 * transfer Stripe refuses — through {@see enablePayouts()} and {@see failNextTransfer()},
 * because those are the paths that decide whether money moves.
 */
class FakeStripeConnect implements StripeConnect
{
    /** @var array<int, ConnectStatus> */
    private array $statuses = [];

    private ?string $nextTransferFailure = null;

    /** @var list<array{payout_id: int, destination: string, amount: int, currency: string}> */
    public array $transfers = [];

    public function createOrGetAccount(User $user): string
    {
        if ($user->stripe_connect_account_id !== null) {
            return $user->stripe_connect_account_id;
        }

        $accountId = 'acct_fake'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT);
        $user->forceFill(['stripe_connect_account_id' => $accountId])->save();

        // A brand-new Express account can do nothing yet — that is the honest
        // starting state, and the one a test forgetting to onboard should hit.
        $this->statuses[$user->id] = new ConnectStatus(
            $accountId,
            detailsSubmitted: false,
            chargesEnabled: false,
            payoutsEnabled: false,
            requirementsDue: ['individual.verification.document'],
        );

        return $accountId;
    }

    public function createOnboardingLink(User $user): string
    {
        $accountId = $this->createOrGetAccount($user);

        return 'https://connect.stripe.test/setup/'.$accountId.'/'.bin2hex(random_bytes(8));
    }

    public function accountStatus(User $user): ConnectStatus
    {
        if ($user->stripe_connect_account_id === null) {
            return ConnectStatus::none();
        }

        return $this->statuses[$user->id] ?? new ConnectStatus(
            $user->stripe_connect_account_id,
            detailsSubmitted: false,
            chargesEnabled: false,
            payoutsEnabled: false,
        );
    }

    public function createTransfer(Payout $payout, string $destinationAccountId): string
    {
        if ($this->nextTransferFailure !== null) {
            $message = $this->nextTransferFailure;
            $this->nextTransferFailure = null;

            throw PayoutFailed::transferRejected($message);
        }

        $this->transfers[] = [
            'payout_id' => $payout->id,
            'destination' => $destinationAccountId,
            'amount' => $payout->amount,
            'currency' => $payout->currency,
        ];

        return 'tr_fake'.str_pad((string) $payout->id, 12, '0', STR_PAD_LEFT);
    }

    // ---- test controls -----------------------------------------------------

    /** Put an account in the fully-verified state. */
    public function enablePayouts(User $user): self
    {
        $accountId = $this->createOrGetAccount($user);

        $this->statuses[$user->id] = new ConnectStatus(
            $accountId,
            detailsSubmitted: true,
            chargesEnabled: true,
            payoutsEnabled: true,
        );

        return $this;
    }

    /**
     * Details in, verification still pending — the state 06 §4.3 warns about,
     * where `details_submitted` is true and money still must not move.
     */
    public function submitDetailsOnly(User $user): self
    {
        $accountId = $this->createOrGetAccount($user);

        $this->statuses[$user->id] = new ConnectStatus(
            $accountId,
            detailsSubmitted: true,
            chargesEnabled: false,
            payoutsEnabled: false,
            requirementsDue: ['individual.verification.document'],
        );

        return $this;
    }

    public function failNextTransfer(string $message = 'Insufficient funds in the platform balance.'): self
    {
        $this->nextTransferFailure = $message;

        return $this;
    }
}
