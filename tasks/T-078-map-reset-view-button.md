# T-078 — Map "reset view" button (reset zoom / recenter)

- **Phase:** M2 (mobile UX) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-032 (mobile map)
- **Target paths:** `apps/mobile/app/(main)/map.tsx`
- **UI task → use the `/frontend-design` skill.**

## Context

Requested 2026-07-16. After panning/zooming around, there's no quick way back to a
sensible default view. The map has on-screen zoom +/- controls (`zoomBy`) but no
**reset**. Add a button that resets the view (zoom back to a default level and
recenter).

## Implementation

- Add a **reset/recenter** control to the existing zoom stack (bottom-right) in
  `map.tsx` — e.g. a `locate`/`scan` glyph. On press, `animateToRegion` back to a
  sensible view.
- **What "reset" targets** (pick per design): the default region (`DEFAULT_REGION`),
  the user's current location, or **fit-to-bounds of my places** (most useful for
  the personal map — reuse `lib/map-region.fitRegion`). A safe default: recenter +
  reset zoom to the default delta; fit-to-mine if that data is on hand.
- Keep it consistent with the MERCADO map controls (matching button style/shadow);
  follow `/frontend-design`.

## Acceptance criteria

- [ ] A reset button sits with the zoom controls and, on press, animates the map
      back to the default zoom + center (or fit-to-my-places)
- [ ] Doesn't disturb the pin/cluster fetch invariants (it settles the region like
      any pan → the debounced refetch runs normally)
- [ ] Accessible label; matches the map-control visual style
- [ ] Test: pressing reset calls `animateToRegion` with the reset region
- [ ] Gates: mobile eslint + tsc + jest
