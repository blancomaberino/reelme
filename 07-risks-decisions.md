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

### ADR-085: Admin moderation batch — take-down = `places.status`, force-reprocess bypasses the pipeline guards
- **Context:** T-072. The Filament admin panel at `/admin` was 100% read-only (Shares + Places tables offered only a `ViewAction`; `canCreate/Edit/Delete = false`). Two moderation needs had no path: (a) take down bad content, (b) re-run extraction on a share whose pin came out wrong under an old prompt version. The only reprocess entrypoint (`ShareController::retry`) refuses anything but `Failed`/`fetch_unavailable`, and the pipeline actively blocks re-running a `Published` share (`PipelineStubJob::handle` status guard, `ExtractPlaceData::existingSuccess` reuse, `PublishShare` published short-circuit, the terminal-state guard in `Share::transitionTo`). **Correction to prior notes:** admin access was NOT missing — `users.is_admin`, `User::canAccessPanel()`, `reelmap:make-admin`, `AdminUserSeeder`, and the `->admin()` factory state already existed; only granting a dev admin remained.
- **Key finding — two independent visibility gates:** the global map/browse/search filter *only* on `Place::scopePubliclyVisible` (`status IN (pending,active) AND merged_into IS NULL`); the feed/profile cards additionally require `shares.status = Published` + `published_place_source_id` + `published_at` **AND** `whereHas('publishedPlaceSource.place', publiclyVisible())`. So the *place status* gate sits under *both* — a non-matchable place status drops a pin from the map **and** its feed cards in one column change.
- **Reconciliation (post-build, owner-confirmed):** the place-level admin take-down **reuses the EXISTING `Hidden` status + the per-record Hide/Restore (T-035, `ViewPlace`)**, NOT a parallel `Removed`. T-035 already had `hide` (→`Hidden`, "disappears from map/browse/search") + `restore` (`Hidden`→`Pending`) on the place detail; T-072's bulk take-down is the *bulk* counterpart, same status. `Removed` stays exclusively the auto-orphan tombstone (T-071/073, used by `ShareModerator`/`ForceReprocessShare` via `tombstoneIfOrphaned`). This removed the duplicate take-down mechanism AND the `Removed`-overload bug class (restoring an auto-orphan-`Removed` place would have resurrected a sourceless ghost pin — a whole class of guard that `Hidden` (never auto-created) simply doesn't need).
- **Decision (product choices confirmed with Marcelo): full take-down, soft/reversible, fresh re-extract.**
  1. **`PlaceModerator::takeDown/restore`** — take-down = `places.status = Hidden` (the bulk counterpart of T-035's per-record Hide; `Hidden` fails `publiclyVisible` so it drops from both gates); restore = `Hidden`→`Pending` (back to the review queue). Reversible; sources untouched.
  2. **`ShareModerator::takeDown`** — the share-level "remove from feed": unpublish (`status → Rejected`, reason `admin_removed`) + null the sources' `published_at`, then recount; the pin drops only when this share was its last published contributor (a place others also published survives). Honest per-user reach.
  3. **`ForceReprocessShare`** — delete the share's `place_sources` (so `ResolvePlace` re-resolves), tombstone the freed pins, raw-reset status past the terminal guard via new `Share::forceResetStatus()`, and re-dispatch `Pipeline::chain(..., forceExtract: true)`. A new `forceExtract` flag makes `ExtractPlaceData` skip its succeeded-run reuse so the LLM genuinely re-runs — no schema change, `AnalysisRun` audit history preserved. Verified idempotent (no duplicate sources via `unique(place_id, share_id)`; counters recomputed, not summed).
  4. **Surface:** custom Filament `BulkAction`s + per-record `Action`s on the Shares and Places tables (each an admin-gated closure — the panel is already `is_admin`-gated, so no policy needed; the resources keep default edit/delete off). Confirmation dialogs on all destructive actions.
- **Consequences:** No new API/mobile surface — moderation lives entirely in Filament. "Remove from feed" and "remove the pin" are recognized as distinct operations with distinct primitives (share columns vs `places.status`). Soft-only (no hard row deletion) keeps every action reversible and avoids fighting the revival machinery. Follow-ups noted: a "reprocess from `fetch`" (re-download) button (service already takes `fromStage`); bulk actions on other resources if needed.

### ADR-086: Google-verified places activate on the first source
- **Context:** A place's lifecycle status (`PlacePublisher::recompute`) promoted `pending → active` only on influencer-share corroboration: `sources ≥ 2 OR the sharer user-confirmed in review`. But places that resolved to a real Google Places establishment — canonical `google_place_id`, rating, hundreds/thousands of reviews — sat `pending` indefinitely because only one influencer had shared them. Owner feedback (2026-07-19): "if we can pull Google Places info + reviews, it's an actual business — it should be active." A third-party Google match with reviews is *stronger* corroboration than a second influencer share, so it was wrong to ignore it.
- **Decision:** Add **Google-verified** as an activation trigger alongside the existing two: a `pending` place activates when `google_place_id IS NOT NULL AND google_rating_count ≥ 1` (`PlacePublisher::isGoogleVerified`). The resolver (`PlaceResolver`) already persists `google_rating_count` at resolve time, before publish, so new places activate on their first publish. **A bare `google_place_id` with zero reviews does NOT activate** — a thin/address-only geocode match isn't proof of a live establishment; it stays pending until a second source or a human confirms (guards against a wrong match auto-verifying). A one-time backfill migration lifts existing `pending` + Google-verified rows to `active` (only `pending` moves; never Merged/Hidden/Removed; irreversible `down()` by design — it can't know which were pending for other reasons).
- **Consequences:** The Filament "Review queue (pending)" empties for Google-matched places — intended (we trust the Google match; the human dedup queue now surfaces only genuinely-unverified pins). `pending` now means "single influencer source AND not Google-verified" — a smaller, higher-signal review set. **Watch-item / follow-up:** a place first published with 0 Google reviews that later gains them via the standalone `RefreshStaleGoogleReviews` cron won't auto-activate (that path doesn't call `recompute`); the re-share refresh path (`GooglePlaceRefresher` during a publish) does. If the cron case matters, have it promote pending→active when it lands the first review. Distinct from T-072 (moderation) — shipped as its own PR.

