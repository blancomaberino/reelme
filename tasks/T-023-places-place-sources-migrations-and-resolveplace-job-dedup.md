# T-023 — Places + place_sources migrations and ResolvePlace job (dedup)

- **Phase:** M1 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-021, T-022
- **Target paths:** `apps/api/database/migrations/`, `apps/api/app/Jobs/ResolvePlace.php`, `apps/api/app/Services/Places/PlaceResolver.php`
- **Spec refs:** [02-data-model.md#places](../02-data-model.md#38-places), [04-analysis-pipeline.md#resolveplace](../04-analysis-pipeline.md#6-resolveplace-stage)

## Context

Extraction (T-021) now produces a validated `result_json` and the `Geocoder` contract (T-022) resolves names to `google_place_id` + coordinates. This task creates the last two core tables — `places` (the deduplicated map pins, PostGIS) and `place_sources` (the provenance/attribution backbone) — plus the deferred `shares.published_place_source_id` FK, and implements the `ResolvePlace` job around a testable `PlaceResolver` service running the dedup decision tree. It unlocks publish (T-024) and everything in M2. App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

1. **Migrations** (three files, in order, exactly per 02-data-model — extensions `postgis`, `pg_trgm`, `unaccent`, `citext` already enabled in M0):
   - `create_places_table`: all columns from §3.8 including `location geography(Point,4326) NOT NULL`, `google_place_id`, `normalized_name`, `status` (`PlaceStatus` CHECK), self-FK `merged_into_place_id` (SET NULL), counters. Indexes: **GIST(`location`)**, unique(`slug`), partial unique(`google_place_id`) where not null, trigram GIN(`normalized_name`), index(`status`), index(`country_code`,`city`), index(`merged_into_place_id`). Use raw `DB::statement` for the geography column, GIST, and GIN indexes.
   - `create_place_sources_table`: per §3.9 with `extraction_snapshot_json jsonb NOT NULL`, unique(`place_id`,`share_id`), unique(`share_id`), partial unique(`place_id`) where `is_primary = true`.
   - `add_published_place_source_id_to_shares`: nullable FK → place_sources (SET NULL) — this breaks the `shares ⇄ place_sources` circular dependency (02 §6).
2. **Models + factories**: `Place` (casts; `normalized_name` maintained in a model observer: lowercase → `unaccent` → strip legal suffixes/punctuation; `slug` = name + short hash), `PlaceSource`. Factory for `Place` must write a real PostGIS point (`ST_MakePoint(lng, lat)::geography` via a raw expression or a `Point` cast helper).
3. **`PlaceResolver` service** (`App\Services\Places\PlaceResolver`) — pure, injectable, returns a `ResolutionOutcome` (attached place / created place / ambiguous with candidates / geocode_failed):
   1. Geocode via `Geocoder::findPlace(extraction place.name, GeoHints from address/geo/language)`.
   2. Take `Cache::lock('resolve:' . md5($googlePlaceId ?? $name.$city), 30)` around steps 3–5.
   3. `google_place_id` exact match on `places` → attach.
   4. No id match, geocode succeeded → candidate scan + scoring:
      ```sql
      SELECT * FROM places
      WHERE ST_DWithin(location::geography, ST_MakePoint(:lng,:lat)::geography, 75)
        AND status IN ('pending','active')
      ```
      Name similarity per candidate = `max(pg_trgm similarity(), normalized-token Jaro-Winkler)` on accent-folded, lowercased names. Then: exactly one best candidate with distance <75 m **and** similarity ≥0.85 → attach (backfill `google_place_id` if the row lacks one); **multiple** candidates ≥0.85 within 75 m → ambiguous; otherwise → create.
   5. Create path: new `places` row `status: pending` with geocode data (name, address parts, `country_code`, location point, `google_place_id`) + attach.
   6. Geocode `null` or `score < 0.5` → `geocode_failed` outcome.
   - "Attach" = create `place_sources` row (place, source_post, share, analysis_run, `extraction_snapshot_json` = the extracted-place payload, `is_primary` true iff first source for the place). Rely on unique(`place_id`,`source_post_id`-equivalent: `place_id`,`share_id`) for idempotency (upsert/ignore-on-conflict).
4. **`ResolvePlace` job** (`queue: resolve`, tries 3, backoff `[10, 60, 300]`, timeout 60s): stage-contract guards (expected status, idempotent — existing `place_sources` row for this share → skip). Map outcomes: attach/create → continue chain to `PublishShare`; ambiguous → share `review`, `review_reason: ambiguous_place`, candidate list persisted on the share for the picker UI; geocode failed → share `review`, `review_reason: geocode_failed`. `failed()` hook → share `failed`, `failure_code: geocode_failed` or `resolve_conflict`.
5. **Merging support** (`PlaceResolver::merge(Place $b, Place $a)` or a dedicated `PlaceMerger`): rehome `place_sources` (`UPDATE … SET place_id = A WHERE place_id = B`, on unique conflict keep A's row); set B `status: merged`, `merged_into_place_id = A` (single hop: if A is itself merged, follow to the terminal place first); recompute A's counters, backfill A's nulls from B. Filament UI comes in T-035 — this task ships the service only.
6. **Tests** (Pest + Postgres/PostGIS, `FakeGeocoder`), one per branch of the tree: place_id exact match attaches; <75 m + ≥0.85 fuzzy match attaches and backfills place_id; two ≥0.85 candidates → `review`/`ambiguous_place` with candidates stored; no candidate → creates `pending` place with a real geography point; geocode null and score <0.5 → `review`/`geocode_failed`; concurrent double-dispatch creates one place (lock + unique constraints); merge rehomes sources, handles the duplicate-share conflict, and follows single-hop rule.

## Acceptance criteria

- [ ] `places` and `place_sources` tables match 02-data-model §3.8–3.9 exactly (geography(Point,4326) + GIST, trigram GIN on `normalized_name`, partial uniques), plus the separate `shares.published_place_source_id` migration.
- [ ] Dedup decision tree implemented per 04 §6: google_place_id exact match → geo+name fuzzy (<75 m and ≥0.85) → create `pending` place; multiple ≥0.85 candidates route the share to `review` with `review_reason: ambiguous_place`; geocode null/score <0.5 → `review` with `geocode_failed`.
- [ ] Resolution runs under `Cache::lock('resolve:…', 30)` and is idempotent via unique(`place_id`,`share_id`); re-running attaches nothing twice and creates no duplicate places.
- [ ] Merging support: `merged_into_place_id` set, `place_sources` (and future dependents) rehomed with conflict handling, counters recomputed, single-hop chain rule enforced.
- [ ] Pest tests cover every branch of the tree plus concurrency and merge, using `FakeGeocoder` — no network.

## Verification

```bash
cd apps/api
php artisan migrate:fresh --env=testing
php artisan test --filter=ResolvePlace
php artisan test --filter=PlaceResolver
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: green. Sanity SQL: `SELECT name, ST_AsText(location::geometry) FROM places;` shows real points; `EXPLAIN` on the ST_DWithin query uses the GIST index.

## Gotchas

- **PostGIS distance in meters requires the `geography` cast.** `ST_DWithin` on `geometry` in SRID 4326 measures degrees — 75 would be ~8,000 km. The column is already `geography(Point,4326)`; keep every comparison in geography space (`::geography` on the made point) and never let an ORM helper silently cast to geometry.
- `ST_MakePoint` takes **(lng, lat)** — reversed arguments put restaurants in the ocean and are the classic PostGIS bug; add a test with known coordinates asserting distance.
- Threshold discrepancy between docs: 02-data-model §4 describes 0.65/0.40 bands; 04-analysis-pipeline §6 (and this task's acceptance) says **<75 m and ≥0.85 similarity** — implement 04's values, but put them in `config/places.php` so tuning doesn't require code changes.
- `pg_trgm similarity()` runs in SQL; Jaro-Winkler runs in PHP — compute trigram scores in the candidate query and take the max in PHP. Accent-fold with the same `unaccent` logic used for `normalized_name` or the two scores disagree.
- `SQLite` cannot run any of this — tests must use the Postgres+PostGIS service (same as CI, per T-006). Don't add an sqlite fallback path.
- `Cache::lock` needs the Redis cache store; the lock key must be identical for two concurrent shares of the same place (hence keying on `google_place_id ?? name+city`, not `share_id`).
- The ambiguous-candidates payload stored on the share is consumed by the mobile picker (T-026) — shape it as an array of `{place_id, name, distance_m, similarity, lat, lng, address}` and keep it stable.
