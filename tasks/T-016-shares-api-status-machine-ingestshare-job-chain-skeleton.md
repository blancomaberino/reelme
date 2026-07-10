# T-016 — Shares API + status machine + IngestShare job chain skeleton

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-011, T-012, T-007
- **Target paths:** `apps/api/app/Http/Controllers/ShareController.php`, `apps/api/app/Jobs/`
- **Spec refs:** [03-api-design.md#shares](../03-api-design.md), [04-analysis-pipeline.md#overview](../04-analysis-pipeline.md)

## Context

Tables/models exist (T-011), the adapter contract + registry exist (T-012), and Horizon queues `ingest`, `media`, `analysis`, `notifications` are configured (T-007). This task builds the spine everything else hangs on: the `/api/v1/shares` REST surface, the `ShareStatus` state machine, and the `Bus::chain` pipeline skeleton (`IngestShare` → `FetchSourcePost` → stubs for later stages). T-017/T-018/T-021 replace the stubs; T-025/T-026 consume the API from mobile. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

**Queue mapping** (canonical for the repo): the pipeline spec's fine-grained queue names collapse onto T-007's four Horizon queues — `ingest`/`fetch` → **ingest**; `media`/`transcribe` → **media**; `analyze`/`resolve`/`publish` → **analysis**; pushes → **notifications**.

## Implementation steps

1. **State machine** on the `Share` model (using `App\Enums\ShareStatus` from T-011). Transitions exactly per 02 §3.5:
   ```php
   // ShareStatus::transitions(): pending → fetching → analyzing → (review|published|failed)
   // review → (published|rejected); any non-terminal → failed (with failure_reason)
   public function canTransitionTo(ShareStatus $to): bool { ... }
   ```
   `Share::transitionTo(ShareStatus $to, ?string $failureReason = null)` — throws `InvalidShareTransition` on illegal moves, persists atomically (`UPDATE ... WHERE status = :expected` optimistic guard), fires a `ShareStatusChanged` event (03 §4.2 — polling + later push notifications hang off it). Terminal states: `published`, `rejected`; `failed` is terminal-but-retryable.
2. **Stage progress storage**: migration `create_share_stage_metrics_table` per 04 §8 — `share_id` FK CASCADE, `stage varchar(32)`, `status varchar(16)`, `started_at`, `duration_ms integer`, `attempt smallint`, index(`share_id`,`stage`). A `RecordsStageMetrics` job trait writes a row per stage execution; `GET /shares/{id}`'s `status_history` (03 §3.2) is derived from these rows + `shares.created_at`.
3. **Jobs** (`apps/api/app/Jobs/`), all implementing the 04 §1 stage contracts (idempotency check first, status guard second, `failed()` hook sets `failed` + `failure_reason`):
   - `IngestShare` (queue `ingest`, tries 3, backoff `[5, 30, 120]`, timeout 30): expand shortlinks (follow redirects for `vm.tiktok.com`, `youtu.be`, `t.co`), strip tracking params (`igsh`, `utm_*`, `si`, `feature`), resolve platform via `AdapterRegistry::platformFor()`, `firstOrCreate` the `source_posts` row on (`platform`,`external_id`), transition share → `fetching`, then `Bus::chain([...rest])->dispatch()`. Horizon tags: `share:{id}`, `user:{id}`, `platform:{x}`, `stage:ingest`.
   - `FetchSourcePost` (queue `ingest`, tries 4, backoff `[30, 120, 600, 1800]`, timeout 120): walk `AdapterRegistry::resolve($url)`; first successful `fetchMetadata()` wins → persist caption/author/`posted_at`/`oembed_json`, `firstOrCreate` the `Influencer` on (`platform`,`handle`), set `fetch_status: fetched`. Catches: `FetchFailed` → advance chain / `release($retryAfter)`; `PostUnavailable` → advance; `NeedsManualFallback` → share → `review` (manual-fallback prompt; the chain stops — resubmission re-dispatches from DownloadMedia per 04 §2 ManualUpload notes). Apply Laravel `RateLimited` job middleware per platform (e.g. `instagram: 30/min` app-wide).
   - **Stubs** completing the chain shape (each: correct queue per the mapping above, status guard, stage-metric row, then pass-through no-op with a `// T-0NN` marker): `DownloadMedia` (ingest→T-017), `PrepareMedia` (media→T-017), `TranscribeAudio` (media→T-018), `ExtractPlaceData` (analysis→T-021; stub transitions `fetching → analyzing`), `ResolvePlace`/`PublishShare` (analysis→T-023/T-024; stub leaves share in `analyzing`).
4. **Controller** `App\Http\Controllers\ShareController` + `ShareResource` (envelope `{"data":..., "meta":...}` per 03 §1):
   - `POST /shares` (auth, rate-limit 10/min + 100/day per 03 §1): validate `{url?: url, shared_text?: string, source_hint?: string}` — URL extracted from `shared_text` if `url` absent; neither → manual share (`shared_via: manual`). **Duplicate guard**: canonicalize inline (same helper IngestShare uses); if this user already has a share for the canonical URL's source_post, return the existing share with `meta.idempotent_replay: true` (no new row, no new chain). Honor the `Idempotency-Key` header. Otherwise create share (`pending`, `shared_via` from payload), dispatch `IngestShare`, return **202** with `meta.poll_interval_ms: 2000` (03 §3.1 shape).
   - `GET /shares` (auth, owner-scoped, cursor pagination, `?status=` filter).
   - `GET /shares/{id}` (auth + `SharePolicy` owner): status, `status_history`, `source_post` summary, latest `analysis_runs` summary (`run_id`, `model`, `status`, `confidence`, extraction excerpt), `failure` object (`{code, step, message, manual_fallback}`) when failed — shape per 03 §3.2.
   - `POST /shares/{id}/retry` (auth owner): only from `failed` (or `review` with `fetch_failed`); determines the failed stage from the last `share_stage_metrics` row and re-dispatches the chain from that stage; resets status to the stage's entry status. Illegal state → 409 `conflict`.
   - `DELETE /shares/{id}` (auth owner): discard unpublished share (`rejected`); published → 409.
5. **Tests** (Pest, `tests/Feature/Shares/`): POST creates pending share + `Bus::fake` asserts `IngestShare` dispatched with chain; duplicate URL same user returns existing share id + `idempotent_replay`; state machine unit tests — every legal transition passes, every illegal one throws (matrix test); `FetchSourcePost` with a fake adapter (bind a `FakeAdapter` into the registry config) persists metadata and advances; `NeedsManualFallback` from all adapters lands share in `review`; retry from `failed` re-dispatches correct stage; policy: other users get 403/404; rate-limit headers present per 03 §5.

## Acceptance criteria

- [ ] `POST /shares` accepts url or manual payload, creates a `pending` share, dispatches the `Bus::chain` pipeline, and returns 202 in the 03 §3.1 envelope.
- [ ] `GET /shares/{id}` returns status + stage progress (`status_history` derived from `share_stage_metrics`) + latest analysis_run summary per 03 §3.2; owner-only.
- [ ] `POST /shares/{id}/retry` re-runs from the failed stage only; invalid states get 409 `conflict`.
- [ ] Status transitions enforced by the state machine per 02 §3.5; invalid transitions raise `InvalidShareTransition` and are covered by a full transition-matrix test.
- [ ] Duplicate canonical URL by the same user returns the existing share (no second row — unique(`user_id`,`source_post_id`) never violated) with `meta.idempotent_replay: true`.
- [ ] All jobs follow the stage contract: idempotent re-entry, status guard, `failed()` sets `failed` + `failure_reason`, Horizon tags `share:{id}`/`user:{id}`/`platform:{}`/`stage:{}`, queues per the ingest/media/analysis/notifications mapping.
- [ ] `NeedsManualFallback` parks the share in `review` (manual prompt), not `failed`.
- [ ] `pint --test` and `phpstan analyse` pass; suite green without network.

## Verification

```bash
cd apps/api
php artisan test --filter=Shares
php artisan test --filter=ShareStatusMachine
# manual smoke (Horizon running: php artisan horizon):
TOKEN=... # from /auth/login
curl -s -X POST localhost/api/v1/shares -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"url":"https://www.instagram.com/reel/DAbC123xyz/"}' | jq '.data.status, .meta.poll_interval_ms'
# expect: "pending", 2000; repeat the same curl → same id, .meta.idempotent_replay == true
curl -s localhost/api/v1/shares/1 -H "Authorization: Bearer $TOKEN" | jq '.data.status_history'
php artisan horizon:status   # queues ingest/media/analysis/notifications active
```

## Gotchas

- **ID representation**: 03's examples show prefixed ULIDs (`shr_01J...`) while 02 (canonical DB spec) mandates bigint identity PKs. Follow 02: bigint PKs, serialize `id` as a string in resources. Do not invent a `public_id` column here; if prefixed IDs are wanted later it's a resource-layer concern. Flag this divergence in the PR description.
- **Idempotent jobs are non-negotiable**: Horizon can double-deliver. Every stage checks "is my output already there?" (e.g. FetchSourcePost: `fetch_status === fetched` → return) before working, and status guards must exit *silently* (04 §1), not throw.
- `Bus::chain` runs each job on **its own** queue only if each job sets `onQueue()` — the chain does not inherit. Set queue in each job's constructor.
- The optimistic `UPDATE ... WHERE status = ?` guard prevents two workers double-transitioning; check affected-rows and treat 0 as "someone else moved it" → exit silently.
- Shortlink expansion in IngestShare does live HTTP (HEAD/GET redirects) — in tests, `Http::fake` the redirect; in the canonicalizer, cap redirects (3) and timeout (5 s).
- Retry must NOT re-run `IngestShare` for a share whose source_post is already fetched — resume from the recorded failed stage; keep a `stage → job class` map in one place (`App\Jobs\Pipeline::STAGES`) used by both chain assembly and retry.
- `shares.failure_reason` (02) stores the machine code from the 04 §8 taxonomy (`fetch_unavailable`, `media_too_large`, ...); the API `failure.message` is a humanized string derived from it — don't persist prose.
- Don't build push notifications here — `ShareStatusChanged` event exists, listeners arrive in T-027.

## Log
- **2026-07-09** — Done. **PR #8** (`feat/t016-shares-api` → `feat/t012-source-adapters`, stacked). All gates green: `composer test` 107 passing / 326 assertions (21 shares tests), Pint (139 files), PHPStan L6.
- **Implementation notes**:
  - State machine on `Share` (`transitionTo` with optimistic `WHERE status` guard + `ShareStatusChanged` event + `InvalidShareTransition`). `Pipeline::STAGES` single source of truth for chain + retry. `share_stage_metrics` → `status_history`.
  - **Queue mapping**: DownloadMedia=ingest, Prepare/Transcribe=media, Extract/Resolve/Publish=analysis (per brief step-3 annotations). Full sync-pipeline test ends in `analyzing` (stubs are no-ops; Extract bumps fetching→analyzing).
  - **Gotcha hit**: a job method named `queue()` collides with Laravel's Dispatcher custom-queue handler → renamed to `queueName()`. `failed()` hooks guard `canTransitionTo(Failed)` (avoid failed→failed crash).
  - **Canonicalization lives in the controller** (not IngestShare) because the duplicate guard needs the source_post synchronously; IngestShare is the async kickoff. Documented deviation from the brief's division.
  - **/security-review — Finding 1 (High, SSRF) FIXED**: shortlink expansion now follows redirects manually, validating each hop's host/IP against private/reserved ranges (Guzzle auto-redirect only gated the first allowlisted host; t.co open-redirect → internal hosts). **Finding 2 (High, latent)**: cross-user manual-payload exposure — not reachable in T-016 (no manual payload is created yet). **BLOCKING for T-024/T-025**: manual caption + `screen_recording` must be user-attributed and never served cross-user off the shared `source_post` (the T-012 caller-authorization obligation).
  - **/simplify** applied: `FailsShareOnError` trait, `Pipeline::entryStatus` as the one stage→status map, `isTerminal` via `transitions()`, controller response dedup, `sha1` no-op.
  - **Spec divergences flagged**: bigint IDs serialized as strings (no `shr_` prefix); `source_posts.platform` NOT-NULL gap for unknown-host URLs → placeholder stored, honest platform returned, **needs an ADR** (nullable/`unknown`) in T-024.
