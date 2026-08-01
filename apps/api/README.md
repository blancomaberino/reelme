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

## Admin panel (Filament)

Ops tooling lives in a [Filament](https://filamentphp.com) panel at **`/admin`** — there is deliberately **no** `/api/v1/admin/*` REST surface (ADR-012). The panel is session-authed (web guard), separate from the API's Sanctum tokens.

- Access is gated by `User::canAccessPanel()` → **`is_admin` in every environment**. Non-admins get 403, guests are redirected to `/admin/login`.
- **Local admin**: `php artisan db:seed --class=AdminUserSeeder` creates `admin@reelmap.test` / `password`. **Never run in production.**
- **Production admins**: promote an existing account with `php artisan app:make-admin {email}` (never the seeder).
- **Ban** = soft delete + Sanctum token revocation (there is no `banned_at` column). A banned user's username/email stay **reserved** (the unique citext indexes have no `deleted_at` carve-out). Unban = restore. Admins cannot ban themselves.

## Place claims (restaurant verification)

Before a restaurant can publish offers — or be charged for a redemption — someone has to prove they actually run the venue. That proof is a **place claim** (`place_claims`, T-041, `06-monetization.md §2.1`).

A place on the map is free and unclaimed by default: it got there because someone shared a video about it. Claiming is opt-in, and only matters once an operator wants the paid surfaces.

### The one rule everything else follows

**Every method proves control of something the *place record already lists* — never something the claimant types.**

The OTP goes to `places.phone`. The token is looked for on the host of `places.website`. Neither endpoint accepts a phone number or a domain, and that omission is deliberate: a claimant who could nominate either could verify any venue on the map. If you are adding a fourth method, this is the property to preserve.

### The three methods

| Method | What it proves | How it is checked | Needs a human? |
|---|---|---|---|
| `phone` | You can answer the phone the business publicly lists | 6-digit code sent to `places.phone`; claimant submits it back | No |
| `website` | You can publish files on the business's own domain | Backend fetches `<scheme>://<host of places.website>/.well-known/reelmap-verify.txt` and looks for the issued token | No |
| `document` | Everything else — business registration, a utility bill | An admin reads it in Filament | Yes |

**`phone`** — a 6-digit code is generated, **stored hashed**, and sent to the number already on the place. The API never returns the code (receiving it *is* the proof); it returns only `phone_last4` so the screen can say which line to answer. The code expires in 15 minutes, and **five wrong guesses burn it**, so the TTL is not the only bound on guessing six digits.

**`website`** — a token (`reelmap-verify-…`) is issued and returned to the claimant, along with the exact `verification_url` to publish it at, so nobody has to guess the path. The token is valid 72 hours. The fetch goes through `PublicUrlGuard` with **redirects disabled**: `places.website` was extracted from a third party, so it is untrusted input aimed at our own HTTP client — without the guard, "verify my website" is a request-forgery primitive pointed at anything the API server can reach. The two failure modes are kept apart: a connection or guard failure is `site_unreachable`, while a reachable site that does not serve the token — including a 404, i.e. the file was never published — is `token_not_found`. **Both leave the claim `pending`**, so a flaky host never burns it and the operator can publish the file and retry.

**`document`** — nothing is auto-verified. The claim lands in the Filament queue and waits for a person (the spec sets a two-business-day SLA). This is the fallback for the long tail of places with neither a phone nor a website on file.

### Endpoints

All authenticated (`auth:sanctum`), throttled `10/min`, and scoped to the **caller's own** claim — no user id appears in any signature.

```
GET    /api/v1/places/{place}/claim          your claim on this place, or null
POST   /api/v1/places/{place}/claim          { method: phone | website | document }
POST   /api/v1/places/{place}/claim/verify   { method: phone, code } | { method: website }
```

`GET` returns **only your own** claim, so the endpoint cannot be used to learn who is trying to claim which venue. `document` is rejected at the `verify` endpoint by validation rather than falling through to a misleading "no pending claim" — a document claim is settled by an admin, not by the claimant.

### One verified owner per place

Enforced by a **partial unique index**, not by application code:

```sql
CREATE UNIQUE INDEX place_claims_one_verified_per_place
  ON place_claims (place_id) WHERE status = 'verified';
```

Two admins approving competing claims in separate requests is exactly the race an in-application check misses, and a second owner would mean two people creating offers and drawing fees against one venue. Pending and rejected rows are deliberately **unconstrained**, so competing claims can accumulate and be escalated rather than being refused at insert. When one claim verifies, the others on that place are closed with `reason = claimed_by_other`.

A conflict never says *who* holds the place — `already_claimed` for a stranger, `already_yours` (idempotent) for the operator who already has it.

### What a verified claim grants

- `users.is_restaurant_owner` is set — a capability flag, not the ownership record. **The verified `place_claims` row is what scopes an operator to a specific place**; the flag only says "this account operates something".
- `places.claimed` flips to `true` on the public place API. It is a **boolean on purpose**: "this venue is claimed" is the signal a diner acts on; who runs it is not public.
- `evidence_json` is **nulled on verify** — a hashed OTP or a live token kept past verification is pure liability. The column is `$hidden` on the model regardless.

### Admin queue

**`/admin` → Moderation → Place Claims**, badged with the pending count. Defaults to pending, **oldest first** — it is a work queue with an SLA, so the default sort is work order rather than newest. A **Disputed only** filter isolates places with two or more live claims, which is what the automatic methods cannot settle on their own.

Approve grants operator access and closes competing claims; Reject grants nothing and records who decided. Both are confirm-gated, because approval is what lets an account create offers and draw fees against a venue.

### Configuration

```
PLACES_CLAIM_VERIFY_HOST=true    # DNS-resolve + vet the website host is public.
                                 # OFF only in the no-network test env.
PLACES_CLAIM_WEBSITE_TIMEOUT=8   # seconds
```

### Not yet wired

The SMS/robocall provider is **a log line, not a stub sender** (`place_claim.otp_issued`). A fake "sent" would make the flow look finished when nothing leaves the building. To exercise the phone path locally, read the code out of the log — or seed a claim with a known code.

`email_domain` and `google_business` appear in `02-data-model.md §3.12` but are **deliberately not implemented** — see **ADR-041**, which also records why place claims use their own `PlaceClaimMethod` enum instead of the `ClaimMethod` that belongs to influencer claims.

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
