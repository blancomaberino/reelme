# T-107 — Filament: pipeline-health dashboard (stage timings, failure mix, queue depth)

- **Phase:** ARCH (audit wave 2) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-093
- **Target paths:**
  - `apps/api/app/Filament/Widgets/`
  - `apps/api/app/Providers/Filament/AdminPanelProvider.php`
  - `apps/api/tests/Feature/Filament/`

## Context (codebase audit, 2026-07-29)

Audit finding B10 (2026-07-29). Filament has 8 resources across 40 files and ZERO widgets - an operator diagnoses pipeline health by eyeballing the Shares table. T-093 finished stage-completion metrics, so the data is already on disk and unread. T-051/T-052 (M5) cover the AI-cost and Sentry slices; the pipeline-health slice is in no open task. Deliberately scoped S: read-only widgets, no alerting (that stays T-052).

## Acceptance criteria

- [ ] The admin landing page answers 'is the pipeline healthy right now?' at a glance: shares by status, failure-code mix, per-stage p50/p95 duration, queue depth/oldest job
- [ ] All figures come from data already recorded (share_stage_metrics, analysis_runs, shares.failure_reason) - no new collection
- [ ] Widgets are time-window filterable (last hour / 24h / 7d) and cheap enough to load without timing out on a large table (indexed, aggregate queries, no N+1)
- [ ] Tests assert the aggregates against seeded fixtures, including the empty-data case
- [ ] Gates green: composer lint + stan + test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
