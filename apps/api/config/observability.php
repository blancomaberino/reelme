<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Error reporter driver (T-091)
    |--------------------------------------------------------------------------
    |
    | Which ErrorReporter unhandled HTTP exceptions and failed queue jobs are
    | forwarded to. `null` (default) is a no-op — CI, tests, and any environment
    | without a tracker send nothing. `log` writes structured error records to
    | `log_channel`. `sentry` forwards to Sentry (requires sentry/sentry-laravel
    | + SENTRY_LARAVEL_DSN; see ObservabilityServiceProvider for activation).
    |
    */
    'error_reporter' => env('OBSERVABILITY_ERROR_REPORTER', 'null'),

    /** Log channel used by the `log` driver. */
    'log_channel' => env('OBSERVABILITY_LOG_CHANNEL', 'stack'),

    /** Sentry DSN — absent ⇒ the `sentry` driver falls back to no-op (never sends). */
    'sentry_dsn' => env('SENTRY_LARAVEL_DSN'),

];
