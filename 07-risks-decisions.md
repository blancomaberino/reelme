# 07 — Risks & Decisions

Status: Draft v1. Companion to 00–06. Decisions here are binding for build agents unless superseded by a later ADR.

## 1. Risk Register

Likelihood/Impact scale: Low / Medium / High.

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R-01 | **Platform ToS / legal — ingestion.** Instagram/Meta prohibits scraping and offers no public API for arbitrary post content; TikTok and X APIs are expensive or restricted; automated fetching may trigger blocks or legal notices. yt-dlp-style extraction is a legal gray zone. | High | High | Layered ingestion: oEmbed/official APIs where available; automated fetch only where realistically tolerated; **manual-fallback ingestion (ADR-011) always available** so the product never depends on scraping; per-platform kill switches; no login-walled scraping with user credentials; legal review before M5 launch. |
| R-02 | **App-store rejection.** UGC requires moderation/report/block (Apple 1.2); Apple 4.2 minimal-functionality; account-deletion-in-app requirement; Google UGC policy. | Medium | High | Ship in M3: report content, block user, moderation queue in Filament, in-app account deletion. Map + offers + social exceed 4.2 bar. Pre-submission checklist item in M5. |
| R-03 | **AI extraction hallucination** — wrong place names/locations pinned, wrong attribution. | High | Medium | Evidence-first pipeline: every extracted field must cite transcript/OCR/caption evidence; confidence score per candidate; below-threshold results require user review before publish; geocode match must corroborate name+locality; user can correct pin (feeds review queue). Contract schema in `packages/contracts` (ADR-006). |
| R-04 | **Geocoding cost creep** (Google Places per-call pricing). | Medium | Medium | `Geocoder` contract (ADR-007): cache-by-place-hash in Postgres, session tokens, Places IDs stored once, nightly budget alarm; can swap provider (e.g. Mapbox/Nominatim tier) without touching callers. |
| R-05 | **Redemption fraud** — fake scans to farm influencer payouts, staff collusion, code sharing. | Medium | High | Controls in 06-monetization §3: single-use signed codes, geofence at verify, velocity limits, per-staff verify caps, self-dealing checks, 7-day void window, payout threshold delays cash-out; anomaly reports in Filament. |
| R-06 | **GDPR** — platform OAuth tokens and social handles are personal data; users can demand export/delete; influencer profiles are third-party personal data. | Medium | High | Encrypt tokens at rest; DSAR export + delete flows in M5 (delete cascades `platform_accounts`, anonymizes `shares`); lawful-basis note: influencer profiles = publicly available business data, with takedown path; EU hosting; DPA with subprocessors (Stripe, OpenRouter, Google). |
| R-07 | **Copyright / DMCA for stored video copies.** Storing downloaded videos = hosting copyrighted content. | Medium | High | **Decision (ADR-010): analyze-then-delete.** Originals held only transiently for AI analysis (≤72 h), then deleted; retain only keyframes/thumbnails (small, transformative, for identification) and always display via embed/link-out to the original post. DMCA takedown endpoint + registered agent before launch. |
| R-08 | **Cold start / marketplace chicken-and-egg** — no offers without restaurants, no restaurants without diners. | High | High | Map is useful with zero restaurants (free discovery layer from shared videos); seed one city; unclaimed-influencer escrow (06 §5.3) as influencer acquisition hook; concierge onboarding of first 20 restaurants; M4 gated on M2/M3 traction metrics. |
| R-09 | **Ollama ops burden** — GPU host maintenance, model updates, queue backpressure, single point of failure. | Medium | Medium | OpenRouter fallback is automatic on timeout/failure (ADR-005); queue-based ingestion tolerates latency; health checks + circuit breaker; acceptable to run 100% OpenRouter temporarily (cost, not availability, problem). |
| R-10 | **Apple/Google payment-policy ambiguity** for real-world dining offers. | Low | Medium | Redemptions are physical-world services → external payments permitted (like TheFork/Groupon). Document reasoning in submission notes; no digital goods sold in-app. |

## 2. Decision Log (ADRs)

