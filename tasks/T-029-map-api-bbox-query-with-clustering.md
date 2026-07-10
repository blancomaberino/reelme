# T-029 — Map API: bbox query with clustering

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023
- **Target paths:** `apps/api/app/Http/Controllers/MapController.php`
- **Spec refs:** [../03-api-design.md#map](../03-api-design.md) (§2.7, §3.3), [../02-data-model.md#places](../02-data-model.md) (§3.8)

## Context

M1 ends with published `places` rows carrying a PostGIS `geography(Point,4326)` `location` with a GIST index (T-023). This task adds the read path that powers the mobile Map tab: `GET /api/v1/map/places` returning server-clustered pins for a viewport bbox. It is the performance-critical endpoint of M2 (exit criterion: clusters at city zoom in <300ms p95 with 10k seeded places) and unblocks T-032 (mobile Map screen). App code lives in the separate app repo created by T-001; controller goes under `App\Http\Controllers\Api\V1` per the API conventions (the tasks.json path is shorthand).

## Implementation steps

1. **Route + controller.** Add `GET /map/places` to the `v1` group in `apps/api/routes/api.php` → `App\Http\Controllers\Api\V1\MapController@places`. Public auth; apply the map rate limiter (120/min per user/IP) with `X-RateLimit-*` headers.
2. **FormRequest validation** (`MapPlacesRequest`): `bbox` required, `minLng,minLat,maxLng,maxLat` floats with lat ∈ [-90,90], lng ∈ [-180,180], `minLat < maxLat`; `zoom` required integer 1–20; optional `tags[]` (slugs), `cuisine`, `price_range` (1–4), `filter` in `all|following|mine` (default `all`; `following`/`mine` require Sanctum auth, 401 otherwise). Reject `minLng > maxLng` (antimeridian) with `validation_failed` for now — see Gotchas.
3. **Base query.** Only `status = 'active'` places (resolve merged places by exclusion — `merged` never appears). Bbox filter via envelope overlap so the GIST index is used:

```sql
SELECT ... FROM places
WHERE status = 'active'
  AND location && ST_MakeEnvelope(:minLng, :minLat, :maxLng, :maxLat, 4326)::geography
```

   (`&&` on geography uses the GIST index; `ST_Within(location::geometry, ST_MakeEnvelope(...))` is the exactness check — with an axis-aligned envelope the `&&` result is already exact, but keep `ST_Within` in the query if you cast to geometry.)
4. **Filters.** `cuisine` → `cuisine_primary = ?`; `price_range` → equality; `tags[]` → `whereHas('tags', fn($q) => $q->whereIn('slug', $tags))` — the `tags`/`place_tag` tables ship in T-031; guard this filter behind `Schema::hasTable('place_tag')` (validated, no-op otherwise) and enable its test once T-031 lands. `filter=following` is a **stub** in M2: validated, requires auth, returns an empty pin set with `meta.filter: "following"` until T-037; `filter=mine` limits to places having a `place_sources.share` owned by the current user.
5. **Clustering by zoom.** Per spec: at `zoom >= 15` return raw pins only, no clusters. Below 15, server-side grid clustering with `ST_SnapToGrid` on the geometry cast, cell size derived from zoom (`cell = 360 / (2^zoom * k)`, tune `k≈2–4` so cells ≈ 60–80 px):

```sql
WITH in_bbox AS (
  SELECT id, name, location::geometry AS geom
  FROM places
  WHERE status = 'active'
    AND location && ST_MakeEnvelope(:minLng,:minLat,:maxLng,:maxLat,4326)::geography
  /* + filters */
)
SELECT ST_SnapToGrid(geom, :cell) AS cell,
       count(*)                    AS count,
       ST_Y(ST_Centroid(ST_Collect(geom))) AS lat,
       ST_X(ST_Centroid(ST_Collect(geom))) AS lng,
       ST_Extent(geom)             AS expand_bbox
FROM in_bbox GROUP BY cell
```

   Cells with `count = 1` are emitted as pins (join back for pin attributes); cells with `count > 1` become clusters with `cluster_id = "{zoom}:{cellX}:{cellY}"` (`cellX = floor(lng/cell)`, `cellY = floor(lat/cell)`) and `expand.bbox` from `ST_Extent`.
6. **Response shape** — exactly §3.3 of 03-api-design.md: `data.pins[]` (`type:"place"`, `id`, `name`, `lat`, `lng`, `tags`, `source_count` (= `shares_count`), `has_active_offer` (hardcode `false` until M4), `top_influencer` from the `is_primary` place_source's influencer) and `data.clusters[]` (`type:"cluster"`, `cluster_id`, `lat`, `lng`, `count`, `expand.bbox`); `meta: {zoom, total_in_bbox, clustered}`. Cap pins at 300 per response; when capped set `meta.truncated: true` (mobile shows "zoom in for more").
7. **Eager loading.** Pins need tags + primary influencer: `with(['tags', 'primarySource.sourcePost.influencer'])` — no N+1 (assert with `Model::preventLazyLoading()` in tests).
8. **10k-place seeder.** `database/seeders/MapPerformanceSeeder.php`: 10,000 `Place::factory()` rows, `status=active`, points distributed over a metro bbox (e.g. greater Lisbon `-9.30,38.60,-8.90,38.85`) with ~20 gaussian hotspots so clustering is realistic; batch-insert (chunks of 1,000, `DB::table('places')->insert`) with `ST_SetSRID(ST_MakePoint(lng,lat),4326)` raw expressions so it seeds in seconds. Register so `php artisan db:seed --class=MapPerformanceSeeder` works.
9. **Tests.** Pest feature tests: happy path pin + cluster shapes at zoom 12 vs 16; each filter; auth denial for `following`/`mine`; validation error shape; rate-limit headers present. Performance smoke test (group `perf`): seed 10k, warm once, run 20 requests at city zoom, assert p95 < 300ms (skip on CI runners flagged slow via env, but keep runnable locally: `php artisan test --group=perf`).

## Acceptance criteria

- [ ] `GET /api/v1/map/places?bbox=minLng,minLat,maxLng,maxLat&zoom=` returns `{data: {pins, clusters}, meta: {zoom, total_in_bbox, clustered}}` matching §3.3 exactly
- [ ] At `zoom >= 15` the response contains pins only (`clusters` empty, `meta.clustered: false`); below 15, grid clusters via `ST_SnapToGrid` with zoom-derived cell size
- [ ] Cluster objects carry `cluster_id` (`zoom:x:y`), centroid `lat`/`lng`, `count`, and `expand.bbox` covering their members
- [ ] Bbox filtering uses the GIST index (verified with `EXPLAIN` in a test or documented query plan) — no seq scan at 10k rows
- [ ] Filters work: `cuisine`, `price_range`, `tags[]` (no-op guard until T-031 tables exist), `filter=mine`; `filter=following` is a validated auth-required stub returning empty
- [ ] Merged (`status=merged`) and `pending`/`hidden` places never appear
- [ ] Pin payload includes `tags`, `source_count`, `has_active_offer` (false in M2), `top_influencer`
- [ ] Responses capped at 300 pins with `meta.truncated: true` when capped
- [ ] `MapPerformanceSeeder` seeds 10,000 active places with realistic hotspots in < 60s
- [ ] p95 latency < 300ms at city zoom with 10k seeded places (perf smoke test, runnable via `--group=perf`)
- [ ] Feature tests cover happy path, validation errors (error envelope with `code: validation_failed`), authz for following/mine, and rate-limit headers

## Verification

```bash
cd apps/api
php artisan migrate:fresh --seed
php artisan db:seed --class=MapPerformanceSeeder
php artisan test --filter=MapPlaces          # feature tests green
php artisan test --group=perf                # prints p95, asserts < 300ms
curl -s "http://localhost:8000/api/v1/map/places?bbox=-9.20,38.69,-9.10,38.75&zoom=13" | jq '.data.clusters[0], .meta'
curl -s "http://localhost:8000/api/v1/map/places?bbox=-9.20,38.69,-9.10,38.75&zoom=16" | jq '.data.clusters | length'   # → 0
curl -s "http://localhost:8000/api/v1/map/places?bbox=-9.20,38.69,-9.10,38.75&zoom=13&filter=following"                  # → 401 without token
```

Expected: zoom 13 returns clusters + pins with `meta.total_in_bbox` ≈ hotspot density; zoom 16 returns pins only; pint/phpstan clean.

## Gotchas

- **Antimeridian:** a bbox crossing 180° arrives as `minLng > maxLng`; `ST_MakeEnvelope` would silently produce a wrapped envelope. M2 explicitly rejects it (422) — document in the controller; if ever needed, split into two envelopes and union.
- **geography vs geometry:** `ST_SnapToGrid` only works on geometry — cast `location::geometry` inside the CTE, but keep the bbox `&&` predicate on the geography column so the existing GIST index is hit. A `::geometry` cast in the WHERE clause bypasses the index unless you add a functional index.
- **Cell size at high latitude:** degree-based grid cells shrink visually toward the poles; acceptable for a restaurant app but pick cell size from zoom, not from bbox width, so panning at constant zoom keeps stable cluster ids.
- **`top_influencer` N+1:** loading the primary place_source's influencer per pin is the classic storm — eager-load, and consider a denormalized subquery select if the perf test fails.
- **Seeder speed:** `Place::factory()->create()` one-by-one with model events + Meilisearch sync (after T-031) takes minutes and pollutes the search index — use bulk inserts and `Place::withoutSyncingToSearch()` (or seed before Scout is configured).
- **tags[] before T-031:** the pivot tables don't exist yet if this task runs first; keep the filter behind a schema guard and a skipped test, flip it on in T-031's PR if needed.
