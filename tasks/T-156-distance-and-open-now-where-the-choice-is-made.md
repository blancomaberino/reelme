# T-156 — Distance and open/closed reach the screen where the choice is actually made

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-155
- **Target paths:**
  `apps/api/app/Services/Map/MapViewport.php`,
  `apps/api/app/Http/Requests/PlaceListingRequest.php`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceController.php`,
  `apps/api/app/Http/Resources/PlaceSummaryResource.php`,
  `packages/contracts/schemas/`,
  `apps/mobile/app/(main)/map.tsx`,
  `apps/mobile/src/components/map/place-sheet.tsx`

## Why

The owner's retrieval query is *zone -> what I feel like eating -> is it open
right now -> pick one*. Today the app answers none of the last three at the moment
of choice.

`MapViewport::pin()` emits `name, lat, lng, category, city, price_range, status,
tags, source_count, has_active_offer` -- no distance, no open state. `PlaceSheet`
renders name, price line, city, one influencer handle and up to four tags, so
deciding "is this one close, and is it open?" costs a tap through to the place
page, a scroll to the hours accordion, prose hours, and a trip back. Per pin.
`PlaceListingRequest` accepts `sort=recent|popular` only, so there is no list
escape either.

The plumbing already half exists and is wired to the wrong surface:
`PlaceSummaryResource` emits `distance_m` when a near-point is selected, and
`PlaceController` already runs `ST_DWithin`/`ST_Distance` -- but only on the
nearby-offers path. This task carries that to the map.

Also fixes the map opening on the last viewport (`map.tsx` `resolveInitialRegion`,
sticky by design) rather than on the user, which is the wrong default for someone
standing in a neighborhood deciding where to eat.

## Acceptance

- The map pin payload carries `distance_m` and `open_now` when the request supplies a viewer point, and both are absent (not zero, not false) when it does not -- asserted both ways
- The pin sheet renders distance and an open/closed cue; with either value null it renders neither and never fabricates a 'Closed' (the T-155 rule, re-asserted here)
- `GET /places` accepts `sort=distance`, requires `lat`/`lng` with it, and returns 422 when the point is missing
- Distance is computed in SQL, not in PHP per row -- asserted by a query-count test over a multi-page result
- The map centers on the viewer when they are within a configured radius of their own pins, and falls back to the last viewport otherwise; both branches tested
- A pan re-queries with the new viewer-relative values (test the loop, not the first paint)
- packages/contracts regenerated and committed; contract-drift green

## Notes

Filed 2026-09-03 from the agency growth review (08-growth-and-opportunities.md, blocker B3). Half the work already exists on the wrong surface: PlaceSummaryResource already emits distance_m for the nearby-offers path. Depends on T-155 for open_now -- if T-155 closes as 'no structured hours', this task ships distance only and the open/closed cue is dropped, not guessed.