Format: Context → Decision → Consequences. All accepted as of 2026-07-09.

### ADR-001: Laravel 13 REST API + Sanctum
- **Context:** Need a productive backend with first-class queues, scheduling, and admin tooling; mobile client needs token auth.
- **Decision:** Laravel 13, plain REST (no GraphQL), Sanctum bearer tokens for the Expo app.
- **Consequences:** Fast delivery, huge ecosystem (Cashier/Stripe, Horizon, Scout). REST versioned under `/api/v1`. No realtime protocol decided yet (see OQ-3).

### ADR-002: Postgres + PostGIS
- **Context:** Core queries are geospatial (map viewport, geofence verify) and financial (ledger).
- **Decision:** Single Postgres instance with PostGIS extension; `places.location` as `geography(Point,4326)`; GiST indexes; ledger in the same DB for atomic transactions.
- **Consequences:** `ST_DWithin`/bbox queries native; one backup/restore story; MySQL-only hosting excluded.

### ADR-003: Monorepo
- **Context:** API, mobile app, shared contracts, and infra must evolve in lockstep pre-1.0.
- **Decision:** Single repo: `apps/api` (Laravel), `apps/mobile` (Expo), `packages/contracts`, `infra/`.
- **Consequences:** Atomic cross-cutting PRs; needs path-filtered CI; no independent versioning until post-launch.

### ADR-004: Expo managed workflow + expo-share-intent
- **Context:** Core UX is "share a video from Instagram/TikTok/X/YouTube into the app"; team is small; OTA updates valuable.
- **Decision:** Expo RN (managed) with `expo-share-intent` for the iOS share extension / Android intent; EAS for builds.
- **Consequences:** Share-sheet ingestion on both platforms without ejecting; config-plugin maintenance risk accepted; native modules limited to Expo-compatible ones.

### ADR-005: Local-first AI via Ollama, OpenRouter fallback
- **Context:** Video/caption analysis is token-heavy; cost control and privacy favor local models; reliability favors hosted.
- **Decision:** Extraction jobs target a local Ollama endpoint first; on timeout, error, or low-confidence output, retry via OpenRouter with a stronger model. Provider behind an `LlmExtractor` contract.
- **Consequences:** Near-zero marginal cost at steady state; dual prompt/output testing burden; fallback keeps ingestion alive when the GPU box is down (see R-09).

### ADR-006: Single extraction JSON schema in `packages/contracts`
- **Context:** Multiple producers (Ollama, OpenRouter, manual entry) and consumers (API, mobile review UI, admin) must agree on extraction output.
- **Decision:** One versioned JSON Schema (`extraction.v1`) in `packages/contracts`; validated on every producer output; evidence spans + confidence required per field.
- **Consequences:** Model/provider swaps don't ripple; schema migrations are explicit (`v2` + adapter); slight prompt rigidity accepted.

### ADR-007: Google Places behind a `Geocoder` contract
- **Context:** Best-in-class place resolution is Google Places, but pricing and lock-in are risks (R-04).
- **Decision:** All geocoding/place-details calls go through a `Geocoder` interface; Google implementation first; results cached in `place_sources`/`places`.
- **Consequences:** Provider swap is a new adapter; must respect Google caching/attribution ToS (store place_id, limited cached fields).

### ADR-008: react-native-maps
- **Context:** Need map clustering + custom pins in Expo; Mapbox RN SDK requires config plugins and billing; Google/Apple native maps are free at app tier.
- **Decision:** `react-native-maps` (Apple Maps on iOS, Google Maps on Android) with clustering.
- **Consequences:** No custom vector styling; two map renderers to QA; can revisit Mapbox post-launch if brand styling demands it.

### ADR-009: Stripe Connect Express + double-entry ledger
- **Context:** Influencer payouts require KYC, tax handling, and transfers; money movement must be auditable.
- **Decision:** Stripe Connect Express for influencer payouts; internal `ledger_entries` double-entry table is the source of truth; Stripe is a settlement rail only (details in 06 §4).
- **Consequences:** KYC outsourced; append-only ledger discipline required (reversing entries, nightly balance check); EUR-only v1.

