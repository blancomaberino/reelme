# T-091 — ARCH/P1: Sentry (or equiv) on API HTTP handler + queue failed-job hook

- **Phase:** ARCH (P1 observability) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-002 (laravel scaffold), T-007 (Redis/Horizon)
- **Target paths:** `apps/api/composer.json`, `apps/api/app/Exceptions/ApiExceptionRenderer.php`,
  `apps/api/bootstrap/app.php`, `apps/api/config/`, `apps/api/.env.example`
- Resolve lib version via `library-version-resolver`.

## Context (audit finding, 2026-07-21)

No APM/error-tracking package in `composer.json`; no `SENTRY_*` in `.env.example`;
`ApiExceptionRenderer::safeServerMessage()` returns a generic `"Server error."` with nothing
forwarded anywhere. A queued multi-vendor pipeline (yt-dlp, Whisper, OpenRouter, Google Places,
Trustpilot) has many independent failure modes; without aggregation, spotting failure trends
means tailing `storage/logs` or querying `failed_jobs`.

## Implementation

- Wire Sentry (or equivalent) for the HTTP exception handler **and** the queue worker's
  failed-job hook, with `share_id` + `request_id` (T-092) context.
- Env-gated: DSN absent ⇒ no-op; CI/test never sends.

## Acceptance criteria

- [ ] Unhandled HTTP exceptions and queued-job failures are captured with share_id/request_id
- [ ] Config-gated by env; documented in `.env.example`; CI-safe
- [ ] Test: a thrown exception in a fake pipeline job reaches the fake tracker transport with
      the expected context
- [ ] Gates: `composer lint` + `stan` + `test` green

## Notes

Pulls the architecture-critical slice of **M5 T-052** forward; T-052 remains for the full
alerting/Horizon-dashboard buildout.

## Log

- **2026-07-21** — Filed from the architecture audit.
