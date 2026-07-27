# T-099 — Business photo gallery: collect multiple business-owned images + gallery UI

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-084
- **Priority:** ⭐ **do-next override** (owner request 2026-07-26) — worked ahead of the remaining M1 test tasks (T-056/T-058).
- **Target paths:** `apps/api/app/Services/Places/Enrichment/`, `apps/api/app/Services/Geo/`, `apps/api/database/migrations/`, `apps/api/app/Http/Resources/PlaceResource.php`, `packages/contracts/place.json`, `apps/mobile/app/place/[slug].tsx`, `apps/mobile/src/components/place/`, `apps/api/public/demo.html`
- **Spec refs:** builds on T-084 (`tasks/T-084-places-as-businesses.md`), [02-data-model.md](../02-data-model.md), [03-api-design.md](../03-api-design.md)

## Context

T-084 made places first-class businesses and gave them **one** photo: `places.image_url` (detail hero) + `places.thumbnail_url` (map marker), populated through the pluggable `BusinessEnrichmentSource` seam (`app/Services/Places/Enrichment/`). Today only `WebsiteBusinessSource` sets an image (first schema.org JSON-LD `image`); `GoogleBusinessSource` fetches no photos at all; and every surface renders a single hero — there is no gallery.

This task collects **multiple business-owned photos** during enrichment and shows a **gallery when a place has more than one**. The guiding rule (owner): _prefer photos that actually belong to the business._ A business's own **website images (schema.org) are definitively owned** — trust them first. Google is best-effort: the repo uses the **legacy** Google Places API, whose `photos[]` expose only `html_attributions` (the uploader), with **no clean "uploaded by the owner" flag** — so we prefer Google photos whose attribution matches the business, then fill from the top remaining photos to reach a small gallery (owner decision 2026-07-26: _best-effort business-first, then fill_).

> **Owner decisions (AskUserQuestion 2026-07-26):** (1) **prioritize — do next**; (2) Google fallback = **best-effort business-first, then fill** (not strict business-only).

## Implementation steps

1. **Data model.** New migration adding `places.gallery_json` (jsonb, default `[]`) — an ordered list of `{ url, source, attribution }` (`source` ∈ `website|google|reel`, `attribution` nullable). Keep `image_url`/`thumbnail_url` as the hero/marker (derived from `gallery_json[0]` when not human-locked). Add `gallery` to `Place::CURATED_FIELDS` and make it lockable via T-084's `locked_fields` so a manual edit is never clobbered by re-enrichment.
2. **Enrichment seam — emit many images.** Extend `BusinessDetails` (`app/Services/Geo/BusinessDetails.php`) and the `BusinessEnrichmentSource` contract/result to carry `images: list<{url, attribution?}>` instead of a single `image`.
   - **`WebsiteBusinessSource`** — parse **all** schema.org `image` entries (JSON-LD `image` may be a string, an array of strings, or `ImageObject[]` with `.url`), not just the first. These are business-owned → highest priority. Each URL must pass the existing `PublicUrlGuard` (SSRF) before it is kept.
   - **`GoogleBusinessSource`** — add `photos` to the fetched Places Details fields. For each `photos[]` entry build the Places **Photo** URL from its `photo_reference` (+ a `maxwidth`), and capture its `html_attributions`. **Business-first heuristic:** rank photos whose normalized attribution text contains the place's normalized name (or its website domain) ABOVE the rest; keep that ranking so the merge can prefer them. Never fetch photo bytes server-side (store URLs only; the client lazy-loads).
