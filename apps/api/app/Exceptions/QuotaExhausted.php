<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Carbon;

/**
 * A daily allowance is used up (T-051, NFR-12).
 *
 * This exists so the client can tell a DAILY cap from a BURST limit. A bare
 * `abort(429)` renders as `rate_limited` — the same code the 10/min share
 * limiter produces — and the two want opposite advice: one means "wait a few
 * seconds", the other means "come back tomorrow". A client branching on the
 * status alone tells somebody who tapped twice quickly that they are out for
 * the day.
 *
 * `details.resets_at` is the same midnight-UTC boundary `GET /me` reports, so
 * the refusal and the screen that predicted it agree to the second.
 *
 * Mapped to the standard error envelope by {@see ApiExceptionRenderer}, exactly
 * like {@see ClaimException}.
 */
class QuotaExhausted extends Exception
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'quota_exhausted';
    }

    public function status(): int
    {
        return 429;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /** The daily share allowance (NFR-12). */
    public static function shares(int $limit, Carbon $resetsAt): self
    {
        return new self(
            'You have reached your daily share limit of '.$limit.'. It resets at '.$resetsAt->toIso8601String().'.',
            ['reason' => 'daily_shares', 'limit' => $limit, 'resets_at' => $resetsAt->toIso8601String()],
        );
    }
}
