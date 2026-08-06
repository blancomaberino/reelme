# Reelmap — 03: API Design

Status: canonical spec for build agents. All endpoints implemented in `apps/api` (Laravel 13), consumed by `apps/mobile`; request/response shapes are contract-tested against `packages/contracts/schemas`.

## 1. Conventions

- **Style:** REST over HTTPS, JSON only. Prefix: `/api/v1`. Versioning via URL path; breaking changes require `/api/v2`.
- **Auth:** Sanctum bearer tokens (`Authorization: Bearer <token>`), one token per device (`device_name` at login). Public read endpoints (public maps/profiles/places) work unauthenticated.
- **Envelope (JSON:API-ish):** every success response is `{"data": ..., "meta": {...}}`. `data` is an object or array; `meta` carries pagination, counts, timing. No `included` compound documents — relations are embedded objects, controlled by `?include=` where noted.
- **IDs:** ULIDs, string-typed in JSON.
- **Pagination:** cursor-based. Request: `?cursor=<opaque>&limit=<1..100, default 25>`. Response `meta.pagination`: `{"next_cursor": "…"|null, "prev_cursor": "…"|null, "limit": 25}`.
- **Timestamps:** ISO 8601 UTC (`2026-07-09T14:03:00Z`). Money: integer minor units + `currency` ISO 4217.
- **Error shape (all non-2xx):**

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The url field must be a valid URL.",
    "details": {"url": ["The url field must be a valid URL."]},
    "request_id": "req_01J9ZC5N8Q"
  }
}
```

  Stable machine `code` values include: `validation_failed` (422), `unauthenticated` (401), `forbidden` (403), `not_found` (404), `conflict` (409), `rate_limited` (429), `ingest_failed`, `analysis_failed`, `redemption_invalid`, `insufficient_balance`, `server_error` (500).
- **Rate limits** (per user, Redis limiter; headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` on 429):
  - default authenticated: 60/min; unauthenticated public reads: 30/min per IP
  - `POST /shares`: 10/min, 100/day
  - auth endpoints: 5/min per IP
  - `POST /redemptions/verify`: 30/min per restaurant account
  - `GET /map/places`: 120/min (map panning)
- **Idempotency:** `POST /shares`, `POST /redemptions`, `POST /wallet/payouts` accept an `Idempotency-Key` header; replays return the original response with `meta.idempotent_replay: true`.

## 2. Endpoints

Legend — Auth: `public`, `user` (Sanctum token), `restaurant` (user with verified place claim), `admin` (Filament only). Phase: roadmap milestone where the endpoint ships.

### 2.1 Auth

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/auth/register` | Email+password registration; returns user + token | public | M0 |
| POST | `/api/v1/auth/login` | Login with email+password + `device_name`; returns token | public | M0 |
| POST | `/api/v1/auth/social` | Sign in with Apple/Google (`provider`, `id_token`); creates or links account | public | M0 |
| POST | `/api/v1/auth/refresh` | Rotate current token (issue new, revoke old) | user | M0 |
| POST | `/api/v1/auth/logout` | Revoke current device token | user | M0 |
| POST | `/api/v1/auth/forgot-password` | Send reset email | public | M0 |
| POST | `/api/v1/auth/reset-password` | Reset with token | public | M0 |

### 2.2 Me / Profile

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/me` | Current user, settings, model preference, counters | user | M0 |
| PATCH | `/api/v1/me` | Update handle, display name, bio, avatar, map visibility (`public`/`private`), analysis model preference | user | M0 |
| DELETE | `/api/v1/me` | Account deletion (soft, queued purge) | user | M5 |

