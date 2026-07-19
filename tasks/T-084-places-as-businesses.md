# T-084 — Places as first-class businesses: enrich, manual edit, place picture

- **Phase:** M2 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-030 (places API), T-035 (Filament places), T-059 (Google review cache), T-080 (GooglePlaceRefresher), T-082 (review aggregator), T-065 (place enrichments)
- **Target paths:** `apps/api/app/Services/Places/` (new business enricher), `apps/api/app/Filament/Resources/Places/`, `apps/api/database/migrations/`, `apps/api/app/Models/Place.php`, `apps/api/app/Http/Resources/PlaceResource.php` + `PlaceSummaryResource.php`, `packages/contracts/place-summary.json` (+ generated TS), `apps/mobile/src/components/map/`, `apps/mobile/app/place/[slug].tsx`, `apps/api/public/demo.html`
- **Spec refs:** `03-api-design.md#places`, `04-analysis-pipeline.md`, `05-mobile-app.md`, `02-data-model.md`

## Context

Requested 2026-07-19 by the owner. Today a place exists only as a by-product of a
shared post/reel: its data comes from the extraction pipeline. The owner wants a
place to be a **first-class business entity** that can be curated independently —
enriched from external sources on demand, edited by hand, and given a display
picture for the map. Three related deliverables (may ship as slices).

## Deliverables

### 1. Enrich-as-business (independent of a share)
A place-level **"Enrich as business"** action (Filament, optionally an admin API)
that processes a place **without** a reel/post and populates its fields from:
- the **web** — the business's own site / **menu**,
- **Google** — GMB / Places (name, address, hours, phone, website, rating, reviews),
- **reviews** — via the T-082 aggregator.

Constraints: **reuse** `GooglePlaceRefresher` (T-080) and the review aggregator
(T-082) rather than duplicating; **never throws**; **config-gated**; each external
call is **ToS-compliant + SSRF-safe** and **cached** per source (own window).

### 2. Manual editability
Places become **admin-editable** in Filament (name, address, cuisine, hours,
phone, website, picture, …) with an **audit trail**. Manual overrides win: a later
enrichment (or a re-share's resolve) must **not clobber** a field a human set —
model this with per-field precedence / locked fields.

### 3. Place picture (map marker image + detail image)
Add **`image_url`** and **`thumbnail_url`** to `places` (migration + model +
`PlaceResource`/`PlaceSummaryResource` + `place-summary.json` contract + TS regen,
drift green).
- The **map marker** uses **`thumbnail_url`**, **falling back to `image_url`** when
  the thumbnail is null.
- Mobile map markers render the picture; the place detail shows the main image.
- Source: enrichment (deliverable 1) or a manual upload (deliverable 2).

## Acceptance

- [ ] Enrich action populates place fields from web/menu/GMB/reviews, independent of any share; never throws; respects manual overrides; caches per source; SSRF-safe.
- [ ] Places are Filament-editable with an audit trail; a manual override survives a later enrichment.
- [ ] `places.image_url` + `thumbnail_url` shipped end-to-end (migration → model → resources → contract → mobile); marker uses thumbnail-else-main; detail shows the main image.
- [ ] Tests: enricher populates + never-throws + honors overrides (`Http::fake`/fixtures per source); manual edit persists and isn't clobbered by re-enrichment; marker image fallback (thumbnail → main); contract drift green; mobile eslint/tsc/jest green.

## Notes

- **Overlaps T-083** ("suggest an edit" + business-owner management): both add
  manual place editing. Coordinate — the owner/suggest-edit write path (T-083) and
  the admin manual edit (here) should share one place-patch + audit mechanism.
- UI parts (Filament forms, mobile marker image, detail image) → `/frontend-design`.
- Consider a `place_field_overrides` (or per-field `*_locked`) mechanism so
  enrichment and manual edits compose deterministically.
