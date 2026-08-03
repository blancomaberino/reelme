<?php

namespace App\Services\Payments;

/**
 * What Stripe currently says about a connected account (T-045, 06 §4.3).
 *
 * The important thing this type encodes is that **KYC is not binary**.
 * `details_submitted` is not `payouts_enabled`, and `requirements_due` can
 * reappear long after onboarding finished — Stripe re-verifies. So every
 * transfer gates on the LIVE `payoutsEnabled`, never on a cached
 * `stripe_connect_onboarded_at`, which records only that onboarding once
 * completed.
 */
final readonly class ConnectStatus
{
    /**
     * @param  list<string>  $requirementsDue
     */
    public function __construct(
        public ?string $accountId,
        public bool $detailsSubmitted,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public array $requirementsDue = [],
    ) {}

    /** No account yet — the influencer has not started onboarding. */
    public static function none(): self
    {
        return new self(null, false, false, false);
    }

    /** May we send this account money right now? */
    public function canReceiveTransfers(): bool
    {
        return $this->accountId !== null && $this->payoutsEnabled;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'details_submitted' => $this->detailsSubmitted,
            'charges_enabled' => $this->chargesEnabled,
            'payouts_enabled' => $this->payoutsEnabled,
            'requirements_due' => $this->requirementsDue,
        ];
    }
}