### 2.3 Platform Accounts

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/platform-accounts` | List linked accounts (platform, handle, scopes, status) | user | M1 |
| POST | `/api/v1/platform-accounts/:platform/link` | Begin OAuth link (Instagram first); returns `authorize_url` | user | M1 |
| GET | `/api/v1/platform-accounts/:platform/callback` | OAuth redirect target; stores token in `platform_accounts` | public (state-signed) | M1 |
| DELETE | `/api/v1/platform-accounts/:id` | Unlink and revoke stored token | user | M1 |

### 2.4 Shares (ingest)

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/shares` | Ingest a shared URL (or manual payload); returns 202 + share in `pending` | user | M1 |
| GET | `/api/v1/shares` | List my shares (filter `?status=`) | user | M1 |
| GET | `/api/v1/shares/:id` | Share status incl. analysis progress, extraction result, place candidate | user (owner) | M1 |
| PATCH | `/api/v1/shares/:id` | Confirm/correct extraction while `status=review`; `action: "publish"` publishes | user (owner) | M1 |
| POST | `/api/v1/shares/:id/retry` | Re-run failed step (refetch or reanalyze, optional `model` override) | user (owner) | M1 |
| POST | `/api/v1/shares/:id/media` | Get presigned R2 upload URL(s) for manual caption/screen-recording fallback | user (owner) | M1 |
| DELETE | `/api/v1/shares/:id` | Discard an unpublished share | user (owner) | M1 |

### 2.5 Analysis

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/analysis/models` | Merged model list: local Ollama tags + curated OpenRouter models, with `source`, `cost_class`, `default` flag | user | M1 |
| PUT | `/api/v1/me/analysis-preference` | Set preferred model id (`auto` = local-first with remote fallback) | user | M1 |
| GET | `/api/v1/shares/:id/analysis-runs` | List analysis_runs for a share (model, status, timings, raw extraction) — debugging/review UI | user (owner) | M1 |

### 2.6 Places

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/places` | Index with filters: `?q=&tags[]=&near=lat,lng&radius_m=&influencer_id=&sort=` | public | M2 |
| GET | `/api/v1/places/:id` | Place detail: geo, tags, aggregate stats, `?include=sources,offers` | public | M2 |
| GET | `/api/v1/places/:id/sources` | place_sources: each source post with sharer, influencer, original post link (attribution) | public | M2 |
| POST | `/api/v1/places/:id/claims` | Restaurant claims ownership (evidence payload; verified in Filament) | user | M4 |

### 2.7 Map

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/map/places` | `?bbox=minLng,minLat,maxLng,maxLat&zoom=&tags[]=&filter=all|following|mine` → clustered pins (PostGIS grid clustering by zoom) | public (`following`/`mine` require user) | M2 |

### 2.8 Feed

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/feed` | Reverse-chron published shares from followed users/influencers; `?scope=following|global` | user (global: public) | M3 |

### 2.9 Users & Influencers

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/users/:handle` | Public profile + counters | public | M3 |
| GET | `/api/v1/users/:handle/map` | User's public map (their published places; bbox params as §2.7) | public | M3 |
| GET | `/api/v1/influencers/:id` | Influencer profile (platform handles, place count, follower count) | public | M2 |
| GET | `/api/v1/influencers/:id/map` | All places sourced from this influencer's posts | public | M2 |
| POST | `/api/v1/influencers/:id/claim` | Influencer claims their auto-created profile (verify via linked platform account) | user | M4 |

### 2.10 Follows

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/follows` | Follow `{followable_type: "user"|"influencer", followable_id}` | user | M3 |
| DELETE | `/api/v1/follows/:id` | Unfollow | user | M3 |
| GET | `/api/v1/me/follows` | Who I follow | user | M3 |
| GET | `/api/v1/me/followers` | My followers | user | M3 |

### 2.11 Tags & Search

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/tags` | Tag list (`?q=` prefix search, `?popular=1`) | public | M2 |
| GET | `/api/v1/search` | Meilisearch federated search: `?q=&types=places,users,influencers,tags` | public | M2 |

### 2.12 Offers

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/offers` | Diner browse: `?place_id=&near=lat,lng&radius_m=&active=1` | public | M4 |
| GET | `/api/v1/offers/:id` | Offer detail (terms, validity window, redemption limits) | public | M4 |
| POST | `/api/v1/offers` | Restaurant creates offer for a claimed place | restaurant | M4 |
| PATCH | `/api/v1/offers/:id` | Update/pause offer | restaurant (owner) | M4 |
| DELETE | `/api/v1/offers/:id` | Archive offer | restaurant (owner) | M4 |