### ADR-087: Share-intent ingest reuses the single Share screen; manual-media fallback deferred — T-025
- **Context:** T-025 ("share-intent receiver + ShareIngest screen") was specced against an idealized API and a from-scratch mobile flow: a modal `share-ingest.tsx` + a separate `shares/[id]/status` route, a `409 duplicate_share` branch, and a private-post manual fallback that uploads a screen recording via `POST /shares/:id/media` + `POST /shares/:id/manual`. By the time it was implemented, the ingest **core already shipped** (share-sheet registration via `expo-share-intent`, cold-start capture via `app/+native-intent.tsx`, `ShareIntentProvider`, and `app/(main)/share.tsx` doing submit + live-status polling + published/review/multi-place/T-076 auto-open), and the **API had diverged** from the spec in three ways: (1) a re-shared post is an **idempotent replay** (`202` + `meta.idempotent_replay`), never a `409`; (2) there is **no `/shares/:id/media` or `/shares/:id/manual`** endpoint — manual entry is `POST /shares { caption, shared_via:'manual' }`; (3) a private post (`fetch_auth_required`) lands in **Review** and is **not** retryable (`ShareController::retry` allows only `Failed` or `Review`+`fetch_unavailable`). The mobile "link account" screen is separately deferred to T-015's follow-up.
- **Decision:** Complete T-025's *implementable, API-backed* surface on the existing single-screen architecture rather than rebuild it into the specced modal+status routes (which would discard shipped, tested UX — T-071/T-073/T-076). Shipped: (a) **auth-gate URL survival** — the shared payload is staged in `useUiStore.pendingShare` *before* the sign-in redirect (the previously-stubbed, unused `pendingShareUrl` was the intended hook but was never wired, so a logged-out share was silently dropped), a guest sees a "sign in to add this place" banner, and the share resumes post-login; (b) a client-side **platform badge** (`platformFromUrl`); (c) a friendly **"already added"** note driven by `meta.idempotent_replay` (the spec's `409` behavior, adapted to the real API); (d) a **Retry** action on `Failed` shares (`POST /shares/:id/retry`); (e) the Android `text/*` intent filter. `extractUrl` pulls the link out of Instagram-style shared text. Route params (`sharedUrl`/`sharedText`) remain a fallback for the Maestro/CI deep-link entry (T-053).
- **Deferred (blocked on missing API):** uploading a screen recording to an existing private-post share, and retry-after-account-link for `fetch_auth_required`. Both need new API endpoints (a share-scoped media attach + a manual re-drive, and relaxing the retry guard once an account is linked). The private-post message the API already returns ("This post is private. Link the account or upload it manually.") is surfaced; the interactive linking flow ships with T-015's mobile side. Tracked as a follow-up; no spec edit — the plan text stands and this ADR records the divergence.
- **Consequences:** The ingest screen stays the one place a share is composed, auto-submitted, and tracked. The `409 duplicate_share` contract in the T-025 spec is superseded by idempotent replay project-wide (already how the API behaves). A logged-out share is no longer lost — the highest-value gap the original stub anticipated. Screen-recording ingestion remains manual-caption-only until the API grows the attach endpoints.

