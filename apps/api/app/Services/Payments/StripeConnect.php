<?php

namespace App\Services\Payments;

use App\Exceptions\PayoutFailed;
use App\Models\Payout;
use App\Models\User;

/**
 * The Stripe Connect surface the application depends on (T-045).
 *
 * An interface, with a fake implementation bound whenever no secret is
 * configured, for two reasons that matter more than testability in the
 * abstract: CI must run with **no network and no credentials** (CLAUDE.md), and
 * a payout is not something anyone should be able to trigger accidentally
 * against a live account while developing.
 *
 * Deliberately small. Everything Stripe-shaped — Account, AccountLink, Transfer
 * — is reduced to the four questions the product actually asks.
 */
interface StripeConnect
{
    /** The influencer's Express account id, creating one on first call. */
    public function createOrGetAccount(User $user): string;

    /**
     * A fresh hosted-onboarding URL.
     *
     * Never stored: account links expire in minutes and are single-use, so a
     * cached one is a broken button. `POST /wallet/connect/onboarding-link` is
     * "create or refresh" for exactly this reason.
     */
    public function createOnboardingLink(User $user): string;

    /** Live account state — the gate every transfer checks (06 §4.3). */
    public function accountStatus(User $user): ConnectStatus;

    /**
     * Move money to the connected account.
     *
     * Idempotency-keyed on the payout id, so a retried request cannot create a
     * second Transfer for one payout row.
     *
     * @return string the Stripe transfer id
     *
     * @throws PayoutFailed
     */
    public function createTransfer(Payout $payout, string $destinationAccountId): string;
}
