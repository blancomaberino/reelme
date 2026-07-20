# T-082 — Multi-source review aggregator (Google + native + Trustpilot/others)

- **Phase:** M2 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-059 (native reviews + Google cache), T-081 (Google reviews viewer/link)
- **Target paths:** `apps/api/app/Services/Reviews/` (new), `apps/api/app/Models/Place.php`, `apps/api/app/Http/Resources/PlaceResource.php`, `apps/api/config/reviews.php`, `apps/mobile/app/place/[slug].tsx`, `apps/mobile/src/components/place/`, `apps/api/public/demo.html`
- **Spec refs:** `04-analysis-pipeline.md`, `03-api-design.md#places`

## Context

Requested 2026-07-17: aggregate reviews so users can read them from multiple
sources (Google, Trustpilot, etc.) in one place.

**Two review sources already exist** — this task generalizes them into a
pluggable aggregator and adds external providers:

- **Cached Google snippets** — `google_reviews_json` + `rating.google` (T-059,
  surfaced by T-081), refreshed under the 30-day ToS window (T-080).
- **Native Reelmap reviews** — `Review` model (`place_id, user_id, rating 1–5,
  body`), aggregated to `reviews_count` / `reviews_avg_rating`, moderated (T-059),
  exposed as `rating.app` + `?include=reviews`.

The new work is the **abstraction** + at least one external provider (Trustpilot).

### Grounding

- Follow the existing provider-registry pattern the codebase already uses for
  `SourceAdapter` (contract + registry) and `Geocoder` (interface + Google/
  Nominatim/Fake drivers) — same shape, ToS-compliant per-provider caching,
  never-throws, config-gated.
- Provider **id resolution** differs per source: Google keys on `google_place_id`;
  Trustpilot on the business domain derived from `places.website`; native is
  intrinsic. A provider with no resolvable id for a place is simply omitted.

## Implementation

- **`ReviewSource` contract** → `summary(Place): ?ReviewSourceSummary`
  `{ source, rating, count, url (deep link), synced_at, snippets[] }`.
- **Registry + drivers:** `google` (wrap the existing cache), `native` (wrap the
  Reelmap aggregate), `trustpilot` (new fetch + ToS-compliant cache, keyed on the
  website domain, SSRF-safe, never throws). Each driver config-gated in
  `config/reviews.php`.
- **Aggregate accessor** on `Place` returning the non-null summaries.
- **API:** `PlaceResource` exposes `review_sources[]` (contract schema + TS regen,
  drift green). Keep the existing `rating.google` / `rating.app` / `google_reviews`
  for back-compat or migrate clients deliberately.
- **UI:** place detail shows a per-source summary row (label, rating, count, "read
  on <source>" deep link, last-synced + attribution) on mobile + web; a failed/
  missing provider just doesn't appear.

## Acceptance criteria

- [x] `ReviewSource` abstraction + registry with drivers `google`, `native`,
      `trustpilot`, each yielding `{source, rating, count, url, synced_at, snippets}`
- [x] Place detail shows a per-source rating summary with label, deep link, last
      synced, and attribution; providers with no resolvable id are omitted (no
      empty rows) — mobile + web
- [x] Each external provider caches ToS-compliantly (own window), is SSRF-safe, and
      never throws; one provider failing degrades gracefully to the others
- [x] `PlaceResource` exposes `review_sources[]`; contract schema + TS regen, drift green
- [x] Tests (`Http::fake`/fixtures per provider): aggregation shape, provider-failure
      isolation, cache/ToS window, missing-id omission, mobile+web render; gates green

## Verification

```bash
cd apps/api
php artisan test --filter=Review
vendor/bin/pint --test && vendor/bin/phpstan analyse
cd ../mobile && npx tsc --noEmit && npx jest place
```

Manual: a place with `google_place_id` + a website domain shows Google, native,
and Trustpilot summaries; killing the Trustpilot fake drops only that row.

## Gotchas

- **ToS per provider** — Google review content caching is capped (~30 days, already
  enforced by T-080); Trustpilot has its own terms. Cache windows are per-driver,
  never one global TTL.
- Don't fetch external providers inline on the request — cache + refresh out of
  band (like the Google flow); the resource reads cached summaries.