### ADR-095: PlaceResolver decomposition + Jaro-Winkler retained as a complementary dedup signal — T-095
- **Context:** `PlaceResolver` (783 lines) was the largest file in `Services`, mixing five concerns: the dedup decision tree, the raw PostGIS candidate scan, a hand-rolled Jaro-Winkler, place creation + column clamping, and per-canonical distributed locking. The 2026-07-21 architecture audit flagged it for a split, and specifically flagged the hand-rolled Jaro-Winkler as "duplicating the Postgres `similarity()` trigram call already computed two lines away."
- **Decision:** Split into two collaborators, keeping `PlaceResolver` as a thin orchestrator over them (+ `Geocoder`/`InstagramProfileLocator`/`GooglePlaceRefresher`):
  1. **`PlaceDedupMatcher`** — the geo + name dedup scan (`scanCandidates`/`fuzzyMatches`/`candidatesFor`). Single definition of "looks like a duplicate," shared by the pipeline resolver and the T-035 admin review queue (the two Filament call sites now resolve `PlaceDedupMatcher` directly).
  2. **`PlaceFactory`** — new-place construction from a geocode or IG-profile location, with the untrusted-extraction column clamping (`truncate`/`priceRange`/`countryCode`/`component`).
  **Jaro-Winkler is retained** (moved into `PlaceDedupMatcher`), NOT removed. It is not a duplicate of the trigram score: pg_trgm measures trigram overlap while Jaro-Winkler is an edit-distance measure with a shared-prefix boost. They catch different near-misses — trigram is weak on short names and transposed tokens, Jaro-Winkler rewards a common prefix — and the scan combines them with `max()`, so dropping JW would *lower dedup recall* (more duplicate pins created), a real behavior change the existing tests would not necessarily catch. The audit's "duplicate" framing is inaccurate; the second signal earns its place.
- **Consequences:** Behavior-preserving — all 43 existing resolver/dedup/Filament tests pass unchanged; new isolated unit tests cover the matcher (radius/similarity gates, tombstone exclusion, `candidatesFor` self-exclusion + sort) and the factory (geocode + profile creation, column clamping, country sentinel). `PlaceResolver` drops from 783 → ~490 lines. If a future data-quality review shows the trigram signal alone suffices at the 0.85 threshold, JW can be dropped then with a recall measurement — this ADR is the record that the removal was considered and deferred, not overlooked.

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