3. **Merge policy** in `BusinessEnricher`: concatenate website images (owned) → business-attributed Google photos → remaining Google photos, **dedup by normalized URL**, cap at `places.enrich.gallery.max_images` (default **8**), and also carry any existing reel-derived thumbnail as a last-resort entry so a place with no crawl still has ≥1. Write `gallery_json`; set `image_url = gallery_json[0].url` when `image_url` isn't human-locked. First-non-empty-wins stays for the scalar fields. Respect `locked_fields`.
4. **Config.** `config/places.php` → `enrich.gallery.enabled` (default true) and `enrich.gallery.max_images` (default 8), plus a `google.photo_maxwidth` (e.g. 1024). Legacy-Places-API note documented in the config comment.
5. **API + contract.** Add `gallery` (array of `{ url, source, attribution }`) to `PlaceResource` and `packages/contracts/place.json`; regenerate the TS types (drift gate must stay green). Keep `image_url`/`thumbnail_url` for back-compat.
6. **Mobile gallery** (`/frontend-design`, MERCADO). In `app/place/[slug].tsx`, when `gallery.length > 1` render a swipeable gallery (horizontal paged `FlatList` with page dots) in place of the single hero; `length <= 1` keeps the current single hero (`image_url` → reel-thumbnail fallback, unchanged). Extract a reusable `PlaceGallery` component under `src/components/place/`. Lazy-load images; graceful fallback on a broken URL.
7. **Web demo** (`apps/api/public/demo.html`) — show a minimal image carousel on the place detail when `gallery.length > 1`; single image unchanged. Keep the existing `cssUrl()`/`safeUrl()` escaping.

## Acceptance criteria

- [ ] Enrichment collects **multiple** business images: all schema.org website images are captured, and Google Places photos are fetched and included, deduped by URL and capped at the configured max.
- [ ] **Business-first ordering** holds: website (owned) images rank first, then Google photos whose attribution matches the business, then the rest as fill — verified by a test with a mixed set.
- [ ] `places.gallery_json` is populated with `{url, source, attribution}`; `image_url` is derived as the first gallery entry when not human-locked; a T-084 manual lock on the image/gallery survives re-enrichment.
- [ ] The place API/contract exposes `gallery`; the contracts drift gate is green.
- [ ] Mobile place detail shows a swipeable gallery with page indicators when `gallery.length > 1`, and the unchanged single hero otherwise; web demo shows a carousel when `> 1`.
- [ ] No server-side photo-byte fetching (URLs only); every website image URL is SSRF-guarded via `PublicUrlGuard`; no Google API key is logged or persisted in `gallery_json`.

## Verification

```bash
cd apps/api
php artisan test tests/Feature/Places tests/Unit/Services/Places
vendor/bin/pint --test && vendor/bin/phpstan analyse
# contracts drift + mobile
cd ../.. && npm run --workspace @reelmap/contracts build && cd apps/mobile && npm run lint && npx tsc --noEmit && npm test
```
Expected: enrichment produces a multi-image `gallery_json` with the documented ordering; the gallery renders on device (verify on the place detail) and in `demo.html`; all gates green.

## Log

**2026-07-27 — IMPLEMENTED (branch `feat/T-099-business-photo-gallery`).** Owner
priority-override task (do-next), started after shipping T-056. All 7 steps done;
backend + mobile + web demo + tests. Gates green: API Pint(540)+PHPStan L6+**Pest
932**; mobile tsc+eslint+**jest 306**; contracts drift clean. Verified the demo
carousel end-to-end in Chrome (seeded a 3-photo gallery → swipe + attribution
pill + count badge render; seed reverted).

- **Data model:** migration `2026_07_27_000000_add_gallery_to_places` adds
  `gallery_json` jsonb default `[]`; `Place` gets it in `$fillable`, `CURATED_FIELDS`
  (lockable), and an `'array'` cast.
- **Seam emits many images:** `BusinessDetails` carries `images:
  list<{url,attribution}>` (round-tripped for the 30-day cache).
  `WebsiteBusinessSource` collects ALL schema.org `image` entries (string | list |
  ImageObject), deduped, tagged `website` (first also stays `image_url` for
  back-compat). `GoogleBusinessSource` maps `$details->images` to `google`-tagged
  entries.
