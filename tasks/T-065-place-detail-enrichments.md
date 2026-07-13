# T-065 — Place-detail enrichments: opening hours + Google Maps link

- **Phase:** M2 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-030 (place API), T-033 (mobile place detail), T-061 (web demo)
- **Target paths:** `apps/mobile/app/place/[slug].tsx`, `apps/api/public/demo.html`, `apps/api/app/Http/Resources/PlaceResource.php`
- **Spec refs:** `03-api-design.md#places`, `05-mobile-app.md`

## Context

Small display-only enrichments to the place-detail screen requested 2026-07-13:

1. **Opening hours if present** — surface `opening_hours` (today's open/closed + weekly).
2. **Google Maps profile link** — when `google_place_id` exists, link to the place's Google
   Maps page.
3. **Menu-updated date** — show when the dish/menu prices were last refreshed.

### Already-done notes (don't re-build)

- **Menu-updated date is ALREADY SHIPPED** (`dishes_updated_at`, PRs #53/#54 — mobile +
  web show "Menú actualizado el <fecha>"). This task only **verifies it stays**.
- **Opening-hours summary already exists on mobile** (`src/lib/opening-hours.ts`
  `summarizeHours` + the collapsible weekly view on place detail). The real new work here is
  the **Google Maps link** (both surfaces) and **porting opening hours to the web demo**.

## Implementation

- **Google Maps link:** when `google_place_id` is present, render a "View on Google Maps /
  Ver en Google Maps" row/link →
  `https://www.google.com/maps/search/?api=1&query=<name>&query_place_id=<google_place_id>`
  (http(s)-guarded via the existing `safeUrl`/`isHttpUrl`). Add on mobile place detail and
  in the web demo drawer.
- **Opening hours (web demo):** render today's status + weekly hours from `opening_hours`
  in `demo.html`, mirroring the mobile summary. Preserve `esc()`.
- **Verify** the menu-updated line + mobile hours still render (no regression).

## Acceptance criteria

- [ ] Opening hours: today's open/closed + weekly schedule render when present (mobile confirmed; added to web demo)
- [ ] Google Maps link shown when `google_place_id` exists (mobile + web), http(s)-guarded
- [ ] Menu-updated date confirmed present on both surfaces (already implemented)
- [ ] Localized (es default); no regression to the detail layout

## Verification

- Mobile: `tsc`/`lint`/`jest`; a place with hours + a google_place_id shows both the hours and the Google link.
- Web: browser-check the demo drawer shows hours + the Google Maps link; XSS guards intact.