### ADR-010: Media retention — analyze-then-delete
- **Context:** R-07 (copyright/DMCA) and R-06 (GDPR) make hosting full copies of influencer videos untenable.
- **Decision:** Downloaded originals live in a private bucket ≤72 h, only for AI analysis, then hard-deleted by scheduled job. Keep: keyframes/thumbnails, transcript text, extraction JSON. The app always displays media via platform embed or link-out to the original post; never streams stored copies.
- **Consequences:** Re-analysis requires re-fetch; dead-link risk if the original post is deleted (show thumbnail + "post removed"); dramatically reduced legal surface and storage cost.

### ADR-011: Manual-fallback ingestion as the ToS-safe guaranteed path
- **Context:** R-01 — any automated fetcher can break or be prohibited at any time, per platform.
- **Decision:** The share flow always works with zero automated fetching: user pastes/shares a URL, app captures oEmbed-level metadata where permitted, and the user can type/confirm place details manually (assisted by autocomplete). Automated analysis is an enhancement, never a dependency.
- **Consequences:** Product survives total API/scraping shutdown per platform; manual path must be genuinely fast (<30 s) or sharing dies; extraction pipeline treats manual input as another producer of `extraction.v1`.

### ADR-012: Filament admin
- **Context:** Need moderation queues, claim review, offer oversight, ledger tooling without building a custom admin.
- **Decision:** Filament panel inside `apps/api` for ops/admin; role-gated.
- **Consequences:** Server-rendered admin (Livewire) — fine for internal use; admin work stays in PHP; no admin API surface to secure separately.

### ADR-013: Meilisearch
- **Context:** Users search places, cuisines, influencers with typo tolerance; Postgres FTS is workable but weaker UX; Elastic is overkill.
- **Decision:** Meilisearch via Laravel Scout for `places` and `influencers` indexes; Postgres remains source of truth.
- **Consequences:** One more service to run (small footprint); index rebuild job required; geo-filtering available natively in Meilisearch if needed.

### ADR-071: Personal collection model (Instagram-style ownership) — T-071
- **Context:** The original model was a global pin cloud + chronological feed. Requested 2026-07-15 reframe: each user owns their map + list; nothing global. A place a user shared kept showing on the map even after being hidden from the feed — content ownership was ambiguous.
- **Decision:**
  1. **"Mine" = shared ∪ saved.** A place is mine if I published a share resolving to it (not soft-hidden) OR I saved it to any of my lists. Implemented as `Place::scopeMine(User)` — a query scope, not a data copy. Reuses `place_lists` (T-062); no new "saved" table.
  2. **Purely personal map.** The home map is always the current user's places; the mine/following/all scope chips are removed. Followed users' places are reachable only by visiting their profile → their map (never mixed into mine).
  3. **Soft, reversible removal.** Removing one of my shared places soft-hides it — reuses `feed_dismissals` (now map + list aware via `scopeMine`), keeps the share + canonical place, is undo-able. Hard `DELETE /shares/{id}` stays a separate explicit action.
  4. **Canonical place stays global/deduped.** "My map" is the connected subset (a scope); dedup and place data are unchanged.
  5. The feed is replaced (in-app) by a filterable **"my places"** list (`GET /me/places`: country, type/cuisine, tags) over the same dataset as my map. The `GET /feed` endpoint stays (deprecated, unused by the app) to avoid churn.
- **Consequences:** Ownership becomes a first-class read scope rather than an implicit global-minus-filters. The "removed but still on map" inconsistency is resolved. Per-user profiles gain a map + places list + public Lists (new `GET /users/{username}/places` and `/lists`). Discovery of *new* places narrows to search + visiting users; a broader explore surface can return later as an explicit, opt-in mode.

