<?php

namespace App\Adapters\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Permanent failure — the post is deleted, or private and no linked account
 * authorizes access. The caller advances the chain (ultimately to manual).
 */
class PostUnavailable extends RuntimeException implements AdapterFailure
{
    public function __construct(
        string $message = 'Post is unavailable.',
        public readonly bool $requiresAuth = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->requiresAuth ? 'fetch_auth_required' : 'fetch_unavailable';
    }
}
