# Reelmap — Product Specification

**Doc id:** 00-product-spec
**Status:** Canonical v1 (greenfield)
**Audience:** Autonomous build agents and human reviewers. Requirement ids (`FR-n`, `NFR-n`, `IR-n`) are stable and load-bearing; downstream tasks reference them. Never renumber — deprecate ids instead.

---

## 1. Vision & Elevator Pitch

People discover restaurants in influencer videos on Instagram, TikTok, X, and YouTube — then lose them forever in their saved-posts graveyard. Reelmap turns that moment into a durable, mapped, shareable memory: share the video into Reelmap from the native share sheet, and AI extracts the restaurant, pins it on your map with full attribution (who shared it, which influencer made it, link to the original post). A social layer makes maps and profiles followable; a monetization layer pays influencers per attributed real-world visit and gives restaurants TheFork-style offers with measurable ROI.

**One-liner:** "Turn food videos into a map you'll actually use — and pay the creators who sent you there."

**Core loop (must stay under ~60 s happy path):** watch video → share to Reelmap → AI analysis → pinned place with attribution → visit later via map → redeem offer → influencer gets paid.

---

## 2. Personas

### 2.1 Diner / Sharer ("Maya")
- Watches food reels daily; saves posts she never revisits.
- **Goals:** capture a restaurant from a video in seconds; find it later on a map near her; see the original video before deciding; redeem offers.
- **Pain points:** platform saves are unsearchable and unmapped; captions omit addresses; private accounts and deleted posts lose the info.
- **Success signals:** shares ≥3 posts/week; opens the map when choosing where to eat; redeems an offer.

### 2.2 Influencer ("Leo")
- Food creator, 10k–500k followers across TikTok/Instagram/YouTube.
- **Goals:** claim his canonical Reelmap identity; see every place his content created; earn per attributed visit; embed/point audiences to his public Reelmap map.
- **Pain points:** no attribution or revenue when his video drives a real visit; identity scattered across platforms.
- **Success signals:** claims profile; connects Stripe; earns first payout.

### 2.3 Restaurant Owner ("Sofia")
- Independent restaurant, active on Instagram, skeptical of unmeasurable marketing.
- **Goals:** claim her place; publish offers (discounted menu, freebie); see exactly how many visits each influencer/offer produced; pay only for results.
- **Pain points:** influencer marketing ROI is a black box; existing platforms charge per cover regardless of source.
- **Success signals:** claims place; publishes an offer; validates redemptions at the counter; renews after seeing the visit report.

### 2.4 Admin ("Ops")
- Internal Reelmap operator using Filament admin.
- **Goals:** review moderation/report queues; resolve place duplicates and claims; monitor analysis pipeline health and model costs; handle DMCA/takedowns; manage fraud flags, ledger, and payouts.
- **Success signals:** report SLA met; fraud rate on redemptions kept low; pipeline error rate visible and actionable.

---

## 3. Functional Requirements

Format: `FR-n (Mx)` — one testable sentence; optional detail sub-bullet. Phase = milestone in which the FR must be **complete**.

### 3.1 Ingestion

- **FR-1 (M1)** A user can share a post URL from Instagram, TikTok, X, or YouTube into the app via the native OS share sheet, creating a `share` record linked to a `source_post`.
  - Uses `expo-share-intent`; app cold-start from share sheet must land on the ingest screen with the URL prefilled.
- **FR-2 (M1)** A user can paste a post URL directly into the app as an equivalent entry point to the share sheet.
- **FR-3 (M1)** The system normalizes and deduplicates source URLs so the same post shared by multiple users maps to a single `source_post` row.
  - Canonicalization strips tracking params and resolves platform short-links (e.g., `vm.tiktok.com`).
