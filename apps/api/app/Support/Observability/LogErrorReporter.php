<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Forwards captured exceptions to a dedicated log channel with the correlation
 * context (T-091). The CI-safe "equivalent" tracker: structured error records
 * that are already correlatable by request_id/share_id (T-092/T-093), and a
 * drop-in until the Sentry driver is provisioned. Never throws.
 */
class LogErrorReporter implements ErrorReporter
{
    public function capture(Throwable $e, array $context = []): void
    {
        try {
            Log::channel(config('observability.log_channel', 'stack'))->error(
                'unhandled.exception',
                [...$context, 'exception' => $e],
            );
        } catch (Throwable) {
            // Telemetry must never crash the caller — swallow a logging failure.
        }
    }
}
