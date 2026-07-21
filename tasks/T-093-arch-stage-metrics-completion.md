# T-093 — ARCH/P1: finish ShareStageMetric (completion/failure/duration) + pipeline logs

- **Phase:** ARCH (P1 observability) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-016 (shares/status machine), T-017 (download/prepare media jobs)
- **Target paths:** `apps/api/app/Jobs/Concerns/RecordsStageMetrics.php`, `apps/api/app/Jobs/`,
  `apps/api/app/Models/ShareStageMetric.php`, `apps/api/app/Http/Resources/ShareResource.php`

## Context (audit finding, 2026-07-21)

`RecordsStageMetrics::recordStage()` only ever `ShareStageMetric::create(['status' => 'running'])`
— every job calls it once and never updates the row. `duration_ms`/`attempt` are dead columns.
`ShareResource::statusHistory()` only needs first-seen timestamps so the client endpoint isn't
broken, but there's no way to answer "did stage X fail, hang, or not start" or "which stage is
slow" from data. Only 2 `Log::` calls exist across the 12 job classes.

## Implementation

- Add `completeStage()`/`failStage()` (or extend `recordStage()`) to update
  `status` + `duration_ms` + `attempt` on success and in each job's `failed()` hook.
- Add entry+exit logs to each pipeline job with `share_id` (+ `request_id` once T-092 lands).

## Acceptance criteria

- [ ] A fake pipeline run records `running→completed` with a duration; a forced failure records
      `failed` with the error — asserted on the rows
- [ ] No regression to `ShareResource` status history
- [ ] Gates: `composer lint` + `stan` + `test` green

## Notes

Overlaps **M5 T-052** (stage metrics); this builds the queryable-telemetry core now.

## Log

- **2026-07-21** — Filed from the architecture audit.
