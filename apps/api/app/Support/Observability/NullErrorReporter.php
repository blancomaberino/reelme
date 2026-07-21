<?php

namespace App\Support\Observability;

use Throwable;

/**
 * No-op reporter — the default when no tracker is configured (CI, tests, and any
 * environment without a DSN). Keeps the capture choke points wired everywhere
 * without sending anything or touching the network (T-091).
 */
class NullErrorReporter implements ErrorReporter
{
    public function capture(Throwable $e, array $context = []): void
    {
        // Intentionally does nothing.
    }
}