### ADR-084: Multi-language tags — server-owned i18n + facet-based filtering
- **Context:** Tags (cuisine/vibe/diet/dish) are stored in English (`tags.name/slug`); Spanish display was a client-only static dictionary (`apps/mobile/src/lib/tags.ts`). This broke tag **search** (typing the Spanish label "Informal" never matched the stored English "casual"), left the tag **filter** offering globally-popular tags rather than the tags actually on the user's places (guaranteed-empty selections; long tail beyond the top-100 unsearchable), and left **global search** (`/search?types=tags`) and Filament unable to see Spanish at all. Root cause: two different problems were conflated — filtering is a *facet* problem (bounded, needs completeness) and localization is a *data* problem (belongs in the DB, not one client).
- **Decision (4 PRs, ranked by value/effort):**
  1. **`GET /me/places/tags` facet endpoint** — the discovery tags actually on the caller's places (reusing `Place::scopeMine`), with a per-tag count, deduped by slug. The map + "my places" filter sheets consume it; the tag autocomplete matches over this complete, bounded set client-side (instant, offline-friendly, correct — the client matcher was fine, the *population* was wrong). Global-popular catalog stays only for genuinely global surfaces (search, guest map).
  2. **`tags.name_i18n` JSONB** (`{"es":"Informal"}`), canonical `name` stays English + is the fallback. Seed from the existing `TAGS_ES` dictionary; `TagResource` returns a locale-resolved `label` (from `Accept-Language`/`?locale`). The client dictionary degrades to a legacy fallback, then is deleted. Web + Filament get correct labels for free. Not a `tag_translations` table — JSONB is one migration for 2 locales and migrates to a table later without a contract change.
  3. **Locale-aware Meilisearch** — flatten `name_i18n` into `Tag::toSearchableArray` and the `Place` `tags` searchable attribute (`name_es`, later `name_pt`, …); one index, all locales searchable at once. Route `/tags?q=` through Scout (or Postgres `unaccent`+`pg_trgm`, both already installed) instead of the English-only `LIKE 'q%'`. Fixes the pre-existing Spanish global-search defect.
  4. **AI translate-on-create** for `kind != dish` (queued from `TagMaterializer`), with a Filament review/override column. **Dishes are never translated** — they are verbatim menu text in arbitrary languages (`dishes_language` already models this); they need accent/case-insensitive matching, not localization.
- **Consequences:** Tag identity stays the slug everywhere (never localize identifiers). Filtering by tag becomes complete and per-user relevant; search becomes language-aware without per-locale index sprawl; adding a language is "seed/translate + reindex", not "edit every client". Interim (PR-1 only) the client keeps localizing display while the facet fixes coverage — no rework, `label` is additive. Watch-items: dedupe `(kind, slug)` twins in the facet; `foldSearch` is Spanish-only (extend or let Meilisearch own folding); display label and search haystack must be the same string; keep discovery tags separate from the private `user_place_tags` (T-064).

## 3. Open Questions (deferred, not blockers)

| # | Question | Owner | Resolve by |
|---|---|---|---|
| OQ-1 | Which cities for launch seed, and concierge-onboarding budget for first restaurants? | Marcelo (product) | End of M2 |
| OQ-2 | Per-platform ingestion depth: which platforms get automated fetch vs. manual-only at launch? (Legal review input) | Marcelo + legal counsel | M1 exit |
| OQ-3 | Realtime needs (live redemption confirmation, feed updates): polling vs. Reverb/Pusher websockets? | Backend lead (agent) | M3 planning |
| OQ-4 | Restaurant billing rail: Stripe invoicing vs. SEPA direct debit for monthly fees? | Marcelo | M4 planning |
| OQ-5 | Influencer income tax reporting duties (DAC7 applicability in EU) — does the platform have reporting obligations? | Legal/accounting | Before first payout run (M4) |
| OQ-6 | Unclaimed-escrow expiry: 12 months confirmed, or shorter for accounting simplicity? | Marcelo + accounting | M4 planning |
| OQ-7 | Moderation staffing/tooling scale: is Filament queue + on-call enough at launch volume? | Ops | M5 planning |
| OQ-8 | Ollama hardware target (single GPU box vs. hosted GPU) and model choice for extraction v1. | Infra (agent) + Marcelo | M1 mid-phase |
