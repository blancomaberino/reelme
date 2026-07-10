# T-002 — Laravel 13 API scaffold with quality tooling

- **Phase:** M0 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-001
- **Target paths:** `apps/api/`
- **Spec refs:** [01-architecture.md#tech-stack](../01-architecture.md#tech-stack), [07-risks-decisions.md#adr](../07-risks-decisions.md#adr)

## Context
T-001 created the monorepo (separate app repo — application code does NOT live in this plans folder) with an empty `apps/api/` placeholder. This task turns it into a running Laravel 13 REST API with the project's quality gates (Pest, Pint, Larastan) green from day one, a documented local environment, and a health endpoint. It unlocks T-003 (DB + auth), T-007 (Horizon), T-008 (Filament), T-009 (storage) and the CI api job in T-006.

## Implementation steps
1. Scaffold Laravel — resolve latest stable versions at install time (target Laravel 13.x on PHP 8.4+; pin whatever `composer create-project` resolves):
   ```bash
   cd apps && composer create-project laravel/laravel api-tmp \
     && rsync -a api-tmp/ api/ && rm -rf api-tmp
   ```
   (rsync-over-placeholder because `apps/api` already contains a README; keep/merge the README.)
2. Install quality tooling, resolving latest stable at install time:
   - `composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan`
   - Pint ships with Laravel; add `pint.json` if deviating from the `laravel` preset (don't — use defaults).
   - `vendor/bin/pest --init` if the scaffold used PHPUnit; convert `tests/` to Pest style.
3. `phpstan.neon` at `apps/api/`:
   ```neon
   includes:
     - vendor/larastan/larastan/extension.neon
   parameters:
     level: 6
     paths: [app, database, routes]
   ```
4. Composer scripts in `apps/api/composer.json`: `"test": "pest"`, `"lint": "pint --test"`, `"stan": "phpstan analyse --memory-limit=1G"`.
5. Local environment — Sail is the reference environment (per 01-architecture §7); Herd allowed as developer's choice:
   - `php artisan sail:install` selecting **pgsql, redis, meilisearch, mailpit**.
   - Edit `docker-compose.yml`: swap the pgsql service image for `postgis/postgis:16-3.4` (or latest 16.x-postgis tag) so T-003 can enable PostGIS. Keep env vars identical to Sail's pgsql defaults.
   - Document in `apps/api/README.md`: Sail boot (`./vendor/bin/sail up -d`), Herd alternative (needs local Postgres+PostGIS, Redis), and `OLLAMA_URL=http://host.docker.internal:11434` note for later phases.
6. `.env.example`: set `DB_CONNECTION=pgsql`, `DB_HOST=pgsql`, `DB_DATABASE=reelmap`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=cookie`, and add commented placeholders with one-line docs for keys used in later M0/M1 tasks: `OLLAMA_URL`, `OPENROUTER_API_KEY`, `AWS_*`/R2 keys, `MEILISEARCH_HOST`. Every key must have a comment explaining it.
7. Health endpoint. In `routes/api.php` create the versioned group used by all future endpoints (controllers namespace `App\Http\Controllers\Api\V1` per 03-api-design §5):
   ```php
   Route::prefix('v1')->group(function () {
       Route::get('/health', HealthController::class);
   });
   ```
   `App\Http\Controllers\Api\V1\HealthController` (invokable) returns `{"data":{"status":"ok"},"meta":{}}` — matching the API envelope convention from 03-api-design §1. Include a DB connectivity check (`DB::select('select 1')`) reported as `"db": true|false` without failing the 200 when DB is down (degraded flag).
8. Configure the standard error envelope early: in `bootstrap/app.php` exception handling, render JSON errors for `api/*` requests as `{"error":{"code","message","details","request_id"}}` with codes `validation_failed` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `server_error` (500) per 03-api-design §1. (Small investment now; every later task relies on it.)
9. Pest feature test hitting `GET /api/v1/health` asserting 200 + envelope shape; a test asserting 404s return the error envelope.
10. Run all gates, fix anything red, commit.

## Acceptance criteria
- [ ] `composer.json` pins Laravel 13.x and requires PHP `^8.4` (exact versions resolved at install time, committed in `composer.lock`).
- [ ] Pest installed and `composer test` passes on the fresh scaffold (includes the health-endpoint feature test).
- [ ] `vendor/bin/pint --test` passes (no pending fixes).
- [ ] `vendor/bin/phpstan analyse` passes at level ≥6 with Larastan.
- [ ] Local env boots via Sail (reference: docker-compose with postgis-capable Postgres 16, Redis, Meilisearch, Mailpit) or Herd; both documented in `apps/api/README.md`.
- [ ] `.env.example` exists with every required key documented via inline comments.
- [ ] `GET /api/v1/health` returns HTTP 200 with `{"data":{"status":"ok", ...},"meta":{}}`.
- [ ] Non-2xx API responses use the 03-api-design error envelope with stable `code` values.

## Verification
```bash
cd apps/api
composer install
composer lint && composer stan && composer test        # all green
./vendor/bin/sail up -d && sleep 10
curl -s http://localhost/api/v1/health | python3 -m json.tool   # {"data":{"status":"ok",...},"meta":{}}
curl -s -H "Accept: application/json" http://localhost/api/v1/nope | grep '"code": *"not_found"'
```

## Gotchas
- The stock Sail `pgsql` image has no PostGIS — you MUST swap to a `postgis/postgis` image now or T-003's `CREATE EXTENSION postgis` fails.
- Laravel 13 scaffolds may not include `routes/api.php` by default — run `php artisan install:api` if absent (this also pulls in Sanctum, which T-003 needs anyway).
- Pest and PHPUnit test styles conflict; convert the two scaffold tests to Pest immediately rather than mixing.
- Larastan level >6 on a fresh scaffold generates noise in `database/factories`; start at 6, don't burn time chasing level 9.
- Always request JSON (`Accept: application/json`) in tests, otherwise Laravel renders HTML error pages and envelope assertions fail.
- Do not create any `/api/v1/admin/*` routes now or ever — admin is Filament-only per 03-api-design §2.16.

## Log
- **2026-07-09** — Done. Laravel **13.19** resolved on PHP `^8.4` (composer.json bumped from the scaffold's `^8.3`, lock refreshed). `install:api` added `routes/api.php` + Sanctum. All three gates green: `composer lint` (Pint, 32 files), `composer stan` (PHPStan level 6, Larastan, no errors), `composer test` (Pest, 3 passing). `GET /api/v1/health` → `{"data":{"status":"ok","db":true},"meta":{}}` verified over HTTP; `GET /api/v1/nope` → `not_found` error envelope, HTTP 404. Sail stack booted (postgis/postgis:16-3.4, redis:alpine, meilisearch, mailpit); PostGIS 3.4 extension confirmed installable.
- **Environment**: local PHP is 8.2 (MAMP), so all PHP/Composer/artisan/Pest ran inside the `laravelsail/php84-composer` Docker container (user chose Docker/Sail). A reusable wrapper lives in the session scratchpad (`api.sh`).
- **Deviations from the brief**:
  - `pestphp/pest-plugin-laravel` is **not yet Laravel-13-compatible** (latest requires `^11.45|^12.25`), so it was omitted. Pest core v4 + Laravel's own `Tests\TestCase` provide the HTTP/DB test helpers; no functionality lost. Revisit when the plugin ships L13 support.
  - Newer Sail publishes **`compose.yaml`** (not `docker-compose.yml`); the PostGIS image swap was applied there. Sail runtime resolved to the PHP **8.5** image (satisfies `^8.4`).
  - App exposed on **`APP_PORT=8080`** locally because host port 80 is held by MAMP Apache (documented in README).
  - Error envelope implemented as a dedicated `App\Exceptions\ApiExceptionRenderer` (registered from `bootstrap/app.php`) rather than inline, for testability.
