# T-080 — Refresh a place's Google data on re-share when the cached copy is stale

- **Phase:** M2 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-023 (ResolvePlace/dedup), T-059 (Google review cache + `reelmap:google:refresh-stale`)
- **Target paths:** `apps/api/app/Jobs/ResolvePlace.php`, `apps/api/app/Services/Places/PlaceResolver.php`, `apps/api/app/Console/Commands/RefreshStaleGoogleReviews.php`, `apps/api/app/Services/Places/` (new shared refresher), `apps/api/config/places.php`
- **Spec refs:** `04-analysis-pipeline.md#6`, `03-api-design.md#places`

## Context

Requested 2026-07-17. When a reel/post for a place we **already have** comes in
again, we should still pull fresh Google data for that place if the last fetch
was more than X days ago — the venue's rating, reviews (and ideally hours) drift
over time, and a re-share is a natural, free trigger to refresh.

### Grounding (what exists today)

- **Google data is already fetched + cached.** `GooglePlacesGeocoder` (legacy
  Places API) returns `rating, ratingCount, reviews[]` (≤5), `googlePlaceId`.
  Stored on `places`: `google_rating`, `google_rating_count`, `google_reviews_json`,
  and the **staleness clock `google_reviews_synced_at`**.
- **A daily cron already refreshes-or-drops.** `RefreshStaleGoogleReviews`
  (`reelmap:google:refresh-stale --days=30`) re-fetches every place whose
  `google_reviews_synced_at` is null/older than the window and, per Google ToS,
  **drops** the cached content when a re-fetch yields nothing. This is the exact
  refresh-or-drop logic to reuse — don't duplicate it.
- **Two gaps this task closes:**
  1. `ResolvePlace::run()` early-returns (`PlaceSource::where('share_id',…)->exists()`)
     for an already-resolved share, so a re-share does **no** Google work.
  2. `PlaceResolver::backfillGoogleSignal()` only fills when `google_rating` is
     **null** — it never refreshes a place that already has (stale) data.

## Implementation

- **Extract** the refresh-or-drop body of `RefreshStaleGoogleReviews::handle()`
  into a shared service (e.g. `GooglePlaceRefresher::refresh(Place): void`) —
  same "trust only a same-`google_place_id` result carrying rating + reviews,
  else drop" semantics, never throws. The command calls it in its chunk loop.
- **On-demand trigger:** when ingesting a post that resolves to an **existing**
  place, if `google_reviews_synced_at` is older than
  `places.google.refresh_after_days` (config, default 30, ToS-capped), invoke the
  refresher. Wire it either at the `ResolvePlace` early-return point (before it
  no-ops on an already-processed share) or by broadening `backfillGoogleSignal`'s
  null-guard to `null-or-stale`. A place synced within the window triggers **no**
  extra Google call (happy path unchanged).
- Keep it best-effort: a refresh failure must not fail ingestion.

### Optional stretch (note, don't force)

Widen `GooglePlacesGeocoder::DETAILS_FIELDS` to also refresh `opening_hours` /
`phone` so "info about the place" broadens beyond reviews. The field mask is
asserted in a dedicated test ("never widened casually") — update that assertion
deliberately if you do this, and account for the added quota cost.

## Acceptance criteria

- [ ] Refresh-or-drop logic lives in one shared service used by **both** the daily
      command and the on-demand path (no duplicated fetch/ToS-drop code)
- [ ] A re-shared/already-processed post whose place is stale
      (`google_reviews_synced_at` older than the config window) refreshes its
      Google data; a fresh place triggers no extra Google call
- [ ] `backfillGoogleSignal` (or the resolve hook) covers the stale case, not just
      null; ToS drop-on-miss preserved; only a same-`google_place_id` result with
      rating + reviews is trusted
- [ ] Refresh never throws (keyless/Nominatim dev, place-not-found, API error);
      ingestion still succeeds
- [ ] Tests: stale existing place on re-share → refreshed; fresh place → no fetch;
      re-fetch miss → cached signal dropped; shared service exercised by both cron
      and on-demand tests; Pint/PHPStan L6/Pest green

## Verification

```bash
cd apps/api
php artisan test --filter='GooglePlaceRefresh|RefreshStaleGoogleReviews|ResolvePlace'
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

Manual: seed a place with `google_reviews_synced_at` 40 days ago; re-ingest a
post that dedups to it (Http::fake the Places response) → data + `synced_at`
refresh. Seed one synced today → no Places call is made.
