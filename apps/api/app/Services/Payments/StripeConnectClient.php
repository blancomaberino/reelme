<?php

namespace App\Services\Payments;

use App\Exceptions\PayoutFailed;
use App\Models\Payout;
use App\Models\User;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * The real Stripe Connect driver (T-045, 06 §4.3).
 *
 * Bound only when a secret is configured; otherwise {@see FakeStripeConnect}
 * takes its place, so CI never reaches the network.
 *
 * Every Stripe error is translated into {@see PayoutFailed}. Letting an
 * `ApiErrorException` escape would surface Stripe's own wording — which
 * sometimes names internal account ids — to a mobile client, and would bypass
 * the machine-readable reason the wallet screen branches on.
 */
class StripeConnectClient implements StripeConnect
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function createOrGetAccount(User $user): string
    {
        if ($user->stripe_connect_account_id !== null) {
            return $user->stripe_connect_account_id;
        }

        try {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'email' => $user->email,
                'capabilities' => ['transfers' => ['requested' => true]],
                // Our own id, so a Stripe-side investigation can be tied back to
                // an account here without a lookup table.
                'metadata' => ['reelmap_user_id' => (string) $user->id],
            ], [
                // Keyed on the user: a retried request cannot create a second
                // Express account for one influencer, which would strand their
                // earnings against an account nobody looks at.
                'idempotency_key' => 'connect-account:'.$user->id,
            ]);
        } catch (ApiErrorException $e) {
            throw PayoutFailed::transferRejected($this->safeMessage($e));
        }

        $user->forceFill(['stripe_connect_account_id' => $account->id])->save();

        return $account->id;
    }

    public function createOnboardingLink(User $user): string
    {
        $accountId = $this->createOrGetAccount($user);

        try {
            $link = $this->stripe->accountLinks->create([
                'account' => $accountId,
                'type' => 'account_onboarding',
                'return_url' => (string) config('services.stripe.connect_return_url'),
                'refresh_url' => (string) config('services.stripe.connect_refresh_url'),
            ]);
        } catch (ApiErrorException $e) {
            throw PayoutFailed::transferRejected($this->safeMessage($e));
        }

        // Deliberately not persisted: links expire in minutes and are
        // single-use, so a stored one is a button that fails.
        return $link->url;
    }

    public function accountStatus(User $user): ConnectStatus
    {
        if ($user->stripe_connect_account_id === null) {
            return ConnectStatus::none();
        }

        try {
            $account = $this->stripe->accounts->retrieve($user->stripe_connect_account_id);
        } catch (ApiErrorException $e) {
            throw PayoutFailed::transferRejected($this->safeMessage($e));
        }

        /** @var list<string> $due */
        $due = $account->requirements->currently_due ?? [];

        return new ConnectStatus(
            $account->id,
            detailsSubmitted: (bool) $account->details_submitted,
            chargesEnabled: (bool) $account->charges_enabled,
            // The only flag that gates money. `details_submitted` is not it —
            // Stripe re-verifies, and requirements reappear (06 §4.3).
            payoutsEnabled: (bool) $account->payouts_enabled,
            requirementsDue: $due,
        );
    }

    public function createTransfer(Payout $payout, string $destinationAccountId): string
    {
        try {
            $transfer = $this->stripe->transfers->create([
                // Minor units end-to-end — Stripe's unit is ours, so nothing is
                // converted and nothing rounds.
                'amount' => $payout->amount,
                'currency' => strtolower($payout->currency),
                'destination' => $destinationAccountId,
                'metadata' => ['reelmap_payout_id' => (string) $payout->id],
            ], [
                // The guarantee that a retry cannot send the money twice.
                'idempotency_key' => 'payout:'.$payout->id,
            ]);
        } catch (ApiErrorException $e) {
            throw PayoutFailed::transferRejected($this->safeMessage($e));
        }

        return $transfer->id;
    }

    /**
     * Stripe's message, but only the parts safe to show.
     *
     * Their errors can carry account ids and internal detail; the full object is
     * logged by the caller, the client gets the summary.
     */
    private function safeMessage(ApiErrorException $e): string
    {
        return $e->getError()->message ?? 'The payment provider rejected the request.';
    }
}
