# T-003 — Postgres + PostGIS + base users migration + Sanctum auth API

- **Phase:** M0 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-002
- **Target paths:** `apps/api/database/migrations/`, `apps/api/app/Http/Controllers/Auth/`, `apps/api/routes/api.php`
- **Spec refs:** [02-data-model.md#users](../02-data-model.md#users), [03-api-design.md#auth](../03-api-design.md#auth)

## Context
T-002 delivered a Laravel 13 scaffold running against a postgis-capable Postgres 16 (application code lives in the separate app repo, not this plans folder). This task lays the database foundation (extensions + `users` per the canonical data model) and the Sanctum token auth API the mobile app (T-010) will consume. It unlocks T-008 (Filament, needs `is_admin`), T-010 (mobile auth), and all M1 migrations (T-011).

## Implementation steps
1. Ensure Sanctum is installed (`php artisan install:api` from T-002 pulls it; else `composer require laravel/sanctum` + publish migration). Add `Laravel\Sanctum\HasApiTokens` to the `User` model.
2. **Extensions migration** — must be the chronologically FIRST migration (rename timestamp below the framework ones if needed), per 02-data-model §3.8/§6:
   ```php
   DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
   DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
   DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
   DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
   ```
3. **Users migration** — replace the stock `create_users_table` content with the 02-data-model §3.1 spec exactly:
   - `id` bigint identity; `name` varchar(120); `username` citext unique; `email` citext unique; `email_verified_at`; `password` varchar(255) **nullable** (social-only signups); `avatar_path` varchar(2048) nullable; `bio` text nullable; `is_influencer`/`is_restaurant_owner`/`is_admin` boolean default false; `preferred_analysis_model` varchar(120) nullable; `stripe_connect_account_id` varchar(255) nullable; `stripe_connect_onboarded_at` nullable; `is_public` boolean default true; `remember_token`; timestamps (`timestamptz`); `deleted_at` (soft deletes).
   - Indexes: unique(username), unique(email), partial unique(stripe_connect_account_id) where not null (`DB::statement` for the partial index), index(is_admin).
   - citext columns via `DB::statement` or `$table->addColumn('citext', ...)`—simplest: create as string then `ALTER TABLE users ALTER COLUMN username TYPE citext`.
   - Keep framework tables (`cache`, `jobs`, `sessions`, `password_reset_tokens`, `personal_access_tokens`) at Laravel defaults.
4. Update `User` model: `SoftDeletes`, `HasApiTokens`, casts (`email_verified_at`, `stripe_connect_onboarded_at` datetime; role booleans), `$fillable`/`$hidden` (`password`, `remember_token`), and the `UserFactory` (unique username + email, `is_admin` state).
5. **Auth controllers** under `App\Http\Controllers\Api\V1\Auth` (satisfies the target path `app/Http/Controllers/` while following 03-api-design §5 namespacing), with FormRequests:
   - `POST /api/v1/auth/register` — `{name, username, email, password, device_name}` → creates user, returns `{"data":{"token": "...", "user": {...}}}` (201). Token via `$user->createToken($deviceName)->plainTextToken`.
   - `POST /api/v1/auth/login` — `{email, password, device_name}` → verifies credentials, issues one token per device (delete existing token with same `device_name` first), returns token + user (200). Invalid credentials → 422 `validation_failed` envelope.
   - `POST /api/v1/auth/logout` — auth:sanctum; revokes **current** token (`$request->user()->currentAccessToken()->delete()`), returns `{"data":{"ok":true}}`.
   - `POST /api/v1/auth/refresh` — auth:sanctum; issues a new token, revokes the old (rotation per 03-api-design §2.1).
   - `POST /api/v1/auth/forgot-password` / `POST /api/v1/auth/reset-password` — standard Laravel password broker (Mailpit locally).
   - `POST /api/v1/auth/social` — route registered; may return 501 `{"error":{"code":"not_implemented"}}` until Apple/Google credentials exist; leave a TODO referencing 03-api-design §2.1.
   - `GET /api/v1/me` — auth:sanctum; returns the current user via a `UserResource` (no `password`, no stripe internals beyond onboarded flag).
6. Rate-limit auth endpoints: `RateLimiter::for('auth', fn ($r) => Limit::perMinute(5)->by($r->ip()))` and apply `throttle:auth` to the `/auth/*` group (03-api-design §1: 5/min per IP). Ensure 429 carries the error envelope + `Retry-After`.
7. Configure Pest to run against Postgres (phpunit.xml: `DB_CONNECTION=pgsql`, dedicated `reelmap_test` database; `RefreshDatabase`). PostGIS columns arrive later, but tests must exercise the citext/partial-index migrations — do NOT switch tests to sqlite.
8. **Pest feature tests**: register happy path (201, token works on `/me`); register validation failures (duplicate email, duplicate username case-insensitively — citext check, weak password) → 422 envelope; login happy + wrong password; logout revokes token (subsequent `/me` → 401 envelope); refresh rotates (old token dead, new works); `/me` unauthenticated → 401; throttle → 429 after 5 attempts.

## Acceptance criteria
- [ ] First migration enables `postgis`, `pg_trgm`, `unaccent`, `citext`; `SELECT postgis_version()` succeeds after `migrate:fresh`.
- [ ] `users` table matches 02-data-model §3.1 column-for-column (names, types, nullability, defaults), including citext `username`/`email`, role boolean flags, `preferred_analysis_model`, stripe columns, `is_public`, soft deletes.
- [ ] Declared indexes exist: unique(username), unique(email), partial unique(stripe_connect_account_id), index(is_admin).
- [ ] `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/me` work end-to-end with Sanctum bearer tokens (one token per `device_name`).
- [ ] `POST /api/v1/auth/refresh`, `forgot-password`, `reset-password` implemented; `auth/social` route present (may be a documented 501 stub).
- [ ] All error responses use the standard envelope with `validation_failed`/`unauthenticated` codes; auth routes throttled at 5/min per IP.
- [ ] Pest feature tests cover happy AND failure paths for register/login/logout/me and pass against Postgres.

## Verification
```bash
cd apps/api
./vendor/bin/sail up -d
php artisan migrate:fresh
php artisan tinker --execute="echo DB::selectOne('select postgis_version() as v')->v;"
composer test && composer lint && composer stan

# manual smoke
curl -s -X POST http://localhost/api/v1/auth/register -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"name":"Maya","username":"maya","email":"maya@example.com","password":"secret123!","device_name":"cli"}'
# → 201 with data.token; then:
curl -s http://localhost/api/v1/me -H "Authorization: Bearer <token>" -H 'Accept: application/json'   # → data.user
```

## Gotchas
- `CREATE EXTENSION` requires superuser on some managed Postgres; the Sail postgis image grants it — in CI use the `postgis/postgis` service image (T-006), never plain `postgres`.
- Test database must also run the extensions migration — that's why it must be first; `RefreshDatabase` + a sqlite fallback would silently skip citext/PostGIS and hide bugs.
- Sanctum tokens vs cookie/SPA mode: this is a pure **bearer token** API (NFR-7, no cookies). Do not add `EnsureFrontendRequestsAreStateful`/`statefulApi()` middleware; do not configure `SANCTUM_STATEFUL_DOMAINS`.
- Laravel's default `users` migration has `name`/`email` only — replace it wholesale; don't stack alter-migrations at M0.
- citext uniqueness makes `MAYA@example.com` collide with `maya@example.com` — write the duplicate tests case-insensitively and normalize input in FormRequests anyway.
- `deleted_at` soft delete: login must reject soft-deleted users (default Eloquent scope handles it, but add a test).
- IDs are exposed as string ULIDs in JSON per 03-api-design §1 conventions; at minimum cast ids to string in `UserResource` now so the mobile client (T-010) types don't churn later (full ULID prefixing like `usr_…` can land with the contracts work).

## Log
- **2026-07-09** — Done. All gates green in the Sail container (against Postgres): Pest **16 passing / 50 assertions**, Pint (46 files), PHPStan level 6. `migrate:fresh` runs extensions migration first (`0000_01_01_000000_enable_postgres_extensions`), `postgis_version()` = 3.4. `users` verified column-for-column: citext `username`/`email` (udt_name=citext), 4 indexes incl. partial `users_stripe_connect_account_id_unique WHERE ... IS NOT NULL`, soft deletes. Manual smoke (register → 201 + token → `/me`) passed over HTTP on :8080.
- **Implementation notes / deviations**:
  - Tests run **inside the Sail `laravel.test` container** (`docker compose exec -T laravel.test composer test/lint/stan`) because the standalone php84 image isn't on the compose network and can't reach `pgsql`. phpunit.xml points at the Sail `testing` database.
  - Auth split into invokable controllers under `App\Http\Controllers\Api\V1\Auth` (Register/Login/Logout/Refresh/Social) + `PasswordResetController` (forgot/reset) + `Api\V1\MeController`. FormRequests normalize email/username (lowercase/trim) so citext uniqueness is predictable.
  - Role flags + stripe columns are **not** mass-assignable; `RegisterController` calls `$user->refresh()` after create so DB defaults (is_public=true, role flags=false) appear in the response instead of null.
  - **Guard-caching test artifact**: within one test the `sanctum` guard caches the resolved user across sub-requests, so a token revoked mid-test still authenticated. Verified via token count (1→0 confirms real deletion); tests call `$this->app['auth']->forgetGuards()` after logout/refresh to simulate a fresh request. Production is correct (fresh app per request).
  - Laravel 13 uses PHP attributes `#[Fillable]`/`#[Hidden]` (not `$fillable`/`$hidden` properties) — followed the scaffold's style. `@property Carbon` annotations added so datetime casts type correctly under Larastan.
  - No stateful Sanctum config added (pure bearer per NFR-7), as the brief warns.