- **FR-4 (M1)** The system fetches publicly accessible post metadata and media (caption, author handle, thumbnail, video where permitted) for a shared URL and stores them as `media_assets`.
- **FR-5 (M3)** A user can link their own platform account (`platform_accounts`) via OAuth where the platform offers it, enabling fetch of posts visible to that account (e.g., private/followed accounts).
  - Tokens stored encrypted (see NFR-9); scopes limited to read.
- **FR-6 (M1)** When automated fetch fails or is not permitted, the user is offered a guaranteed manual fallback: keep the pasted URL and manually add the caption text and/or upload a screen recording of the post.
  - Fallback must be reachable within one tap from the failure state; no share may dead-end.
- **FR-7 (M1)** Every share displays a live ingestion status (queued → fetching → analyzing → done / needs-input / failed) that updates without manual refresh.
- **FR-8 (M1)** The system records per-platform fetch method and outcome on the `source_post` (public-fetch, linked-account-fetch, manual) for audit and ToS compliance.
- **FR-9 (M2)** A user can re-trigger ingestion/analysis on a share that previously failed or produced a wrong result.
- **FR-10 (M1)** Shares initiated while offline are queued locally and submitted automatically when connectivity returns.

### 3.2 Analysis

- **FR-11 (M1)** The system analyzes caption text and available video/image media with an AI pipeline and produces a structured extraction: candidate place name(s), address hints, city, cuisine, dish mentions, price signals, and influencer handle.
  - Each execution is an `analysis_runs` row storing model id, provider, prompt version, token counts, latency, and raw output.
- **FR-12 (M1)** Analysis runs first against a self-hosted local model via Ollama; on failure, timeout, or low-confidence output it falls back to a remote model via OpenRouter.
- **FR-13 (M2)** A user can select their preferred remote model from an admin-curated OpenRouter model list in settings.
- **FR-14 (M1)** Extracted place data is geocoded through the `Geocoder` contract (Google Places implementation) to obtain a place id, formatted address, and lat/lng.
- **FR-15 (M1)** When extraction or geocoding is ambiguous (multiple candidates or confidence below threshold), the user is shown a disambiguation UI to pick or correct the place before the pin is created.
- **FR-16 (M1)** A user can edit any AI-extracted field (name, address, tags, notes) before and after confirming the place.
- **FR-17 (M1)** Analysis of a video promoting multiple restaurants produces multiple candidate places, each individually confirmable.
- **FR-18 (M1)** The analysis pipeline runs asynchronously on Horizon/Redis queues; no synchronous HTTP request waits on model inference.
- **FR-19 (M2)** The system detects caption language and analyzes non-English captions correctly, storing detected language on the `source_post` (see IR-7).
- **FR-20 (M5)** Analysis quality is measurable: a golden-set evaluation suite scores extraction accuracy per model/prompt version and runs in CI.

### 3.3 Places & Map

- **FR-21 (M1)** A confirmed analysis creates or updates a `place` (PostGIS point) linked to the share, the `source_post`, and the attributed influencer via `place_sources`.
- **FR-22 (M2)** The map screen renders the user's places as pins with clustering, viewport-based loading, and a detail card on tap.
  - `react-native-maps`; API endpoint accepts bbox + zoom and returns clustered results.
- **FR-23 (M2)** A place detail view shows attribution: which user shared it, which influencer authored the source content, and a deep link to the original post on its platform.
- **FR-24 (M2)** When multiple shares resolve to the same real-world place, the system merges them into one `place` with multiple `place_sources` rather than duplicate pins (dedup rules in IR-3).
- **FR-25 (M2)** A user can search their places and global public places by name, tag, cuisine, and city via Meilisearch (Scout).
- **FR-26 (M2)** A user can filter the map by tags, cuisine, source platform, and influencer.
- **FR-27 (M2)** A user can manually add a place with no source post (name + location), producing a pin without influencer attribution.
- **FR-28 (M2)** A user can mark a place with personal states — want-to-go, been, favorite — and a private note, each filterable on the map.
- **FR-29 (M2)** A user can open a place in Apple/Google Maps for directions with one tap.
- **FR-30 (M2)** If the original post is deleted or becomes unavailable, the place entry survives, retains stored metadata/asset references, and labels the source link as unavailable.
- **FR-31 (M2)** Place pages show freshness: date of source post and date shared, so stale recommendations are visible.

