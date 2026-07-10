# T-055 — Production deploy: Forge provisioning, Ollama host, runbooks

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-052
- **Target paths:** `docs/runbooks/`, `apps/api/`
- **Spec refs:** [01-architecture.md#environments-deployment](../01-architecture.md#7-environments--deployment)

## Context

Everything so far runs locally/CI; M5 exit requires staging + production environments documented in runbooks, with observability (T-052) already wired so the deploy is monitorable from day one. Per `01-architecture.md §7`: a single VPS per concern provisioned by Laravel Forge (nginx, PHP 8.4, Postgres+PostGIS, Redis, Meilisearch on the same box; separate site + DB per environment initially), Ollama on the same VPS or a separate GPU host behind `OLLAMA_URL`, Cloudflare R2 buckets, EAS profiles already pointing at `https://staging.reelmap.app` / `https://api.reelmap.app`. App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Provision via Forge** (staging first, then production; record every step in `docs/runbooks/provisioning.md`):
   - Server: PHP 8.4, nginx, Postgres 16, Redis. Sites `staging.reelmap.app` and `api.reelmap.app` (separate DBs `reelmap_staging` / `reelmap_prod`), TLS via Forge/Let's Encrypt.
   - **PostGIS**: `apt install postgresql-16-postgis-3`; `CREATE EXTENSION postgis;` per database (the app migration also guards this, but the OS package must exist).
   - **Meilisearch**: install as a systemd service, master key in env, bound to localhost; set `MEILISEARCH_HOST`/`MEILISEARCH_KEY`.
   - **Binaries for the pipeline**: `ffmpeg`, `yt-dlp` (pinned version, self-updating disabled; path in config), `whisper.cpp` binary + model file (per `TRANSCRIBER_DRIVER`); verify each with a version command as a provisioning checklist item.
   - **Horizon daemon**: Forge daemon `php artisan horizon` (one per site), restarted on deploy via `horizon:terminate`.
   - **Scheduler**: Forge cron `php artisan schedule:run` every minute per site — this powers T-050 retention (`media:prune-originals`), T-052 alerting (`metrics:check-failures`, `horizon:snapshot`), and nightly cost rollups. Verify with `php artisan schedule:list`.
2. **Ollama host wiring**: install Ollama on the VPS if the chosen extraction model is CPU-viable, otherwise a separate GPU box (OQ-8 decision) reachable over private network/VPN — never a public unauthenticated port. Pull the pinned models (`OLLAMA_VISION_MODEL=qwen2.5-vl:7b`, `OLLAMA_TEXT_MODEL=qwen2.5:14b`); set `OLLAMA_URL`, `OLLAMA_TIMEOUT=180` in both site envs; systemd unit with restart policy. Confirm workers reach it: `curl $OLLAMA_URL/api/tags` from the app server.
3. **Environment config**: full `.env` per environment from `.env.example` — DB, Redis, `QUEUE_CONNECTION=redis`, R2 (`reelmap-staging`/`reelmap-prod` buckets, S3 driver keys), Google Places key, OpenRouter key, Stripe keys + webhook secret (register the webhook endpoint per environment in the Stripe dashboard), Expo push access token, `SENTRY_LARAVEL_DSN` per environment (T-052), mail driver, `AI_*` cost caps (T-051). Secrets live only in Forge env panels (NFR-8).
4. **Zero-downtime deploy script** (Forge deploy script, committed copy in `docs/runbooks/deploy.md`): `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` → `config:cache`/`route:cache`/`event:cache` → `php artisan horizon:terminate` → `php artisan scout:sync-index-settings` (if changed) → export `SENTRY_RELEASE=$(git rev-parse HEAD)`. Enable Forge "deploy on push" for staging (main branch); production deploys manual.
5. **Backups + restore runbook**: nightly `pg_dump` (Forge backup config or `spatie/laravel-backup`) to R2 with 30-day retention; Redis is disposable (document that queue loss = retryable shares); Meilisearch rebuilt via `php artisan scout:import`. Write `docs/runbooks/backup-restore.md` and **execute a restore once into a scratch DB** (M5 exit criterion "tested once"), recording the commands + timing.
6. **Staging fallback verification** (tasks.json acceptance): on staging, push a real share through the pipeline, then `systemctl stop ollama` and push another — confirm the second records an `analysis_runs` pair/fallback (`engine: openrouter`, `fallback_reason: ollama_unreachable`), the share still publishes, and the T-052 alert fires. Document as a runbook drill.
7. **Smoke checklist post-deploy** (`docs/runbooks/deploy.md`): `GET /api/v1/health` 200, Horizon dashboard green (admin-gated), a seeded share completes on staging, map bbox query < 600 ms, Sentry receives a test event, scheduler ran within the last minute.
8. **Runbook index**: `docs/runbooks/README.md` linking provisioning, deploy, backup-restore, pipeline-failures (T-052), submission runbook (T-054), plus an on-call quick card (service list, restart commands, log locations, Horizon/Filament URLs).

## Acceptance criteria

- [ ] Staging and production sites provisioned per `01-architecture.md §7`: nginx + PHP 8.4, Postgres 16 + PostGIS, Redis, Meilisearch, Horizon daemon, per-minute scheduler cron — each verifiable via the provisioning checklist.
- [ ] `ffmpeg`, `yt-dlp`, and whisper.cpp installed and version-pinned on the worker host; pipeline jobs run against them on staging.
- [ ] Ollama host reachable from workers via `OLLAMA_URL` (private network, not public); pinned vision/text models pulled; health check returns tags.
- [ ] Staged fallback verified: with Ollama stopped, a share completes via OpenRouter with the fallback recorded on `analysis_runs` and an alert emitted.
- [ ] Zero-downtime deploy script live on both environments (composer install, `migrate --force`, caches, `horizon:terminate`); staging auto-deploys from main.
- [ ] Nightly Postgres backups to R2 with retention; restore executed once successfully and documented with timings in `docs/runbooks/backup-restore.md`.
- [ ] R2 buckets `reelmap-staging`/`reelmap-prod` wired with signed-URL delivery; secrets only in Forge env (none in repo).
- [ ] `docs/runbooks/` contains provisioning, deploy + smoke checklist, backup-restore, and the on-call index; M5 exit "environments documented in runbooks" satisfied.

## Verification

```bash
# From the app server (staging):
ffmpeg -version && yt-dlp --version && ./whisper --help | head -1
curl -s $OLLAMA_URL/api/tags | jq '.models[].name'          # pinned models listed
php artisan schedule:list && php artisan horizon:status      # scheduler + horizon running
curl -s https://staging.reelmap.app/api/v1/health            # {"status":"ok"}

# Fallback drill (staging):
sudo systemctl stop ollama
# submit a share from the staging app / curl POST /api/v1/shares
php artisan tinker --execute="dump(App\Models\AnalysisRun::latest()->first()->only(['engine','model','cost_usd']))"
# expect engine=openrouter with cost > 0; restart: sudo systemctl start ollama

# Restore drill:
createdb reelmap_restore_test && pg_restore -d reelmap_restore_test <latest-dump>
psql reelmap_restore_test -c "SELECT count(*) FROM places;"  # matches source
```

Manual: trigger a deploy while polling `GET /api/v1/health` in a loop — no 5xx window; confirm Horizon workers restarted (uptime reset) and a queued job processes post-deploy.

## Gotchas

- **Forge cron is not automatic** — without the `schedule:run` cron, T-050 media retention silently never runs and the 72 h ADR-010 promise (a legal commitment) is broken. Make the scheduler check part of the post-deploy smoke, and alert if `schedule:run` hasn't executed recently (heartbeat).
- `horizon:terminate` requires the daemon to be supervised (Forge daemon does this) — without supervision it terminates and never restarts, killing the pipeline until someone notices.
- `config:cache` means runtime `env()` calls outside config files return null in production — audit for stray `env()` usage before first deploy (`grep -rn "env(" app/`).
- yt-dlp breaks frequently against platform changes — pin the version, and document the "bump yt-dlp" procedure + the per-platform kill switch (config flag from `01-architecture.md §5`) in the runbook instead of auto-updating.
- Ollama on a public interface with no auth is a free GPU for the internet — bind to localhost/private network; if a separate GPU box, use WireGuard/Tailscale or firewall rules.
- `migrate --force` on big tables can lock during deploy — for M5 the DB is small, but note in the runbook that future index changes should use `CREATE INDEX CONCURRENTLY` outside the transaction.
- Restore drill must include PostGIS: a plain `pg_restore` into a DB without the extension fails on `geography` columns — create the extension first; that's exactly why the drill is mandatory.
- Meilisearch master key and Stripe **live** keys are the two secrets most often committed by accident — double-check `.env.example` contains placeholders only.