### 2.13 Redemptions

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/redemptions` | Diner generates a single-use code/QR for an offer (`{offer_id}`); carries attribution chain (share → sharer → influencer) | user | M4 |
| GET | `/api/v1/redemptions/:id` | My redemption (code, QR payload, status, expiry) | user (owner) | M4 |
| POST | `/api/v1/redemptions/verify` | Restaurant scans/enters code; validates, marks redeemed, posts ledger entries (attributed visit) | restaurant | M4 |
| GET | `/api/v1/me/redemptions` | Diner redemption history | user | M4 |
| GET | `/api/v1/places/:id/redemptions` | Restaurant redemption log for its place | restaurant (owner) | M4 |

### 2.14 Wallet

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| GET | `/api/v1/wallet` | Balance (available/pending) derived from double-entry `ledger_entries` | user | M4 |
| GET | `/api/v1/wallet/ledger` | Cursor-paginated ledger entries | user | M4 |
| POST | `/api/v1/wallet/payouts` | Request payout of available balance (min threshold; creates `payouts` row → Stripe transfer) | user (Connect onboarded) | M4 |
| GET | `/api/v1/wallet/payouts` | Payout history + statuses | user | M4 |
| POST | `/api/v1/wallet/connect/onboarding-link` | Create/refresh Stripe Connect Express account link; returns hosted onboarding URL | user | M4 |
| GET | `/api/v1/wallet/connect/status` | Connect account status (`onboarded`, `payouts_enabled`, requirements due) | user | M4 |
| GET | `/api/v1/me/influencer/dashboard` | Influencer earnings funnel (shares → views → issued → redeemed → earnings), per place and per source post, + balance/threshold/Connect status. `?period=30d\|90d\|all`. Counts derive from the FROZEN `redemptions.attributed_*`, never a share→source_post walk. Schema: `influencer-dashboard.json` | user (claimed influencer; 403 otherwise) | M4 |

### 2.15 Notifications & Devices

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/devices` | Register Expo push token `{token, platform}` | user | M1 |
| DELETE | `/api/v1/devices/:id` | Remove device token | user | M1 |
| GET | `/api/v1/notifications` | In-app notification list | user | M3 |
| POST | `/api/v1/notifications/read` | Mark read `{ids: []}` or `{all: true}` | user | M3 |

### 2.16 Moderation

| Method | Path | Purpose | Auth | Phase |
|---|---|---|---|---|
| POST | `/api/v1/reports` | Report content `{reportable_type: place|share|user|offer, reportable_id, reason, details}` | user | M3 |

**Admin:** no REST surface. All admin operations (report triage, place claims, ledger ops, analysis-run inspection, platform kill-switches) live in Filament at `/admin` on the API app, guarded by admin users. Build agents must not generate `/api/v1/admin/*` routes.

## 3. Representative Payloads

### 3.1 POST /api/v1/shares

Request:

```json
{
  "url": "https://www.instagram.com/reel/DAbC123xyz/",
  "shared_text": null,
  "source_hint": "instagram"
}
```

Response `202 Accepted`:

```json
{
  "data": {
    "id": "shr_01J9ZD2K4M7P",
    "status": "pending",
    "url": "https://www.instagram.com/reel/DAbC123xyz/",
    "platform": "instagram",
    "requires_manual_input": false,
    "place": null,
    "created_at": "2026-07-09T14:03:00Z"
  },
  "meta": {"poll_interval_ms": 2000}
}
```

### 3.2 GET /api/v1/shares/:id (status = review)

