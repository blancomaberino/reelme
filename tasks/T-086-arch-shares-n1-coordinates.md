# T-086 — ARCH/P0: eliminate GET /shares N+1 (Place::coordinates per-place query)

- **Phase:** ARCH (P0 performance/correctness) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-016 (shares API)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/ShareController.php`,
  `apps/api/app/Http/Resources/ShareResource.php`, `apps/api/app/Models/Place.php`

## Context (audit finding, 2026-07-21)

`ShareController::index()` eager-loads `publishedPlaceSource.place` /
`publishedPlaceSources.place` correctly, **but** `ShareResource::placeCoords()`
(ShareResource.php:135) calls `Place::coordinates()`, which runs an unconditional
`DB::selectOne('SELECT ST_Y(location::geometry) AS lat, ST_X(...) FROM places WHERE id = ?')`
(Place.php:758-761) — one query **per place per share**. A page of 25 shares with 1–3 places
each = 25–75 extra synchronous round-trips. The model already has the right pattern two lines
away (`selectRaw('ST_Y(...) AS lat, ST_X(...) AS lng')`, Place.php:516), used by
`MapViewport` / `PlaceSummaryResource`.

## Implementation

- In `ShareController::index()`'s eager-load, select the geometry inline on the nested place
  relation, e.g. `with(['publishedPlaceSource.place' => fn ($q) => $q->select('places.*')
  ->selectRaw('ST_Y(location::geometry) AS lat, ST_X(location::geometry) AS lng')])` (and the
  multi-place relation).
- Change `ShareResource::placeCoords()` to prefer the hydrated `lat`/`lng` attributes; keep
  `Place::coordinates()` only for genuine single-place callers.

## Acceptance criteria

- [ ] A page of N multi-place shares issues a bounded, constant number of queries (no per-place
      SELECT) — assert via `DB::listen`/a query-count assertion in the test
- [ ] `/shares` response body (coords) is byte-identical to before
- [ ] Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit. Confirmed against source.
