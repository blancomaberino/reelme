<?php

namespace App\Adapters\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The adapter chain is exhausted and only manual upload can proceed. The calling
 * job (T-016) catches this and parks the share in `review` with
 * `review_reason: fetch_failed`, prompting the app for a caption + screen
 * recording. ManualUploadAdapter throws this when no manual payload exists yet.
 */
class NeedsManualFallback extends RuntimeException implements AdapterFailure
{
    public function __construct(
        string $message = 'Manual upload required.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): string
    {
        return 'fetch_unavailable';
    }
}
