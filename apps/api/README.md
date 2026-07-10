# apps/api — Reelmap API

Laravel 13 REST API (scaffolded in T-002). Sanctum auth, Horizon queues, Postgres + PostGIS, Meilisearch, and a Filament admin panel land in later M0 tasks.

- **PHP:** `^8.4` · **Laravel:** `^13.8`
- **API base:** `/api/v1` (versioned; controllers in `App\Http\Controllers\Api\V1`)
- **Admin:** Filament-only at `/admin` — never add `/api/v1/admin/*` routes.

## Quality gates

```bash
composer lint    # pint --test  (code style, Laravel preset)
composer stan    # phpstan analyse (Larastan, level 6)
composer test    # pest
```

All three must be green before committing. CI runs the same three (T-006).

## Local environment

### Option A — Laravel Sail (reference environment)

Sail is the canonical dev environment (Docker). Services: PostGIS-capable **Postgres 16**, **Redis**, **Meilisearch**, **Mailpit**.

```bash
cp .env.example .env
# First run pulls/builds images; the Postgres image is postgis/postgis:16-3.4
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

- API: **http://localhost** (override with `APP_PORT` in `.env` if port 80 is taken, e.g. `APP_PORT=8080`).
- Mailpit dashboard: http://localhost:8025 · Meilisearch: http://localhost:7700
- The Postgres service uses `postgis/postgis:16-3.4` so `CREATE EXTENSION postgis` works (needed from T-003).

Health check:

```bash
curl -s http://localhost/api/v1/health   # {"data":{"status":"ok","db":true},"meta":{}}
```

### Option B — Laravel Herd

Herd is allowed (developer's choice). You must provide the backing services yourself:

- **Postgres 16 with the PostGIS extension** (`brew install postgis` or Postgres.app), database `reelmap`.
- **Redis** (`brew install redis`).
- **Meilisearch** (`brew install meilisearch`) for search (T-031).

Point `.env` at your local hosts: `DB_HOST=127.0.0.1`, `REDIS_HOST=127.0.0.1`, `MEILISEARCH_HOST=http://127.0.0.1:7700`. Then `php artisan migrate`.

### Ollama (later phases)

The analysis pipeline (M1) calls a local Ollama host. Under Sail the workers reach it at
`OLLAMA_URL=http://host.docker.internal:11434`; with Herd use `http://127.0.0.1:11434`.

## Queues & Horizon

Jobs run on Redis queues supervised by [Horizon](https://laravel.com/docs/horizon). Queue names are canonical per `04-analysis-pipeline.md §1` (`ingest, fetch, media, transcribe, analyze, resolve, publish, notifications, default`) — a config test locks the set.

```bash
./vendor/bin/sail artisan horizon          # start supervisors (local)
./vendor/bin/sail artisan horizon:terminate # graceful stop (deploys call this)
```

- Dashboard: **`/horizon`** — gated to `is_admin` users in all **non-local** environments (staging/production); Horizon leaves it open in `local` by default (dev only, tunnel-blocked by Sentinel). Guests and non-admins get 403.
- Production runs Horizon as a Forge daemon, restarted on each deploy via `horizon:terminate` (01-architecture §7). The scheduler runs `horizon:snapshot` every 5 min for metrics.
- `retry_after` (960s) is deliberately greater than the longest supervisor timeout (media = 900s) so long jobs are never re-delivered mid-flight.

## Response conventions

- Success: `{"data": ..., "meta": {...}}`.
- Errors (all non-2xx): `{"error": {"code","message","details","request_id"}}` with stable `code` values (`validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `conflict`, `rate_limited`, `server_error`, …). See `app/Exceptions/ApiExceptionRenderer.php` and `03-api-design.md §1`.
