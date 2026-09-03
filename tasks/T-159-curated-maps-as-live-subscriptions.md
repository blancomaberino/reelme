# T-159 — Curated maps you subscribe to, as a layer that never buries your own pins

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:**
  `apps/api/database/migrations/`,
  `apps/api/app/Models/PlaceList.php`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceListController.php`,
  `apps/api/app/Models/Builders/PlaceQueryBuilder.php`,
  `apps/mobile/app/(main)/map.tsx`,
  `apps/mobile/src/components/map/filter-bar.tsx`

## Why

Two problems, one primitive. A new user's map is empty, and there is no supply
lever the project controls directly. A curated map -- "Pastas en Montevideo" --
fixes both: it fills the first session with something worth looking at, and it is
an asset the owner can keep building.

Owner decision D1 (08 section 9.3): subscribing is **live**, not a copy. When the
curator adds a place later, it appears for everyone subscribed. This is
deliberately NOT the existing `POST /me/lists/{slug}/copy`, which takes a snapshot
and then diverges forever.

Owner decision D2: subscribed pins are a **toggleable layer**, never merged. A
60-pin curated map must not bury the 12 places the user actually chose -- those
are the point of their map.

## Acceptance

- Subscribing to a public list makes its places available as a named, toggleable layer on the map; unsubscribing removes them
- A place the curator adds AFTER a user subscribed appears for that user -- the live half, asserted directly
- Subscribed places never enter `filter=mine`: the user's own place count is unchanged after subscribing, asserted
- A private or unlisted list cannot be subscribed to; subscribing to your own list is rejected
- Toggling a layer re-queries the map rather than filtering a stale client-side set
- A curator deleting the list, or a place being moderator-hidden, removes it from every subscriber's layer

## Notes

Filed 2026-09-03 from owner decisions D1 and D2 (08 section 9.3). Sibling-first: this extends place_lists, it does not introduce a second collection type. The existing copy endpoint stays as-is for people who want to own and edit pins.
