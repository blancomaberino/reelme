# T-092 — ARCH/P1: real request_id (AssignRequestId middleware + propagation)

- **Phase:** ARCH (P1 observability) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-002 (laravel scaffold)
- **Target paths:** `apps/api/app/Http/Middleware/`, `apps/api/bootstrap/app.php`,
  `apps/api/app/Exceptions/ApiExceptionRenderer.php`, `apps/api/app/Jobs/Concerns/`

## Context (audit finding, 2026-07-21)

`ApiExceptionRenderer.php:94-98` and `PlatformAccountController.php:239,246` read
`$request->attributes->get('request_id')` expecting an upstream value — but there is **no
`app/Http/Middleware` directory** and nothing sets it (`attributes->set('request_id'` /
`X-Request-Id` grep is empty). So every error response mints a fresh ULID at render time that
can't be cross-referenced with earlier logs or the async jobs the request triggered — defeating
the purpose of correlation in a multi-stage pipeline.

## Implementation

- Add an early-stack `AssignRequestId` middleware: generate one ULID per request, set
  `$request->attributes->set('request_id', ...)`, `Log::withContext(['request_id' => ...])`,
  echo `X-Request-Id` on the response.
- Have `ApiExceptionRenderer` reuse that id instead of minting one.
- Propagate the id into pipeline jobs via the job payload so async stage logs share it.

## Acceptance criteria

- [ ] Response carries `X-Request-Id`; the error envelope `request_id` equals it
- [ ] A dispatched pipeline job logs the same id
- [ ] Gates: `composer lint` + `stan` + `test` green

## Notes

Prerequisite that makes **T-091** (Sentry) actually correlatable.

## Log

- **2026-07-21** — Filed from the architecture audit.
