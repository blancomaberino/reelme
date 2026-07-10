# T-052 — Observability: Sentry, Horizon alerts, stage metrics

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-028
- **Target paths:** `apps/api/`, `apps/mobile/`
- **Spec refs:** [04-analysis-pipeline.md#observability](../04-analysis-pipeline.md#8-observability)

## Context

NFR-14 requires structured logs, error tracking, and pipeline metrics with alerting before launch; the full pipeline exists and is integration-tested (T-028), so instrumentation points are stable. This task wires Sentry into both apps, persists per-stage timing metrics, adds Horizon failure alerting, and writes the failure-taxonomy runbook. T-055 (production deploy) depends on it. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Sentry — API**: install `sentry/sentry-laravel` (resolve latest stable), publish `config/sentry.php`, `SENTRY_LARAVEL_DSN` per environment (staging/prod DSNs configured in T-055). Enable performance tracing at a low sample rate (`traces_sample_rate: 0.1`), queue job breadcrumbs, and set `release` to the git SHA (exposed as env by the deploy script). Scrub PII: `send_default_pii: false`, strip `Authorization` headers and token fields.
2. **Sentry — mobile**: install `@sentry/react-native` (the current Expo-supported package — `sentry-expo` is deprecated and wraps the same SDK) with its config plugin in `app.config.ts`; wrap the root layout, set `release`/`dist` from EAS build values (git SHA / build number per `01-architecture.md §7`), enable native crash handling; source maps uploaded via the Sentry Expo plugin on EAS builds and on `eas update`. DSN via `EXPO_PUBLIC_SENTRY_DSN` per profile in `eas.json`.
3. **Stage metrics table** (`04-analysis-pipeline.md §8`): migration `share_stage_metrics` (`share_id`, `stage`, `started_at`, `duration_ms`, `attempt`; index on `(stage, started_at)`). Record from a shared job concern (`MeasuresStage` trait or middleware on the pipeline jobs: IngestShare, FetchSourcePost, DownloadMedia, PrepareMedia, TranscribeAudio, ExtractPlaceData, ResolvePlace, PublishShare). Add a Filament widget/page: p50/p95 duration per stage over the last 24 h/7 d.
4. **Structured logs**: JSON log channel (Monolog `JsonFormatter`) for production; a `Context`/log processor attaches `share_id` as the correlation key on every pipeline log line, plus `request_id` on HTTP logs (matching the error-envelope `request_id`).
5. **Failure-taxonomy alerting**: scheduled command (`php artisan metrics:check-failures`, every 5 min) evaluating the §8 rule — any `shares.failure_code` (`fetch_unavailable`, `fetch_auth_required`, `media_too_large`, `ffmpeg_error`, `transcribe_error`, `ollama_unreachable`, `invalid_model_output`, `cost_cap_exceeded`, `geocode_failed`, `resolve_conflict`, `user_discarded`, `unknown`) exceeding 5% of shares over 15 min → notify admins (mail/Slack webhook via a `AdminAlert` notification). Same command checks sustained fallback rate >30% (§8 cost dashboard rule).
6. **Horizon alerts**: implement `Horizon::routeMailNotificationsTo(...)` (and Slack if webhook configured) plus long-wait thresholds in `config/horizon.php` (`waits` per queue, e.g. `redis:analyze => 60`); ensure failed jobs report to Sentry (they do once the Sentry integration is active — verify with a deliberately failing job). Horizon job tags per §8 (`share:{id}`, `user:{id}`, `platform:{x}`, `stage:{name}`, `engine:{local|openrouter}`) — audit that pipeline jobs from M1 actually implement `tags()`; add where missing.
7. **Runbook**: `docs/runbooks/pipeline-failures.md` — table mapping every `failure_code` to: what it means, first diagnostic (Horizon tag search, log query by `share_id`), remediation (e.g. `ollama_unreachable` → check `OLLAMA_URL` host / systemd service, fallback should have engaged; `ffmpeg_error` → check binary version + input via stored asset), and user impact/retry story (`POST /shares/{id}/retry`).
8. **Tests**: Pest — stage metric rows written per pipeline stage in the T-028 integration test path; failure-rate command triggers a notification with a seeded burst of failed shares (Notification::fake). Mobile: jest smoke that Sentry init doesn't crash in test env (mocked).

## Acceptance criteria

- [ ] Sentry receives API exceptions with environment + git-SHA release tagging and no PII/tokens in events.
- [ ] Sentry receives mobile JS errors and native crashes, with releases tagged per EAS build and source maps resolving symbolicated stack traces.
- [ ] Every pipeline stage writes a `share_stage_metrics` row (share_id, stage, started_at, duration_ms, attempt); p50/p95 per stage visible on the ops (Filament) dashboard.
- [ ] All pipeline logs are structured JSON carrying `share_id` as correlation id; a single share is traceable end-to-end (NFR-14).
- [ ] Failure-code alerting fires when any code exceeds 5% of shares over 15 min; fallback-rate >30% sustained also alerts.
- [ ] Horizon notifies on long queue waits and failed jobs land in Sentry; pipeline jobs carry the §8 tags searchable in the Horizon UI.
- [ ] `docs/runbooks/pipeline-failures.md` maps the complete failure taxonomy to remediation steps.

## Verification

```bash
cd apps/api
php artisan test --filter=StageMetrics
php artisan test --filter=FailureAlert
php artisan sentry:test                  # sends a test event; confirm in Sentry UI
# Trace one share end-to-end:
php artisan tinker --execute="/* dispatch a fake-adapter share as in T-028 test setup */"
grep '"share_id":' storage/logs/laravel.log | head   # JSON lines across stages
```

Manual: run the T-028 integration flow locally, open Filament stage-metrics widget — 8 stages with durations. Stop Ollama (`OLLAMA_URL` unreachable), push a share through: confirm an `analysis_runs` fallback pair is recorded, the `ollama_unreachable`→fallback path logs with the share_id, and (with thresholds lowered) the alert notification fires. Mobile: `npx expo start --dev-client`, throw a test error behind a hidden dev menu button, confirm the event in Sentry with the right release.

## Gotchas

- **`sentry-expo` is deprecated** — use `@sentry/react-native` (matches `01-architecture.md` §1); it has first-class Expo/EAS support including the config plugin and source-map upload. Mixing both breaks builds.
- Source maps for **EAS Update** OTA bundles need the update-aware upload step, or every OTA release reports minified frames.
- Sentry sampling: 100% error capture is fine; keep `traces_sample_rate` low or the free tier drowns in map-polling transactions.
- `share_stage_metrics` grows fast — add a pruning policy (e.g. keep 90 days, extend the T-050 housekeeping schedule).
- The 5%-failure alert needs a minimum-volume floor (e.g. ≥20 shares in the window) or one failure at 3 a.m. pages you.
- Horizon wait alerts require the scheduler + Horizon snapshot cron (`horizon:snapshot` every 5 min) — coordinate with T-055 provisioning.
- Never log `result_json` payloads or captions at info level in production (PII + volume); log ids and codes, fetch payloads by id when debugging.