### 3.4 Social

- **FR-32 (M0)** A user can register, verify email, log in, and manage sessions using Sanctum token auth.
- **FR-33 (M3)** A user has a public profile (handle, avatar, bio) and can set their map/profile visibility to public or private.
- **FR-34 (M3)** A user can follow other users and influencers (`follows`) and unfollow at any time.
- **FR-35 (M3)** A user can browse a followed user's or influencer's public map and open its places.
- **FR-36 (M1)** The system auto-creates canonical `influencers` records from extracted platform handles, merging the same person across platforms into one identity where evidence allows.
- **FR-37 (M3)** An influencer can claim their canonical profile by proving control of the platform account (e.g., OAuth login or code-in-bio verification), converting it to a claimed account.
- **FR-38 (M3)** A claimed influencer sees a dashboard of all places sourced from their content, with counts of shares, saves, and follows.
- **FR-39 (M3)** A user receives notifications (push + in-app) for analysis completion, new followers, and offers at saved places, with per-category opt-out.
- **FR-40 (M3)** A user can share a public deep link to a place or a public map that opens the app (or an app-store interstitial if not installed).
- **FR-41 (M3)** A feed screen shows recent public activity from followed accounts (new places, new offers), paginated.

### 3.5 Monetization

- **FR-42 (M4)** A restaurant owner can claim a place (`place_claims`) via a verification flow (phone/email/document review), gated by admin approval.
- **FR-43 (M4)** A claimed owner can create, edit, schedule, and deactivate `offers` (e.g., discounted set menu, free item) with terms, validity window, and redemption cap.
- **FR-44 (M4)** A diner can activate an offer in-app and receive a unique, time-boxed redemption code/QR.
- **FR-45 (M4)** Restaurant staff can validate a redemption at the venue (scan QR or enter code in the owner view), which atomically creates a `redemptions` row = one attributed visit.
- **FR-46 (M4)** Each redemption attributes the visit to the causal chain — sharer, influencer, source post — captured at offer-activation time.
- **FR-47 (M4)** Every monetary event (restaurant fee, influencer revenue share, platform take) is recorded as balanced double-entry `ledger_entries`; ledger invariant (debits = credits) is enforced and tested.
- **FR-48 (M4)** Influencers connect Stripe Connect Express and receive payouts of accrued earnings above a minimum threshold, on a fixed schedule, with a visible statement.
- **FR-49 (M4)** Owners see an ROI dashboard: redemptions per offer, per influencer, over time, with exportable CSV.
- **FR-50 (M4)** Redemptions pass anti-fraud checks (see IR-5) before earning ledger credit; flagged redemptions are held for admin review.

### 3.6 Admin & Moderation

- **FR-51 (M0)** Admins operate a Filament panel with role-based access (admin, moderator) protected separately from user auth.
- **FR-52 (M2)** Any user can report a place, share, profile, or offer (`reports`) with a reason; reports enter an admin moderation queue.
- **FR-53 (M3)** Admins can hide/remove content, warn, suspend, or ban accounts, with every action audit-logged.
- **FR-54 (M2)** Admins can view, merge, and split duplicate places, and correct geocoding, with `place_sources` re-parented safely.
- **FR-55 (M4)** Admins can review flagged redemptions, reverse fraudulent ledger entries (via correcting entries, never deletion), and freeze payouts.
- **FR-56 (M1)** Admins can monitor the analysis pipeline: queue depth, failure rate, per-model cost and latency, and re-run failed jobs from the panel.
- **FR-57 (M2)** Admins process takedown/DMCA requests through a dedicated workflow that removes media assets and annotates affected places (see IR-2).
- **FR-58 (M5)** Admins can configure runtime flags: model list, confidence thresholds, per-user quotas, and feature flags per milestone rollout.

