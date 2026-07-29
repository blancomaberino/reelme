<?php

namespace App\Exceptions;

use App\Enums\Platform;
use Exception;

/**
 * A recoverable failure in the influencer claiming flow (T-038). Carries the
 * canonical error `code`, HTTP status, and a machine-readable `details.reason`
 * so the mobile flow can branch (retry vs. switch method vs. contact support).
 * Mapped by ApiExceptionRenderer to the standard error envelope; the Filament
 * admin path catches it and surfaces the message as a notification instead.
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
    public static function conflict(): self
    {
        return new self(
            'conflict',
            'This influencer has already been claimed by another account. Contact support if this is you.',
            409,
            ['reason' => 'claimed_by_other'],
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
