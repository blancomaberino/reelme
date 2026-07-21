# T-090 — ARCH/P1: mobile top-level ErrorBoundary + crash reporting

- **Phase:** ARCH (P1 reliability) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-004 (expo scaffold)
- **Target paths:** `apps/mobile/app/_layout.tsx`, `apps/mobile/src/components/`,
  `apps/mobile/app.config.ts`, `apps/mobile/package.json`
- **UI task → use the `/frontend-design` skill.** Resolve lib version via
  `library-version-resolver`.

## Context (audit finding, 2026-07-21)

No `ErrorBoundary`/`componentDidCatch`/`getDerivedStateFromError` anywhere in `app/` or `src/`,
and no crash reporter (no Sentry/Bugsnag/Crashlytics in `package.json`/`app.config.ts`). React
Query handles *data* errors per-screen, but a thrown **render** error (bad prop shape, a `null`
deref in one of the many inline `.map()`s) has nothing to catch it — the app unmounts to a red
box (dev) or blank screen (prod), invisible to the team.

## Implementation

- Add a class `ErrorBoundary` around the root `<Stack>` in `_layout.tsx` with a branded
  "Something went wrong / Restart" fallback (MERCADO styling).
- Wire a crash reporter (Sentry RN or equivalent), config-gated by env (no-op without DSN,
  CI-safe), so boundary catches + native crashes are visible post-release.

## Acceptance criteria

- [ ] Root `<Stack>` wrapped in an ErrorBoundary with a branded fallback
- [ ] Crash reporter wired + env-gated; no network in tests/CI
- [ ] Test: a throwing child renders the fallback (not blank) and reports once; reset recovers
- [ ] Gates: mobile `eslint` + `tsc` + `jest` green

## Log

- **2026-07-21** — Filed from the architecture audit.