```json
{
  "data": {
    "id": "shr_01J9ZD2K4M7P",
    "status": "review",
    "status_history": [
      {"status": "pending", "at": "2026-07-09T14:03:00Z"},
      {"status": "fetching", "at": "2026-07-09T14:03:02Z"},
      {"status": "analyzing", "at": "2026-07-09T14:03:11Z"},
      {"status": "review", "at": "2026-07-09T14:03:39Z"}
    ],
    "source_post": {
      "id": "src_01J9ZD2M9QAA",
      "platform": "instagram",
      "url": "https://www.instagram.com/reel/DAbC123xyz/",
      "author_handle": "@noodle.hunter",
      "caption": "Best hand-pulled noodles in Lisbon, hidden on a side street…",
      "fetched_via": "oembed"
    },
    "analysis": {
      "run_id": "arn_01J9ZD2P7XQ2",
      "model": "ollama/qwen2.5-vl:32b",
      "status": "succeeded",
      "confidence": 0.91,
      "extraction": {
        "place_name": "Lanzhou Noodle House",
        "address_text": "Rua do Benformoso 168, Lisbon",
        "dishes": ["hand-pulled beef noodles", "chili oil dumplings"],
        "tags": ["noodles", "chinese", "cheap-eats"],
        "influencer_handle": "@noodle.hunter"
      }
    },
    "place_candidate": {
      "id": null,
      "matched_existing": false,
      "name": "Lanzhou Noodle House",
      "lat": 38.7169,
      "lng": -9.1355,
      "google_place_id": "ChIJx1example9",
      "address": "R. do Benformoso 168, 1100-064 Lisboa, Portugal"
    },
    "failure": null
  },
  "meta": {"poll_interval_ms": 5000}
}
```

`PATCH /shares/:id` accepts corrected `extraction` fields and/or a `place_candidate.google_place_id` override, plus `{"action": "publish"}`. On `failed`, `failure` is `{"code": "ingest_failed", "step": "fetching", "message": "...", "manual_fallback": true}`.

### 3.3 GET /api/v1/map/places?bbox=-9.20,38.69,-9.10,38.75&zoom=13

```json
{
  "data": {
    "pins": [
      {
        "type": "place",
        "id": "plc_01J9ZE0AAA11",
        "name": "Lanzhou Noodle House",
        "lat": 38.7169,
        "lng": -9.1355,
        "tags": ["noodles", "chinese"],
        "source_count": 3,
        "has_active_offer": true,
        "top_influencer": {"id": "inf_01J9ZE0BBB22", "handle": "@noodle.hunter"}
      }
    ],
    "clusters": [
      {
        "type": "cluster",
        "cluster_id": "13:2431:1580",
        "lat": 38.7301,
        "lng": -9.1502,
        "count": 17,
        "expand": {"bbox": [-9.158, 38.724, -9.142, 38.736]}
      }
    ]
  },
  "meta": {"zoom": 13, "total_in_bbox": 43, "clustered": true}
}
```

Clustering: server-side PostGIS grid (`ST_SnapToGrid` cell size by zoom); at `zoom >= 15` no clusters, pins only.

### 3.4 POST /api/v1/redemptions/verify

Request (restaurant device):

```json
{"code": "RLM-7F3K-92QX", "place_id": "plc_01J9ZE0AAA11"}
```

Response `200`:

```json
{
  "data": {
    "redemption": {
      "id": "rdm_01J9ZF9HH777",
      "status": "redeemed",
      "redeemed_at": "2026-07-09T19:22:41Z",
      "offer": {"id": "ofr_01J9ZF0CC333", "title": "Free dumplings with any noodle bowl"},
      "diner": {"handle": "@marcelo", "display_name": "Marcelo"}
    },
    "attribution": {
      "share_id": "shr_01J9ZD2K4M7P",
      "sharer": {"id": "usr_01J9ZA1XYZ", "handle": "@marcelo"},
      "influencer": {"id": "inf_01J9ZE0BBB22", "handle": "@noodle.hunter"},
      "source_post_url": "https://www.instagram.com/reel/DAbC123xyz/"
    },
    "ledger_postings": [
      {"account": "restaurant:plc_01J9ZE0AAA11", "direction": "debit", "amount": 200, "currency": "EUR", "memo": "attributed visit fee"},
      {"account": "influencer:inf_01J9ZE0BBB22", "direction": "credit", "amount": 120, "currency": "EUR"},
      {"account": "sharer:usr_01J9ZA1XYZ", "direction": "credit", "amount": 30, "currency": "EUR"},
      {"account": "platform:revenue", "direction": "credit", "amount": 50, "currency": "EUR"}
    ]
  },
  "meta": {}
}
```

