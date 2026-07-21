# T-088 — ARCH/P0: "My places" filter facets over the full collection (not page 1)

- **Phase:** ARCH (P0 silent correctness) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-034 (feed/my-places), T-071 (personal collection)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/MePlacesController.php`,
  `apps/mobile/app/(main)/places.tsx`,
  `apps/mobile/src/components/place/my-places-filters.tsx`,
  `apps/mobile/src/api/hooks/useMyPlaces.ts`
- **UI task → use the `/frontend-design` skill.**

## Context (audit finding, 2026-07-21)

`places.tsx:43-47` fires a second `useInfiniteQuery` (`facetSource`) purely to derive
country/type filter chips, but **never calls `fetchNextPage`** — so facets are computed from
page 1 only (`limit: 20`, useMyPlaces.ts:8). `my-places-filters.tsx:14` documents "derived from
the loaded collection", but that's silently capped at 20. Any user with >20 places sees an
incomplete, arbitrary (recency-ordered) set of filter options — filters for countries/categories
they demonstrably have simply don't appear, with no indication.

## Implementation

- Preferred: a lightweight aggregate endpoint (e.g. `GET /me/places/facets`) returning distinct
  countries/types over the **full** set, mirroring the existing `myPlacesTags()` pattern; drop
  the redundant unpaginated fetch.
- Alternative: compute facets server-side and return alongside the list.
- If a new endpoint/resource is added: JSON schema + TS regen, drift green.

## Acceptance criteria

- [ ] Country/type options reflect every saved/shared place, not just the most-recent 20
- [ ] API facets test covers a >20-item fixture; mobile test shows an option for a country
      present only beyond page 1
- [ ] Contract drift green (if schema touched)
- [ ] Gates: API `lint`+`stan`+`test`; mobile `eslint`+`tsc`+`jest` green

## Log

- **2026-07-21** — Filed from the architecture audit.
