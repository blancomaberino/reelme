<?php

namespace App\Exceptions;

use Exception;

/**
 * A payout could not be requested or sent (T-045, 06 §4.3).
 *
 * Carries a machine-readable reason because the wallet screen branches on it —
 * "you need to finish verification" and "you haven't earned enough yet" are
 * different instructions, and a single "payout failed" teaches an influencer to
 * keep tapping the button.
 */
class PayoutFailed extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /** Balance below the €25 threshold (06 §4.3) — it rolls over, nothing is wrong. */
    public static function insufficientBalance(int $available, int $threshold): self
    {
        return self::reason(
            'insufficient_balance',
            'You need a bigger balance before you can cash out.',
            422,
            ['available_minor' => $available, 'threshold_minor' => $threshold],
        );
    }

    /**
     * Stripe has not enabled payouts on the account.
     *
     * Checked LIVE, never against the cached onboarding timestamp: Stripe
     * re-verifies, and `requirements.currently_due` can reappear months later.
     */
    public static function payoutsNotEnabled(): self
    {
        return self::reason(
            'payouts_not_enabled',
            'Finish verifying your account before cashing out.',
            422,
        );
    }

    /** No Connect account at all — onboarding never started. */
    public static function notOnboarded(): self
    {
        return self::reason('not_onboarded', 'Set up payouts before cashing out.', 422);
    }

    /** EUR only in v1 (06 §4.3). */
    public static function unsupportedCurrency(string $currency): self
    {
        return self::reason('unsupported_currency', "Payouts are EUR-only; got {$currency}.", 422);
    }

    /** Stripe refused the transfer. */
    public static function transferRejected(string $message): self
    {
        return self::reason('transfer_rejected', $message, 502);
    }

    /** Stripe is not configured — a deployment problem, not a user one. */
    public static function notConfigured(): self
    {
        return self::reason('payouts_unavailable', 'Payouts are not available right now.', 503);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private static function reason(string $reason, string $message, int $status, array $extra = []): self
    {
        return new self('payout_failed', $message, $status, ['reason' => $reason] + $extra);
    }
}
