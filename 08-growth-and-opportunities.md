# 08 — Growth & Opportunity Review

**Doc id:** 08-growth-and-opportunities
**Date:** 2026-09-03
**Status:** Advisory. Proposes decisions and tasks; does not amend 00–07 until the owner accepts an item (then record it as an ADR in `07-risks-decisions.md`).
**Method:** six independent specialist agents (market/competitive, growth loops, creator-side virality, product/business model, engagement & retention, platform leverage) reviewed the specs and the code with no knowledge of each other's findings. Convergence between them is noted, because independent convergence is the strongest signal in this document.

---

## 1. Three launch blockers

These are not feature ideas. Each one makes something the plan already claims to do impossible in the first market.

### B1 — Stripe does not operate in Uruguay (found independently by 3 of 6 agents; verified)

`06-monetization.md` §4.3 makes Stripe Connect Express the only payout rail and **EUR the only currency**. Uruguay is absent from [Stripe's supported countries](https://stripe.com/global) — in LatAm only Brazil and Mexico are listed — and [cross-border Connect payouts](https://docs.stripe.com/connect/cross-border-payouts) reach recipients only in the US, UK, EEA, Canada and Switzerland. Uruguay was also not in the February 2026 Global Payouts expansion (which added Costa Rica, Dominican Republic, Guatemala, Peru).

Consequences as specified:

- A Montevideo restaurant **cannot be charged**.
- A Montevideo creator **cannot be paid** — so the escrow hook ("you have €212 waiting") is a promise that cannot be honoured, and the 12-month sweep of that escrow to `platform_revenue` (§5.3) would be taking money accrued to people who were never able to claim it.
- Every M4 exit criterion passed against a **fake** Stripe. The money loop is green in tests and unshippable in the launch market.

**Owner decision (2026-09-03): Mercado Pago / MercadoLibre is the rail, deferred to a later phase.** Feature work comes first; the money rail is not on the critical path until there is money to move. Until then the ledger is the record of truth and the first creators are paid by hand against a ledger export.

**Decision proposed:**

1. Extract a `PayoutRail` contract (the `Geocoder`/`SourceAdapter` pattern already in the codebase) and ship a **`ManualPayoutRail`** for v1: the ledger stays the record of truth, the first ~20 creators are paid by bank transfer against a ledger export. At 1,000 users nobody needs an automated payout run, and this deletes KYC, tax and escrow-expiry from the launch surface.
2. Make the ledger **multi-currency and UYU-native**. The app already formats UYU; the ledger insisting on EUR is a straight contradiction.
3. **Disable the 12-month escrow sweep** (relates to T-153). Money quietly expiring back to the platform is the detail that turns a critic into a screenshot. It is a rounding error at this scale.
4. Real rail, when there is money to move: **Mercado Pago Split Payments** (owner's choice — OAuth-connected sellers + `application_fee`, the LatAm Connect analogue). dLocal for Platforms, headquartered in Montevideo, is the fallback if Mercado Pago's split terms don't fit. Do not build either until a gate in §5 demands it.

### B2 — Every share link the product creates is a dead end

Verified in code: `apps/mobile/app.config.ts` declares `scheme: 'reelmap'` and has **no `ios.associatedDomains`** and no Android https intent filter. `resources/views/list-share.blade.php` emits `reelmap://list/{slug}`, which does nothing in WhatsApp's in-app browser and nothing at all for a person without the app. There are no OG/Twitter meta tags and no OG image, so a list pasted into WhatsApp renders as grey nothing. There is no image-card generation on either side (no `react-native-view-shot`, no Browsershot).

So: lists, public slugs, invites, and public influencer maps are all built — and none of them can currently travel. In a WhatsApp-first market this is the difference between having distribution and having none.

### B3 — There is no retrieval moment

The product's own pitch names the saved-posts graveyard, then rebuilds it with better geometry. Storage is built; **retrieval is not**.

- The map opens on the **last viewport you left** (`apps/mobile/app/(main)/map.tsx`, `resolveInitialRegion`), not on you.
- The pin sheet shows name, city, influencer handle and up to four tags — **no distance, no open/closed, no reason you saved it** (`src/components/map/place-sheet.tsx`).
- `PlaceListingRequest` accepts `recent` and `popular` only. **There is no distance sort anywhere in the API.**
- Hours are stored as prose and the mobile parser documents why "open now" is not derivable from them (`apps/mobile/src/lib/opening-hours.ts`; this is what T-155 is about, and the answer is yes).
- Signing up makes the map *emptier*: guests browse the public map, authed users get `filter=mine`, and there is **no empty state on the map screen at all**.

The Friday-8pm answer today is "pan, tap, tap, read, back out, tap again" — the same cognitive cost as scrolling Instagram saves, plus taps.

### B4 — Nothing is measured

Sentry only, on both sides. **Zero product analytics events.** No referral attribution (`Invitation` has no code and no `accepted_at`; `users` has no `referred_by`). `DashboardMetrics` returns `views => null` and says so in a comment. Every gate in §5 is unmeasurable until this exists.

**Owner decision (2026-09-03): GA4, scheduled second-to-last** — ahead of nothing but the final queue item. Consequence to accept knowingly: until it ships, the §5 gates are judged by hand (ledger rows, DB counts, Filament) rather than measured, and referral attribution stays impossible. The event list below still stands — same ten events, GA4 as the sink — and the consent toggle separating analytics from marketing (NFR-10) ships *with* it, not after.

---

## 2. The strategic reframe

### The ingestion mechanic is already commoditized

At least nine shipped apps already do share-reel-to-map (FR-1 → FR-21): **Plotline** (~30k users, 2M places), Stasht, Triply, ReelsToMap, JoySpot, SpotFetch, Via, Mio, Albo. **None of them do influencer attribution or creator payouts.**

M1/M2 is therefore parity work, not differentiation. What is defensible:

| Asset | Why it holds |
|---|---|
| The attribution ledger + signed venue relationships | Instagram will never tell a restaurant "pay this creator €1.50 because a diner walked in" |
| Money accrued to named creators | A prospect list with a number attached; no competitor can replicate it retroactively |
| The **dish-level corpus** (name, price, shown-in-video, freshness) | Google has reviews; nobody has what the dish looked like and cost last month |
| Creator payouts in a market with none | TikTok Creator Rewards covers only Brazil and Mexico in LatAm. A Montevideo food creator's platform payout is **zero**. Reelmap competes with zero, not with a creator fund. |

Note the last one is **market-specific** — it evaporates in Spain. That is an argument for LatAm-first, not a reason to hurry to Europe.

### The two external threats, correctly ranked

1. **Agentic local search (near-certain, already happening).** ChatGPT began completing restaurant bookings in-conversation via Yelp/Resy/OpenTable in August 2026. Yelp and Resy have **no Uruguayan inventory**. A consumer map app is exposed by this; a structured, attributed, hours-accurate place graph is what those assistants are starving for. This is the one positioning that *benefits* from the trend.
2. **Meta shipping save-to-map (~35%, 18 months).** Instagram Map (Aug 2025) is a Snapchat clone — it does not save places. Meta's 2026 roadmap points at shoppable Reels, not cartography. If it ships, M1/M2 goes to zero and the ledger, the venues and the corpus survive.

### Positioning angles worth taking

- **"The only place a LatAm food creator gets paid."** A creator-payments company whose UI happens to be a map. This inverts the cold start: recruit creators with balances first, they bring audiences.
- **Travel, not local.** Residents know where to eat. Uruguay had 3.6M visitors in 2025 spending USD 2.04bn; **Punta del Este alone: 877k visitors, USD 919M, ~USD 1,227 per tourist**, 1.3M of them arriving December–February. Same code, TAM jumps from a city to a country.
- **Sell the attribution to venues, not the app to diners.** Restaurants already pay creators blind. "Which of the 14 creators we comped produced seated covers" is sellable at 20 venues, before diner scale.
- **Be the agent-readable supply for LatAm** — a read-only structured/MCP surface over the place graph.

**Market sizing, honestly:** ~250–500 realistically claimable Montevideo venues. Bull case at maturity ≈ €30k/month gross, ~€14k/month platform net, over 2–3 years. That is a good solo-founder business and a bad venture business. Small-market *density* is a genuine advantage (map liquidity at ~200 pins, both sides hand-recruitable). Small-market *ceiling* is the trap — do not let it justify another 18 months of build.

---

## 3. The ranked backlog

Effort assumes the current AI build capacity. "Seam" names what it plugs into, because almost none of this is new infrastructure.

### Wave 0 — Unblock (do before anything else)

| # | Item | Effort | Seam |
|---|---|---|---|
| 0.1 | `PayoutRail` contract + `ManualPayoutRail`; ledger multi-currency/UYU; disable escrow sweep | M | `LedgerService`, `PayoutRunner` |
| 0.2 | Universal Links + App Links + server-rendered OG tags and OG image on `/l/{slug}`, `/p/{slug}`, `/c/{handle}`; deferred-link install fallback | M | `app.config.ts`, `routes/web.php` |
| ~~0.3~~ | ~~Product analytics~~ — **moved to the end of the queue by owner decision.** GA4, 10 events, mobile + server twin, consent toggle separate from marketing (NFR-10) | S–M | new `POST /events` |
| 0.4 | Machine-readable opening hours + `places.timezone` → real `open_now` (T-155 = yes) | M | enrichment pipeline |

Events to ship in 0.3: `share_submitted`, `share_published{duration_ms, platform, confidence}`, `place_saved`, `place_opened{source}`, `artifact_shared{type,target}`, `link_opened{artifact,has_app}`, `install_attributed{referrer_slug}`, `offer_activated`, `redemption_verified`, `influencer_claim_{started,completed}`.
**North star:** weekly verified redemptions. **Leading proxy until money moves:** weekly active pinners.

### Wave 1 — The front door (the retention half)

| # | Item | Effort | Seam |
|---|---|---|---|
| 1.1 | **"Cerca de mí, abierto ahora"** — `distance_m` + `open_now` on the map-pin payload, both surfaced on the pin sheet, distance sort in `PlaceListingRequest`, Nearby toggle on the map | M | `ST_DWithin`/`ST_Distance` already there |
| 1.2 | **Tonight / "Comé esto hoy"** — one surface, three cards: open now × near you × matches your saves, reel autoplaying, swipe to reject | M | 1.1 + `PlaceQueryBuilder` + gallery |
| 1.3 | **First session** — public map for the zero-pin user, a real empty state, 3 starter saves, pre-permission screen for location | S | `filter=null` is already supported |
| 1.4 | **Review screen: one confirm** — promote `usePublishBestGuess` to the primary action; collapse 30+ fields (23 vibe chips, 10 dietary chips) behind "Añadir detalles"; add "Guardar así" | S | already built, just not surfaced |
| 1.5 | **Been-there** state (FR-28, unbuilt) + want-to-go → been prompt, in-app only | S | place states |
| 1.6 | Notification preferences screen (FR-39 mandates per-category opt-out; settings has none) | S | — |

1.1 + 1.2 are the single highest-value pair in this document. Without a Friday-8pm reason to open the app, every euro downstream is zero.

### Wave 2 — Distribution (the growth half)

| # | Item | Effort | Seam |
|---|---|---|---|
| 2.1 | **Public web surface**: `reelmap.app/@handle` creator map, `/p/{slug}` place page, city map — server-rendered, es-first, SEO-indexable, "open in app" CTA | M | every read route is already public and unauthenticated; reuse the existing renderer, do not build a second map |
| 2.2 | **`/c/{handle}` escrow claim page** — their map already lit, "Tenés €X reservados", per-post earnings, one button to the existing `bio_code` claim. Server-rendered with a real OG image | M | `LedgerService::escrowBalance()` exists |
| 2.3 | **Fan-relay share** — on any unclaimed creator profile: "@leo tiene €212 sin reclamar — avisale" → native share sheet, prefilled. A human fan sends the DM | S | share sheet |
| 2.4 | **Story map card** on `share_published` — 9:16 PNG, the pin on a styled map, "vía @leo", handle watermark | M | needs `react-native-view-shot` + `expo-sharing`; `react-native-svg` already a dep |
| 2.5 | **Map reveal MP4** on claim — 9:16, 12s, animated pin drop, ends on "47 lugares · 1.284 personas comieron ahí · @handle" | M | server-side render |
| 2.6 | **WhatsApp-first list share** — rich preview (map thumb, name, "14 lugares · @maya"), recipient sees "Guardar copia" (`POST /me/lists/{slug}/copy` is built) | S after 0.2 | — |
| 2.7 | **Earnings receipt card** — per-redemption: "Alguien comió en X con tu video. +€1,50", one tap to a shareable card | S after 2.4 | — |
| 2.8 | **Restaurant table-tent QR + IG story sticker kit** on claim verification | S | — |

### Wave 3 — Revenue and leverage

| # | Item | Effort | Seam |
|---|---|---|---|
| 3.1 | **Venue Radar** — free claimed page listing every creator video mentioning the venue + reach + weekly "3 new reels" alert; paid tier adds analytics + offers. **The only thing sellable on day one, with zero diners.** | M | `place_sources`, `influencers`, claims |
| 3.2 | **Dishes table + pgvector** — promote dishes out of `extraction_snapshot_json` into `dishes(place_id, name, price, currency, shown_in_video, language, source_id)` + embeddings, at the `PublishShare` → `TagMaterializer` seam | M | one migration + one job |
| 3.3 | **Conversational query planner** — NL → `{tags, price, bbox, followed}` → existing scopes. `ModelRouter` already gives schema-constrained output, cost caps and per-user budgets | S–M | `ModelRouter` + `PlaceQueryBuilder` |
| 3.4 | **Receipt-photo redemption** — diner photographs the ticket, the existing vision pipeline OCRs venue/date/total, server matches the issued code. Keeps QR as the venue-preferred path | M | `PrepareMedia` + `ModelRouter` |
| 3.5 | **Trip mode** — city switch or country-change detection → my lists for that city + public creator maps + offline pack | M | T-062/063 lists, T-110, T-103 |
| 3.6 | **Group decision** ("¿Dónde comemos?") — shortlist → web vote link → winner pins for everyone. Works for non-users | M | public lists + 0.2 |
| 3.7 | Premium consumer tier gated on the existing quota machinery (`SpendTracker` is already the meter) | S | — |
| 3.8 | Vertical swap (bars, cafés, shops) — **two files**: `resources/prompts/extraction.system.md` and the category/tag enums in `extraction.schema.json`. DB, ledger, offers and map need no change | S | — |

**3.2 is the highest-leverage single build in the codebase.** Dishes are the highest-signal, most differentiated data the pipeline already extracts, and they are currently unqueryable. One migration converts into: semantic search, price-aware ranking for 1.2, a retrieval target for 3.3, the long-tail SEO surface for 2.1 ("best milanesa napolitana in Montevideo"), and the only B2B asset here that Google does not already sell.

### Also latent, no feature exposes it

Transcripts are written by `TranscribeAudio` and **read by zero resources or Filament classes**. Meilisearch `_geo` is filterable *and* sortable but `SearchService` sends text-only queries with no filters and no facets. `users.favorite_topics/favorite_foods` are collected and echoed back with no consumer. The `followedBy` scope powers one map filter and no ranking. The payment-discount graph is real, wired, and has no product story.

---

## 4. Viral features, ranked by expected contribution

The only free distribution this product has is back into Instagram Stories, TikTok and WhatsApp. Rank accordingly.

1. **Universal Links + OG cards** (0.2) — the multiplier on everything below. Today the conversion is structurally zero.
2. **`/c/{handle}` claim page + fan-relay** (2.2, 2.3) — the strongest asset owned, needing no new money logic. Every creator who claims arrives with an audience.
3. **Map reveal MP4** (2.5) — the only feature that turns claiming into content someone else sees. Must be video, not an image: an image gets a Story, a video gets a post.
4. **Story map card on publish** (2.4) — credits the creator, which is why creators reshare it.
5. **WhatsApp list share** (2.6) — the list *is* the message ("dónde comer en Pocitos").
6. **Earnings receipt** (2.7) — small amounts get screenshotted *more* than big ones.
7. **Restaurant table-tent + story sticker** (2.8) — the venue markets you to its own followers, free.
8. **City creator leaderboard** — rank-flex posts and rivalry stitches; publish top 20, not top 3. After density.
9. **Collab lists / map compare** — duet-friendly, highest-distribution native format, but L effort.
10. **Wrapped** — December only, needs ≥20 pins/user, otherwise anti-viral. Defer.

### Content the creator actually posts (write these, don't leave them to chance)

- **"Un boliche me acaba de pagar por un video que subí en marzo."** On-screen: `VIDEO DE MARZO. PLATA DE HOY.` → cut to balance → map reveal. First frame is a claim, not a product.
- **`47 lugares que te recomendé en 2 años. Todos en un mapa.`** — no talking, pin-drop animation, high completion because people watch to see if their barrio appears.
- **"Nadie me avisó que esto existía y tenía guita ahí adentro."** — unedited screen-record of the claim flow. This is the one that gets stitched.

### The link-in-bio slot is winnable — under conditions

`reelmap.app/@handle` can be the default bio link for food creators in a city if it: loads under 1s on 4G, shows the map above the fold with no interaction, works without the app, filters by barrio/tipo/precio, links each pin **back to their original video**, gives them one slot they control, and carries their handle in the URL and their name larger than the logo. It must **never** gate the map behind an install, run a signup interstitial, show other creators' pins, or sell ads on it. The day a creator feels the page farms their audience, they pull the link and say why.

### Anti-patterns — do not ship

- **Never automate DMs, comments or follows** on Instagram/TikTok. Instant platform action, and it endangers the oEmbed path (R-01).
- No contact-book upload for bulk invites (Apple 5.1.1(i) + GDPR). Keep the typed-email invite shape.
- No share-to-unlock, no invite-gated features, no incentivized reviews (Apple 1.1.6 / 3.2.2).
- Never pay diners per redemption — it converts the anti-fraud surface into a business model.
- Do not re-host Instagram/TikTok video in a Story card. Own map + text attribution only (IR-2).
- **Streaks are a dark pattern here** — eating out is not a daily behaviour; a streak punishes people for cooking at home.
- Do not ship wrapped, leaderboards or badges before density.

### The unclaimed-profile risk, stated plainly

Auto-generating a profile, a map and a public page under someone's name is scraping-shaped, and at least one loud creator will read it that way. Assume the post happens; design so the reply is one screenshot:

- The unclaimed page shows **attribution, not personification** — "Lugares que aparecieron en videos de @handle, compartidos por usuarios de Reelmap". No fabricated bio, no avatar until claimed.
- The escrow accrues *before* they hear from anyone. "We already set aside your half" is the strongest available answer.
- **One-click removal**, honoured in 24h, no retention flow — keeps the pins, strips the attribution.
- Every pin deep-links out to their original post. Send traffic, don't take it.

---

## 5. The 90-day plan

The window ends in **early December — the start of the Punta del Este season** (877k visitors, ~USD 1,227 each, 1.3M arrivals Dec–Feb). That is the forcing function: seed Montevideo now, launch into the season.

**Weeks 1–2 — Prove the extraction, not the app.**
Wire the OpenRouter key (the standing finding is that the local model cannot carry menu OCR). Seed 300–500 Montevideo places yourself, concentrated in **Pocitos + Punta Carretas** (~2 km², contiguous, highest reel and card density). Target ~150–250 published places inside that bbox so any pan at z15 shows 8–12 pins; below that the map looks broken. Score against the golden set (FR-20).
**Gate:** ≥70% of shares publish a correct, geocoded, dish-bearing place with no human edit. Miss → fix extraction; nothing downstream matters.

**Weeks 2–4 — Wave 0 + deploy for real.**
Finish T-055 against real infrastructure (the restore drill log is still empty) and T-054's human-only items. Ship Universal Links, OG, analytics, hours.
**Gate:** a list pasted into WhatsApp renders a card and opens in the app for an installed user.

**Weeks 3–6 — Wave 1 + the public web surface.**
Ship Cerca-de-mí, Tonight, first session, the review-screen cut. Ship `reelmap.app` city map and ~40 creator pages (the seeding in weeks 1–2 builds these for free).
**Gate:** 500 organic web sessions/month, and ≥5 creators sharing their own map link unprompted.

**Weeks 4–6 — Demand test without an app.**
A WhatsApp number: "send me a reel, I send you the pin", thin adapter over `POST /shares`. Post in 5 Montevideo food groups.
**Gate:** 100 senders, ≥30% send a second link within 14 days. Under 20% and the capture habit does not exist — drop ingestion-first positioning and lead with discovery + the agent.

**Weeks 5–8 — Creators, then venues, in that order.**
Seed each candidate creator's map *before* they see it — an empty map converts nobody. Rank by back-catalogue density, not followers; 5k–60k is the band that replies. Reach them through **restaurants** (owners have every local creator's phone number, and it is the only channel with zero platform-ToS exposure) and through the business email they published themselves. One Thursday dinner for 12 creators at a partner venue, claiming together on their phones.
Then 30 venue doors with a printed page: *"18 creators have posted you. 340 people have you saved. You pay only when someone walks in."*
**Gate:** 20 claimed creators; 10 paying venues at ≥US$25/month. Under 5 venues → restaurants are not revenue #1; pivot to the B2B data play.

**Weeks 8–12 — Reduced-surface store launch + first live offers.**
Clear the wave-3/4 security tasks first — **T-117 (venue takeover) and T-137 (deep-link auto-submit) are launch blockers**.
**Day-90 gate:** 1,000 registered / 300 WAU / D30 ≥25% / ≥US$400 MRR or ≥50 verified visits. Two of four → continue. Fewer → this is a discovery tool, not a marketplace: kill the offer program, sell subscriptions and data.

---

## 6. What to cut

| Cut | Why |
|---|---|
| Stripe Connect + EUR as the v1 rail | Cannot run in the first market (§1 B1) |
| Escrow expiry sweep | Reputationally expensive, financially trivial |
| **The Feed tab at launch** | An empty social feed with zero users teaches people the app is empty. Ship Map / Tonight / Lists / Share / Profile |
| X + YouTube adapters | Already default-disabled. Instagram + TikTok are ~95% of LatAm food video; every extra adapter is permanent maintenance for near-zero volume |
| Further work on FR-13 model picker, 2FA | Built. Invest zero more |
| "Web app out of scope" (00-spec §6.1) | **Reverse this decision.** The web map is the distribution engine and the only compounding channel a solo founder can afford |
| Widgets parked in 05-mobile §6.2 | **Reverse this too** — a home-screen widget showing the 2 nearest saved places open now is the cheapest habit trigger available |
| Background geofencing | Defer behind Wave 1. Background location on a zero-user app is an App Store risk, a battery cost and a trust cost |
| Streaks, badges, wrapped | Before density they are anti-viral; streaks are a dark pattern in this category regardless |

---

## 7. Constraints to keep in view

1. **Platform fetch fragility (R-01, High/High).** The default posture is Instagram-only; the primary path is an undocumented keyless oEmbed endpoint plus a `yt-dlp` binary. The mitigation is already in the codebase and unused: `InstagramGraphAdapter` works against a user-linked OAuth token. Promote account-linking from optional to a first-run prompt — every linked user converts a scraped fetch into a sanctioned one.
2. **Google Places licensing caps the B2B play.** Provider content is cached 30 days, reviews capped at 5. You cannot resell a corpus whose identifiers and hours came from Google. The mitigation is already half-built: `website_source`/`phone_source` provenance columns exist — extend provenance to every field and a Google-free subset becomes derivable by query. `NominatimGeocoder` is the other half.
3. **AI cost vs. self-hosted capacity.** Caps are $0.10/run and $0.50/user/day; the economics assume Ollama absorbs volume free, with OpenRouter as paid fallback, and the GPU box is a single point of failure (R-09). Cheapest instrument: a `fallback_rate` alarm on `analysis_runs.error LIKE 'fallback:ollama_unreachable%'`. That ratio is the leading indicator of margin going negative and it costs one query.

---

## 8. Decisions needed from the owner

1. **Payout rail** — accept `ManualPayoutRail` + UYU ledger for v1, or invest now in dLocal/Mercado Pago?
2. **Reverse the no-web-app decision?** Five of six agents independently made the public web surface their top or second recommendation.
3. **First market shape** — Montevideo year-round, or Montevideo seeding with a Punta del Este December launch?
4. **First revenue line** — venue subscription (sellable at zero diners) or pay-per-redemption (the built rail, needs scale)?
5. **Cut the Feed tab from the launch surface?**
6. Do these become `T-156+` tasks in `tasks/tasks.json`, and in which order?

---

## 9. Owner's product model (2026-09-03) — and what it decides

The owner clarified the intended product in his own words. It sharpens the priorities above rather than replacing them, and it settles four design questions.

### 9.1 What the map is for

**A map of places I want to visit.** The pin's primary state is **want-to-go**; been-there is the completion tap, not the point. Sources are plural: a reel, *and a friend telling you about a place over dinner*. The retrieval query, in the owner's words:

> "I have a specific zone — I can see all the restaurants in that neighborhood, and pick depending on what I want to eat. I want pasta. OK, I have these five restaurants that offer pasta. Is there one available tonight? It's 11pm — is it already closed?"

Four consequences:

1. **Wave 1 is confirmed and now precise.** Zone → dish → open now → pick. That is exactly "Cerca de mí, abierto ahora" + Tonight.
2. **The dishes table (3.2) is promoted into a Wave 1 dependency.** "Five places near here that do pasta" cannot be answered by `cuisine_primary` (which yields `italian`) or by the 23 vibe chips. The pipeline already extracts dish names and prices per place and buries them in `place_sources.extraction_snapshot_json`, unqueryable. The owner's own example query is the argument for the migration.
3. **Reels are one input, not the product.** "A friend told me about it" is FR-27 manual add, already built. This lowers exposure to the platform-fetch risk (R-01, the highest-rated in `07-risks-decisions.md`) and gives the empty map a second fill route.
4. **Been-there stays** — as the completion of want-to-go and as the ranking signal Tonight needs, not as the headline state.

### 9.2 The empty first session — the owner's answer

Two fills, both better than the current blank map:

- **Curated / preset maps** ("Pastas en Montevideo") that a user adds to their own maps.
- **People to follow**, whose maps you can then see.

### 9.3 Design decisions taken (2026-09-03)

| # | Decision | Consequence for the build |
|---|---|---|
| D1 | **Curated maps are a live subscription**, not a copy | Following a map means the curator's later additions appear on the follower's map; unfollowing removes them. Pins stay credited to the source map. This is *not* the existing `POST /me/lists/{slug}/copy` (a snapshot) — it is a new `list_subscriptions` relation, and it makes preset maps an asset that keeps paying after it ships. |
| D2 | **Subscribed pins are a toggleable layer** | The user's own want-to-go pins are always the map's default. Subscribed maps appear as toggles in the filter bar, so adding a 60-pin curated map never buries the 12 places you actually chose. |
| D3 | **Find-friends: all three non-Instagram routes** | Instagram cannot supply this — Basic Display reached end-of-life 2024-12-04 and the Graph API exposes only a professional account's own data, with no friend-graph permission for third parties since 2018. Build instead: (a) **invite links** through the share sheet, the zero-privacy-cost default, dependent on the Universal Links work; (b) **opt-in phone-contacts matching** — highest hit rate in a WhatsApp market, and an Apple 5.1.1(i) + GDPR surface requiring explicit consent, hashed matching, no silent upload, and deletion; (c) **suggest creators from your own shares** — derivable today with no permission at all, and it fills the follow list on day one. |
| D4 | **Paid placement is a flat monthly "Destacado"** | A fixed monthly price to sit at the top of one neighborhood or one cuisine, visibly labeled. Sold by hand, a flag in Filament, invoiced by bank transfer — **it needs no payment rail at all to start**, which fits the deferred Mercado Pago decision, and it works with zero diners, no QR scanner and no staff training. |

**The guardrail on D4, held firmly:** paid placement lives in browse and discovery lists, always labeled, and **never reorders the "open now, near me" answer**. That one query is the trust the whole app rests on; selling it is the one move that cannot be undone.

D4 also reorders §4 of this document: **Destacado, not pay-per-redemption, is the first revenue line**, and the built redemption rail becomes the performance add-on for venues that already see volume.

