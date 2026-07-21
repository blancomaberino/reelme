<?php

namespace App\Support\Observability;

use Throwable;

/**
 * The single choke point through which unhandled HTTP exceptions and failed
 * queue jobs are forwarded to an error tracker (T-091), always with correlation
 * context (share_id, request_id — T-092). Bound in `ObservabilityServiceProvider`
 * to a driver chosen by env: {@see NullErrorReporter} (default — CI/tests/no-DSN),
 * {@see LogErrorReporter} (structured error logs), or a Sentry driver in prod.
 *
 * A queued multi-vendor pipeline (yt-dlp, Whisper, OpenRouter, Google Places,
 * Trustpilot) has many independent failure modes; routing them all through here
 * means spotting failure trends is one tracker query, not tailing storage/logs.
 */
interface ErrorReporter
{
    /**
     * Capture an exception with correlation context. MUST NOT throw — telemetry
     * can never be allowed to crash the request or job it exists to observe.
     *
     * @param  array<string, mixed>  $context
     */
    public function capture(Throwable $e, array $context = []): void;
}
