<?php

namespace App\Adapters\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Transient fetch failure — the caller (FetchSourcePost) should retry or advance
 * to the next adapter in the chain. Carries an optional Retry-After (seconds)
 * for 429/rate-limit responses.
 */
class FetchFailed extends RuntimeException implements AdapterFailure
{
    public function __construct(
        string $message = 'Fetch failed.',
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): string
    {
        return 'fetch_unavailable';
    }
}
