# T-022 — Geocoder contract + Google Places implementation

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-011
- **Target paths:** `apps/api/app/Services/Geo/`
- **Spec refs:** [04-analysis-pipeline.md#resolveplace](../04-analysis-pipeline.md#6-resolveplace-stage), [07-risks-decisions.md#adr](../07-risks-decisions.md#adr)

## Context

With the M1 core migrations in place (T-011), the pipeline needs a provider-agnostic geocoding seam before `ResolvePlace` (T-023) can turn an extracted place name into coordinates and a `google_place_id` (the primary dedup key). This task ships the `Geocoder` contract, the Google Places implementation, a test double, and response caching so Places API spend stays bounded. It is independent of the AI tasks and can proceed in parallel with T-020/T-021. App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

1. **Contract + DTOs** in `apps/api/app/Services/Geo/` (spec uses namespace `App\Geo` for the interface sketch; keep the class names, place them under `App\Services\Geo` to match the target path):
   - `Geocoder` interface: `findPlace(string $name, GeoHints $hints): ?GeocodeResult`.
   - `GeoHints` (readonly DTO): address parts (street/city/region/postal_code/country), optional lat/lng bias, language (from `post.language`).
   - `GeocodeResult` (readonly DTO): `google_place_id`, `canonical_name`, `formatted_address`, `address_components` (array), `lat`, `lng`, `types` (array), `score` (0–1).
2. **`GooglePlacesGeocoder`**: two-step call with Laravel `Http`:
   - *Find Place from Text* (`input = name + city/country hint`, `inputtype=textquery`, `fields=place_id`, optional `locationbias=point:lat,lng` from hints, `language` from hints).
   - *Place Details* on the returned `place_id` with a strict **field mask**: `place_id,name,formatted_address,address_component,geometry/location,type` — nothing more (billing is per-field-tier).
   - Compute `score` 0–1 from result quality: no candidates → return `null`; single candidate with matching locality → high; use name similarity to the query as a component. Keep the heuristic in one small pure method so T-023 tests can target it.
   - API key via `config('services.google_places.key')` (`GOOGLE_PLACES_API_KEY`), errors: 4xx/5xx or `status != OK/ZERO_RESULTS` → throw `GeocodeFailed` (transient, retryable); `ZERO_RESULTS` → `null`.
3. **Caching**: wrap `findPlace` results (including `null`) in `Cache::remember` for 30 days, keyed by normalized `(name, city, country)` — lowercase, unaccented, whitespace-collapsed (e.g. `geocode:` . sha1 of the normalized triple). Cache the serialized `GeocodeResult`, not the raw HTTP response.
4. **`FakeGeocoder`** (the null/test double; task text calls it NullGeocoder — name the class `FakeGeocoder` per 04 §6): in-memory map of name → `GeocodeResult` seeded per test, records calls, returns `null` by default. Bind via a `GeoServiceProvider`: `Geocoder::class` → `GooglePlacesGeocoder` in production, swap in tests with `app->instance()`.
5. **Fixture tests** (Pest, `Http::fake()` with recorded JSON fixtures under `tests/Fixtures/google-places/`): find-place + details happy path mapping into `GeocodeResult`; `ZERO_RESULTS` → null; API error → `GeocodeFailed`; field mask present on the details request (assert via `Http::assertSent`); cache hit skips HTTP on second identical call; normalization makes `"Café São"` and `"cafe sao"` share a cache key; `FakeGeocoder` seeding works.

## Acceptance criteria

- [ ] `Geocoder` interface exists with `findPlace(name, GeoHints): ?GeocodeResult` returning `google_place_id`, canonical name, formatted address, address components, lat/lng, types, and a 0–1 `score`.
- [ ] `GooglePlacesGeocoder` implements it via Find Place + Place Details with an explicit field mask and location/language bias from hints.
- [ ] `FakeGeocoder` test double is bindable in place of the real implementation and used by all pipeline tests.
- [ ] Results (including misses) are cached 30 days keyed by normalized `(name, city, country)`; a repeat lookup makes zero HTTP calls.
- [ ] Fixture-based tests cover mapping, zero-results, error, caching, and normalization — no live Google calls in CI.

## Verification

```bash
cd apps/api
php artisan test --filter=Geocoder
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: green with `Http::preventStrayRequests()` active. Optional live smoke (needs `GOOGLE_PLACES_API_KEY`): `php artisan tinker` → `app(Geocoder::class)->findPlace('Lanzhou Noodle House', new GeoHints(city: 'Lisbon', country: 'PT'))` returns a result with a `ChIJ…` place id.

## Gotchas

- **Google Places billing**: Place Details without a field mask bills the maximum SKU tier for every call. Always send the minimal field list; assert it in tests so a refactor can't silently drop it. The 30-day cache is also a cost control, not just latency — never bypass it in the pipeline path.
- Google's ToS restricts long-term storage of most Places content but explicitly allows storing `place_id` indefinitely — we persist `place_id` + coordinates on our own `places` rows; don't cache/store full Details payloads beyond the 30-day cache.
- `findPlace` receives names in native script (spec: ResolvePlace passes `place.name` as-is); pass the `language` hint through and don't transliterate.
- Distinguish `ZERO_RESULTS` (legit miss → `null`, cacheable) from quota/`OVER_QUERY_LIMIT`/network errors (throw, retryable, **never cache**).
- Cache `null` misses too, but under the same 30-day TTL — otherwise a trending-but-unmatchable name hammers the API on every share.
- The low-score threshold (`score < 0.5` → review) is enforced by `ResolvePlace` (T-023), not here — the geocoder just reports the score honestly.
