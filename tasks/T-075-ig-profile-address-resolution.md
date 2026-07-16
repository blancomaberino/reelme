# T-075 — Resolve a venue's address/coords from its Instagram profile

- **Phase:** M2 (analysis pipeline) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-013 (resolve/geocode pipeline), #82 (`InstagramApiResolver` cookie plumbing), #93 (extraction v7 poster≠venue)
- **Target paths:** `apps/api/app/Services/Places/PlaceResolver.php`, `apps/api/app/Services/Media/Instagram/InstagramWebClient.php` (new, extracted), `apps/api/app/Services/Places/InstagramProfileLocator.php` (new), `packages/contracts/extraction.schema.json` (+ generated TS), `apps/api/resources/prompts/extraction.system.md`, `apps/api/config/ingestion.php`
- **Spec refs:** `04-analysis-pipeline.md`, `03-api-design.md`

## Context

Requested 2026-07-16. When a venue can't be geocoded (Google/Nominatim find
nothing) **but the caption names it by an `@handle`**, visit that Instagram
profile and mine it for a location, instead of parking the share in review.

**Verified live** (same cookie'd IG web API `InstagramApiResolver` uses, profile
endpoint `GET /api/v1/users/web_profile_info/?username={handle}` + `x-ig-app-id`)
against the real failing case `@lagranburgerok`:

- `is_professional_account: true`, but **`business_address_json` was empty** (no
  street/city/zip, **no lat/lng**) — a small venue that never set its business
  address. So the structured-address path yields nothing *here*.
- **`biography: "…🥩 Burger de asado 📍Barros Blancos 🛵 Delivery…"`** — a real
  locality signal (`📍Barros Blancos`).
- `external_url: https://wa.me/…` (WhatsApp, not a site with an address).
- (`full_name` is always present — the account's real display name, e.g. "La
  Gran Burger" — a bonus **name** upgrade over a bare `@handle`.)

**Set expectations honestly — this is a multi-signal fallback, not a magic
lookup.** Value by account type:

| Signal | When present | Payoff |
| --- | --- | --- |
| `business_address_json` (street/city/zip **+ latitude/longitude**) | full IG *business* accounts with a location set | **best** — direct coords, geocoding solved, no provider call |
| `biography` (📍 / address text) | very common (e.g. @lagranburgerok) | a locality / address to enrich the geocode query |
| `external_url` → website | common | a site often carrying an address / Google Maps link |
| `full_name` | always | the real venue **name** (fixes handle-as-name) |

### Already-available (don't re-build)

- **`InstagramApiResolver`** already does the hard parts: Netscape cookie parse,
  `x-ig-app-id`, `allow_redirects=false` (an expired-cookie 302→/login must not
  be followed), `Http::timeout`, never-throws, 4xx = "refresh the cookie" signal.
  Extract that into a shared `InstagramWebClient` and add a `profile(handle)` call
  beside the existing `media(pk)` one — do NOT duplicate the cookie/SSRF plumbing.
- **`PlaceResolver::resolveOne`** already has the exact hook point: it returns
  `ResolutionOutcome::geocodeFailed()` when `geocoder->findPlace()` misses or
  scores below `places.geocode.min_score`. The fallback slots in right there.
- The **geocoder** (`Geocoder::findPlace(name, hints)` → `GeocodeResult` with a
  `google_place_id`) is the dedup key source — prefer re-running it with an
  *enriched* query over dropping a raw coordinate with no id.

## Implementation

- **Capture the venue's handle.** Add `places[].handle` (the venue's IG handle,
  sans `@`) to `extraction.schema.json`; bump the prompt **v7 → v8** to fill it
  from the caption/transcript `@mention` for THAT venue (per-venue in multi-place
  posts — the prompt already groups dishes per `@handle`). Regenerate the TS +
  keep the contract drift test green. (Caption `@mention` regex is a weaker
  fallback when the model omits it, but the schema field is what disambiguates
  multi-venue posts.)
- **`InstagramProfileLocator`** — given a handle, fetch the profile via
  `InstagramWebClient` and derive a location in priority order:
  1. `business_address_json` → `{lat,lng,street,city,zip}` (direct).
  2. `biography` → parse a 📍/address/locality line.
  3. `external_url` → (optionally) the site's address / embedded Maps link.
  Returns a normalized hint (name from `full_name`, plus address/coords) or null.
- **Wire into `PlaceResolver::resolveOne`:** before returning `geocodeFailed()`,
  if the place has a `handle`, run the locator and **re-run `findPlace` with the
  enriched query** (`full_name` + profile city/address) so a `google_place_id` /
  dedup key is still obtained; only fall back to raw `business_address_json`
  coords (a `pending` place with no `google_place_id`) when the geocoder still
  misses. Use `full_name` as the venue `name` when extraction only had the handle.
- **Guardrails:** reuse the `INGESTION_IG_*` cookie config; never-throws (a dead
  profile fetch just leaves the original `geocode_failed`); cache the profile per
  handle within a resolve run (a roundup can mention the same handle twice); one
  extra fetch only on the *failure* path, so the happy path is unchanged.

## Acceptance criteria

- [ ] `extraction.schema.json` gains `places[].handle`; prompt **v8** fills it
      per venue from the caption `@mention`; TS regen + contract drift green
- [ ] Shared `InstagramWebClient` (extracted from `InstagramApiResolver`) exposes
      `profile(handle)`; `InstagramApiResolver` reuses it (no duplicated
      cookie/header/redirect plumbing); both never throw
- [ ] `PlaceResolver`: a place that would be `geocode_failed` **with** a handle is
      re-resolved from its IG profile — business-address coords resolve it
      directly; a bio locality enriches the query and geocodes; `full_name`
      upgrades a bare-`@handle` name
- [ ] A profile with no usable signal (empty address, no locality, dead url) still
      ends as `geocode_failed` (no invented location); no-cookie / 4xx / timeout
      fall through gracefully (cookie-refresh signal logged, not fatal)
- [ ] Only the failure path fetches a profile; per-handle cached within a run
- [ ] Tests (`Http::fake`): business-address→resolved-with-coords;
      bio-locality→geocode-retry-succeeds; no-signal→still geocode_failed;
      no-cookie/4xx→graceful; `full_name` name upgrade; a `ResolvePlace` pipeline
      test end-to-end
- [ ] Gates green: API Pint / PHPStan L6 / Pest

## Progress

- **2026-07-16** — Opened. Endpoint + payload **verified live** against
  `@lagranburgerok` (professional acct: empty `business_address_json`, bio
  `📍Barros Blancos`, `full_name` present) — confirms the mechanism and the
  realistic "multi-signal, not magic" scope. Grounded on the existing
  `InstagramApiResolver` plumbing and the `PlaceResolver::resolveOne`
  `geocodeFailed()` hook. Pairs with #93 (poster≠venue) and T-074 (video/audio).