Errors use `redemption_invalid` with `details.reason` in `{expired, already_redeemed, wrong_place, not_found}`. Verification is transactional: code state flip + balanced ledger postings commit atomically.

### 3.5 GET /api/v1/wallet

```json
{
  "data": {
    "balance": {
      "available": {"amount": 4230, "currency": "EUR"},
      "pending": {"amount": 150, "currency": "EUR"}
    },
    "lifetime_earnings": {"amount": 12800, "currency": "EUR"},
    "connect": {
      "onboarded": true,
      "payouts_enabled": true,
      "requirements_due": []
    },
    "minimum_payout": {"amount": 1000, "currency": "EUR"},
    "recent_entries": [
      {
        "id": "led_01J9ZF9JJ888",
        "type": "revenue_share",
        "direction": "credit",
        "amount": 30,
        "currency": "EUR",
        "memo": "Attributed redemption at Lanzhou Noodle House",
        "created_at": "2026-07-09T19:22:41Z"
      }
    ]
  },
  "meta": {}
}
```

## 4. Webhooks

### 4.1 Stripe → API

Endpoint: `POST /api/v1/webhooks/stripe` (public, verified via `Stripe-Signature` with webhook secret; unauthenticated but signature-gated; excluded from CSRF and rate-limited generously). Handler queues processing on `payouts` queue and returns 200 immediately. Events consumed:

| Event | Action |
|---|---|
| `account.updated` | Sync Connect status onto user (`payouts_enabled`, `requirements_due`); notify user when onboarding completes or new requirements appear |
| `payout.paid` / `transfer` success events | Mark `payouts` row `paid`, post settling ledger entries (`pending → settled`) |
| `payout.failed` | Mark payout `failed`, reverse ledger hold (credit back to available), notify user |
| `charge.refunded` (offer billing) | Reverse the associated redemption's ledger postings via compensating entries |

All events stored in a `stripe_events` table (event id unique) before processing — idempotent, replay-safe.

### 4.2 Internal analysis-pipeline callback

The analysis pipeline runs in-process (Horizon jobs), so the "callback" is internal, not HTTP: each pipeline stage transitions `shares.status` and fires `ShareStatusChanged`, which (a) is picked up by client polling of `GET /shares/:id`, and (b) triggers an Expo push notification on `review`, `published`, and `failed`.

If the Ollama host is ever split into a separate worker service that cannot run Laravel jobs, it reports back via `POST /api/v1/internal/analysis-callback` `{share_id, run_id, status, extraction|error}` authenticated with a static bearer token (`INTERNAL_CALLBACK_TOKEN`), IP-allowlisted. Route exists behind the `internal` middleware group from M1 but is a no-op passthrough to the same `ShareStatusChanged` flow.

## 5. Cross-Cutting Notes for Build Agents

- Every response resource has a JSON Schema in `packages/contracts/schemas`; add/modify the schema in the same PR as the endpoint and regenerate TS types.
- Authorization is policy-based (Laravel Policies) per model; `restaurant` role = user with an approved `place_claims` row for the target place.
- All list endpoints must use cursor pagination (no page numbers) and respect `limit` cap of 100.
- Route file: `apps/api/routes/api.php` with `v1` prefix group; controllers under `App\Http\Controllers\Api\V1`.
- Feature tests (Pest) required per endpoint: happy path, authz denial, validation error shape, and rate-limit headers present.