- **Google photos (key-free):** `GooglePlacesGeocoder` BUSINESS_FIELDS += `photos`
  (rides the same Details call, NOT the pipeline DETAILS_FIELDS). **ADR /
  decide-and-document:** each `photo_reference` is resolved by reading the Places
  Photo 302 `Location` header (`allow_redirects=false`) → a key-free
  `googleusercontent` URL is stored; we NEVER fetch image bytes, and a resolved
  URL still containing `key=` or a non-3xx response is dropped. Chose this over a
  keyed proxy (no open-proxy key/billing-abuse surface, no new route; URL rot
  self-heals on re-enrichment). `html_attributions` → tag-stripped, entity-decoded
  text. **Legacy Places API has no owner flag → business match is a name/domain
  heuristic; ADR note: new Places API v1 `authorAttributions` would improve owner
  detection.**
- **Merge policy** (`GalleryBuilder`, new): stable rank website(0) →
  business-attributed-Google(1, attribution folded-contains place name OR website
  domain label) → other-Google(2) → reel(3); dedup by normalized URL; cap
  `enrich.gallery.max_images` (8); reel fallback = `place->thumbnail_url` so a
  crawl-less place keeps ≥1. `BusinessEnricher` UNION-merges `gallery_json` across
  sources (pulled out of the per-field first-non-empty-wins), derives
  `image_url = gallery[0]`; both go through `PlaceEditor` so a locked
  gallery/hero survives. Gated by `enrich.gallery.enabled` (off ⇒ single-image
  T-084 behaviour).
- **API/contract:** `PlaceResource.gallery` (normalized, defends malformed rows);
  `place.json` gains a required `gallery` array of `{url, source(enum), attribution}`;
  TS regenerated (drift green).
- **Mobile:** `PlaceGallery` (paged FlatList, terracotta page dots, Google
  photo-credit scrim, reuses `Thumbnail` for graceful fallback, matches the 190px
  hero card); `[slug].tsx` shows it when `gallery.length > 1`, else the single
  hero. `PlaceGalleryImage` type added to `src/api/places.ts` (still hand-defined,
  not contract-derived).
- **Web demo:** CSS scroll-snap carousel + count badge + credit pill when
  `gallery.length > 1`; `::after` texture suppressed over real photos; keeps the
  existing `cssUrl()`/`safeUrl()`/`esc()` escaping.

**KEY:** `UrlCanonicalizer`-style network concern — `pinnedIp`/SSRF is unchanged;
website images still run through `PublicUrlGuard`. **DEFERRED:** admin gallery
reorder/curation UI in Filament (out of scope); new-Places-API-v1 migration for
reliable owner photos (ADR). **ON MERGE:** flip tasks.json T-099 → done.

## Gotchas

- **Legacy Places API has no owner flag.** `html_attributions` names the *uploader*, not "the business." The name/domain match is a heuristic — document it, and note in an ADR that migrating `GoogleBusinessSource` to the **new Places API v1** (`photos[].authorAttributions`) would let us identify owner photos more reliably. Do not claim strict business-only for Google.
- **Photo URLs, not bytes.** The Places Photo endpoint 302-redirects to a `googleusercontent` URL and requires the API key as a query param. Store the URL the client can load; never bake the key into a persisted, user-served field without care (prefer the redirect-following resolved URL, or a keyed proxy) — decide and document, and keep the key out of `gallery_json`/logs.
- **SSRF.** Website images run through `PublicUrlGuard` (added in T-084) exactly like the single image did; keep that guard on every website-sourced URL.
- **Billing.** Adding `photos` to the Details field mask is part of the same Details call (no extra call) — but keep it on the wider, opt-in `BUSINESS_FIELDS` mask, not the pipeline's billing-sensitive `DETAILS_FIELDS`.
- **locked_fields.** Re-enrichment must not overwrite a human-curated gallery/hero; thread `gallery`/`image_url` through the T-084 lock check.
- **Dedup.** Google often returns near-duplicate crops; dedup by normalized URL at minimum (and consider the `photo_reference`), so the gallery isn't the same shot ×5.