---

## 4. Non-Functional Requirements

- **NFR-1 Async pipeline with visible status.** All ingestion/analysis work is queued (Horizon/Redis); users always see current job state (FR-7). No user-facing request blocks on AI or fetch I/O.
- **NFR-2 API latency.** p95 ≤ 300 ms for standard REST endpoints; p95 ≤ 600 ms for map bbox/cluster queries (PostGIS-indexed); measured at the load balancer, excluding queued work.
- **NFR-3 Share-to-result UX.** Happy path (public post, confident extraction) from share-sheet tap to confirmed pin ≤ 60 s at p50, ≤ 120 s at p95; a progress state is visible the entire time.
- **NFR-4 Analysis throughput.** Pipeline sustains 10 concurrent analyses per worker node without queue latency exceeding 30 s at p95 under nominal load.
- **NFR-5 Availability.** API uptime target 99.5% for v1; graceful degradation: if AI providers are down, ingestion still accepts shares and defers analysis.
- **NFR-6 Offline-tolerant mobile.** The app caches the user's map and place details locally; shares queue offline (FR-10); all mutations use optimistic UI with reconciliation; no hard crash on airplane mode.
- **NFR-7 Token auth.** All API access via Sanctum bearer tokens; tokens revocable per device; refresh/rotation policy documented; no cookies for the mobile client.
- **NFR-8 Transport & storage security.** TLS everywhere; secrets in env/secret manager, never in repo; media assets served via signed URLs.
- **NFR-9 Encrypted platform credentials.** `platform_accounts` OAuth tokens encrypted at rest (Laravel encrypted casts backed by app key or KMS), never logged, never returned by any API.
- **NFR-10 Privacy & GDPR.** Users can export all their data (machine-readable) and delete their account with full cascade/anonymization within 30 days; consent is captured for analytics and marketing separately; a records-of-processing map exists before launch (M5).
- **NFR-11 Data minimization.** Only post data needed for the product is stored; raw fetched payloads are pruned on a retention schedule (default 90 days) except assets backing live places.
- **NFR-12 Cost controls — quotas.** Per-user analysis quotas (default: N shares/day, configurable via FR-58) enforced server-side with clear over-quota UX; remote-model (OpenRouter) usage is rate-limited independently of local (Ollama).
- **NFR-13 Cost controls — tracking.** Every `analysis_runs` row records token counts and computed cost; admin dashboard aggregates cost per model, per user, per day; budget alert thresholds notify admins.
- **NFR-14 Observability.** Structured logs, error tracking, and metrics (queue depth, job failure rate, geocode error rate) with alerting; every share traceable end-to-end by a correlation id.
- **NFR-15 Testability.** External integrations (platform fetchers, Geocoder, AI providers, Stripe) sit behind contracts in `packages/contracts` with fakes, so the full loop is testable without network access.
- **NFR-16 Accessibility & i18n readiness.** Mobile UI meets platform accessibility baselines (labels, contrast, dynamic type); all user-facing strings externalized for future localization.

---

## 5. Implicit Requirements (unstated but mandatory)

