<?php

namespace App\Exceptions;

use App\Enums\ShareStatus;
use RuntimeException;

/**
 * Thrown when code attempts an illegal share status transition (a programming
 * error). Concurrent-move races are NOT this — those return false from
 * Share::transitionTo() so jobs can exit silently.
 */
class InvalidShareTransition extends RuntimeException
{
    public function __construct(
        public readonly ShareStatus $from,
        public readonly ShareStatus $to,
    ) {
        parent::__construct("Illegal share transition: {$from->value} → {$to->value}.");
    }
}
