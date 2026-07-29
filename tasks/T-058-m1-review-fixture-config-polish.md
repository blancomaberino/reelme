# T-058 — M1 review follow-up: fixture & config polish

- **Phase:** M1 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-016
- **Target paths:** `apps/api/database/factories/`, `apps/api/database/migrations/`, `apps/api/config/horizon.php`, `apps/api/app/Console/Commands/MakeAdmin.php`
- **Spec refs:** [02-data-model.md](../02-data-model.md)

## Context

Deferred cosmetic/consistency items from the PR #10 review. No behavioural risk;
batch them whenever the M1 area is next touched.

## Items

1. **Redundant `media_assets.sha256` index** — the `unique(['sha256','source_post_id'])`
   composite already indexes `sha256` as its leftmost column, so the separate
   `$table->index('sha256')` is redundant (Postgres uses the composite for sha256-only
   lookups). Drop it in a follow-up migration (or amend if unshipped). Write cost only.
2. **`MediaAssetFactory` default `disk => 's3'`** diverges from the resolved media disk
   (`config('media.disk')` = `local_media` in dev/test). Harmless while the pipeline
   always sets `disk` explicitly, but an inconsistent fixture default. Prefer
   `config('media.disk')`.
3. **`AnalysisRunFactory::openrouter()`** sets `cost_usd` while leaving `status = Queued`
   and no timestamps — a "queued run that already cost money" when used standalone. Set a
   coherent status in the state, or document that it must be composed (`->openrouter()->succeeded()`).
4. **Horizon `LongWaitDetected` only covers `redis:default`** (`config/horizon.php`
   `waits`). The latency-sensitive `media`/`transcribe` (900s) and `analyze`/`resolve`/
   `publish` (600s) supervisors have no wait alerting. Add thresholds for those queues.
5. **`MakeAdmin` + soft-deleted users** — `User::where('email', ...)->first()` is under the
   `SoftDeletes` scope, so a banned user reads as "No user found". Decide: refuse with an
   explicit "user is banned" message (`withTrashed()`), or keep restore-then-promote. Make
   it a deliberate, documented choice.

## Acceptance

- Items 1–4 applied; item 5 resolved with an explicit, documented decision.
- Gates stay green (Pint, PHPStan, Pest).

## Log

**✅ DONE — merged to main 2026-07-28 (squash `e059ef2`, PR #144).** All five items shipped as one API-only commit; no behavioral risk.

1. Redundant `media_assets.sha256` index dropped in a follow-up migration (`2026_07_28_000000_drop_redundant_sha256_index_on_media_assets`) — the composite `unique(sha256, source_post_id)` already covers sha256-leftmost lookups. Applied to dev DB (plain `migrate`); verified the single index is gone and the composite unique remains.
2. `MediaAssetFactory` disk default → `config('media.disk')` (resolves to `local_media` in dev/test) instead of the prod-only `'s3'` literal.
3. `AnalysisRunFactory::openrouter()` now sets a coherent `Succeeded` status + timestamps (was a "queued run that already cost money" when used standalone). Documented that composing with `->failed()` models a failed-but-billed run. Only standalone caller (`PipelineResourcesTest`) stays green.
4. Horizon `LongWaitDetected` thresholds added for the pipeline supervisors — `redis:media`/`redis:transcribe` 900s, `redis:analyze`/`redis:resolve`/`redis:publish` 600s (matching the supervisor timeouts); previously only `redis:default` had wait alerting.
5. **DECISION (item 5):** `MakeAdmin` uses `withTrashed()` to find the user and **refuses a soft-deleted (banned) user** with an explicit `"is banned (soft-deleted); restore the user before promoting."` message + exit 1 — chosen over silent restore-then-promote, since promoting a banned account is never intended. +1 test (`MakeAdminCommandTest`).

**Gates:** Pint 544 · PHPStan L6 clean · Pest **962 passed** (1 new). `/coderabbit`: grounding clean, `/security-review` no findings, `/simplify` code already clean. Manually verified on dev (banned-user refusal, index gone, `config('horizon.waits')`).

**Milestone:** M1 phase is now COMPLETE — zero pending M1 tasks remaining.