- **IR-1 Platform ToS/API constraints.** Each source platform gets a per-platform fetch adapter whose method (official API, oEmbed, user-authorized fetch, manual fallback) is chosen to respect that platform's terms; scraping strategy is a documented, swappable policy per adapter, and the manual fallback (FR-6) guarantees the product works even if a platform blocks all automated fetch. Adapter capability matrix maintained in repo docs.
- **IR-2 DMCA / takedown flow.** Public takedown request channel (form + registered-agent email), counter-notice handling, repeat-infringer policy, and admin tooling (FR-57). Stored third-party media is treated as removable at any time without breaking place entries (FR-30). Phase M2.
- **IR-3 Place deduplication.** Deterministic dedup keyed on Google Place ID when available; otherwise name-similarity + geo-distance heuristic with an admin merge queue for gray-zone matches (FR-24, FR-54). Merges are reversible (split). Phase M2.
- **IR-4 Content moderation.** AI-assisted pre-screen of user-supplied media/captions (NSFW, hate, spam) before public visibility, plus user reporting (FR-52) and admin actions (FR-53). Public-map content defaults to hidden until it passes screening. Phase M3 for public surfaces.
- **IR-5 Visit-attribution integrity (anti-fraud).** Redemption validation requires venue-side confirmation (staff scan/code entry — FR-45); server enforces one redemption per user/offer, device fingerprint + geolocation plausibility checks, velocity limits per venue and per influencer, and payout holdback windows; anomalies route to admin review (FR-55). Ledger corrections are append-only. Phase M4.
- **IR-6 App-store review constraints.** As a UGC app, the iOS build must ship: content reporting, user blocking, moderation with timely response (Apple Guideline 1.2), account deletion in-app (Guideline 5.1.1(v)), and sign-in parity rules if third-party login is offered (Sign in with Apple). These are launch blockers, phase M3–M5.
- **IR-7 Multi-language captions.** The analysis pipeline must handle non-English/mixed-language captions and non-Latin scripts: language detection, extraction prompts that are language-agnostic, and geocoding queries composed from local-language place names (FR-19). Golden set (FR-20) includes non-English cases. Phase M2.
- **IR-8 Legal & payments compliance.** Terms of service, privacy policy, and offer terms templates exist before public launch; Stripe Connect onboarding handles influencer KYC/tax; VAT/receipt responsibilities for offers sit with the restaurant and are stated in owner terms. Phase M4–M5.

---

## 6. Out of Scope for v1 — and Parking Lot

### 6.1 Explicitly out of scope for v1
- Web app / browser client (mobile-only; marketing site is static).
- In-app reservations or booking (offers + redemption only, no table management).
- Payments from diners inside the app (no checkout; redemption is at the venue).
- Ads or sponsored placement of any kind.
- Direct messaging between users.
- Automatic background monitoring of influencer feeds (ingest is user-initiated only).
- Non-restaurant place types (bars/cafés count as restaurants; hotels, shops, attractions do not).
- Android home-screen widgets and iOS widgets/Live Activities.
- Public API for third parties.
- Multi-city offer campaigns, franchises/chains tooling, and agency accounts.

### 6.2 Phase-2/3 parking lot (recorded, not planned)
- Web app with shareable/embeddable public maps (SEO surface for influencer maps).
- In-app booking via TheFork/OpenTable-style integrations.
- Influencer feed auto-sync: index a claimed influencer's entire back catalog automatically.
- Collaborative maps (couples, friend groups, trip planning).
- Itinerary/route mode ("food crawl" ordering of pins).
- Ads / promoted places with strict labeling.
- Widgets (iOS + Android) showing nearby saved places.
- Loyalty layer: repeat-visit rewards on top of redemptions.
- Wearable/CarPlay/Android Auto surfacing of nearby pins.
- White-label city guides for tourism boards.
- User-selectable local models on-device (analysis at the edge).

---

## 7. Traceability Notes for Build Agents

- Every FR maps to at least one milestone task in downstream planning docs; reference FRs by id only.
- Canonical entity names (users, platform_accounts, influencers, source_posts, shares, media_assets, analysis_runs, places, place_sources, tags, follows, offers, redemptions, ledger_entries, payouts, place_claims, reports) are fixed — schema docs must use these exact table names.
- Stack is fixed per shared context: Laravel 13 API (Sanctum, Horizon/Redis, Postgres+PostGIS, Scout+Meilisearch, Filament), Expo React Native (TypeScript, expo-share-intent, react-native-maps, EAS), monorepo `apps/api` / `apps/mobile` / `packages/contracts`, Google Places behind Geocoder contract, Stripe Connect Express, double-entry ledger. Do not substitute technologies without a spec change.
