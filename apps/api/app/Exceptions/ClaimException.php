<?php

namespace App\Exceptions;

use App\Enums\Platform;
use Exception;

/**
 * A recoverable failure in the influencer claiming flow (T-038). Carries the
 * canonical error `code`, HTTP status, and a machine-readable `details.reason`
 * so the mobile flow can branch (retry vs. switch method vs. contact support).
 * Thrown from the API claim paths and mapped by ApiExceptionRenderer to the
 * standard error envelope (status + code + details.reason).
 */
class ClaimException extends Exception
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

    /** The identity is already owned by someone else — never silently reassigned. */
    /**
     * Something is already claimed by someone else.
     *
     * Both arguments are optional so the no-arg influencer call sites (T-038)
     * read exactly as before; place claims (T-041) pass their own reason and
     * wording, because "this influencer" is the wrong noun for a restaurant.
     */
    public static function conflict(
        string $reason = 'claimed_by_other',
        string $message = 'This influencer has already been claimed by another account. Contact support if this is you.',
    ): self {
        return new self('conflict', $message, 409, ['reason' => $reason]);
    }

    /** A moderator rejected this user's claim — re-claiming is blocked until appeal. */
    public static function rejected(): self
    {
        return new self(
            'forbidden',
            'A moderator rejected your claim to this identity. Contact support to appeal.',
            403,
            ['reason' => 'claim_rejected'],
        );
    }

    /** No linked platform account matches the influencer's handle. */
    public static function handleMismatch(Platform $platform): self
    {
        return new self(
            'validation_failed',
            "No linked {$platform->label()} account matches this handle. Try bio-code verification instead.",
            422,
            ['reason' => 'handle_mismatch'],
        );
    }

    /**
     * A 422 keyed by a machine-readable reason (token_not_found, token_expired,
     * profile_unavailable, no_pending_claim).
     */
    public static function reason(string $reason, string $message): self
    {
        return new self('validation_failed', $message, 422, ['reason' => $reason]);
    }
}
