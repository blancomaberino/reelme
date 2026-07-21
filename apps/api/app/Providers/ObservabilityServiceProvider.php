<?php

namespace App\Providers;

use App\Support\Observability\ErrorReporter;
use App\Support\Observability\LogErrorReporter;
use App\Support\Observability\NullErrorReporter;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires error tracking (T-091): binds the {@see ErrorReporter} driver from env
 * and registers the queue worker's failed-job hook so EVERY failed job — the
 * whole multi-vendor pipeline — is captured with its share_id + request_id,
 * alongside the HTTP handler hook in bootstrap/app.php.
 *
 * Activating Sentry (the production driver) is a provisioning step, not a code
 * change: `composer require sentry/sentry-laravel`, add a `sentry` case below
 * that news up a small SentryErrorReporter (Sentry::captureException with the
 * context as extra), set OBSERVABILITY_ERROR_REPORTER=sentry + SENTRY_LARAVEL_DSN.
 * Until then `null` (CI/tests) and `log` (structured error logs) are the drivers.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ErrorReporter::class, function (): ErrorReporter {
            return match (config('observability.error_reporter')) {
                'log' => new LogErrorReporter,
                default => new NullErrorReporter, // 'null' + any unknown/unprovisioned driver
            };
        });
    }

    public function boot(): void
    {
        // The queue worker's failed-job hook: one capture point for every job
        // that exhausts its retries, tagged with the pipeline share it belongs to.
        Queue::failing(function (JobFailed $event): void {
            $this->app->make(ErrorReporter::class)->capture(
                $event->exception,
                $this->jobContext($event),
            );
        });
    }

    /**
     * Correlation context for a failed job: the share it was processing (pulled
     * from the serialized command's `shareId`, which every pipeline job carries)
     * and the request_id that kicked it off (rides the job's Context — T-092).
     *
     * @return array<string, mixed>
     */
    private function jobContext(JobFailed $event): array
    {
        $context = [
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'job' => $event->job->resolveName(),
            'request_id' => Context::get('request_id'),
        ];

        try {
            $payload = $event->job->payload();
            $command = $payload['data']['command'] ?? null;
            $resolved = is_string($command) ? @unserialize($command) : null;
            if (is_object($resolved) && property_exists($resolved, 'shareId')) {
                $context['share_id'] = $resolved->shareId;
            }
        } catch (Throwable) {
            // Best-effort enrichment — never let it break the capture.
        }

        return $context;
    }
}
