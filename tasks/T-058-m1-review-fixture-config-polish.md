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
