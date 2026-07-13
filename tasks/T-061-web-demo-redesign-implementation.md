# T-061 — Web demo redesign: rebuild demo.html to the accepted design

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-060 (accepted design), T-030, T-031, T-034, T-036, T-037, T-059 (the API slices the demo exercises — all done)
- **Target paths:** `apps/api/public/demo.html`
- **Spec refs:** [../design/DESIGN-AGENT-PROMPT.md](../design/DESIGN-AGENT-PROMPT.md), `design/reelmap-design-v1.html` (visual source of truth)

## Context

Reskin-and-restructure the existing `apps/api/public/demo.html` to match the accepted
T-060 prototype, preserving every behavior it already has: Sanctum email/password auth,
share submission with status polling, federated search (T-031), feed with following scope
(T-034/T-037), viewport-driven map pins + clusters (T-029), place detail with sources and
app+Google ratings/reviews (T-030/T-059), review CRUD, public profiles + follow (T-036/
T-037). The demo stays a single static file served by Laravel — no build step — so the
prototype's inline-CSS/token approach ports directly.

## Implementation steps

1. **Lift the design.** Copy the `:root` token block, dark-mode overrides, and component
   CSS from `design/reelmap-design-v1.html` into `demo.html`. Delete the old `<style>`.
2. **Restructure the layout** to the prototype's composition: full-bleed map; floating
   search bar + filter chips; feed as a floating rail (desktop) / bottom sheet (mobile);
   auth and share-a-link moved from sidebar cards into modals behind a primary CTA and an
   avatar menu; place detail and profile as the styled drawer.
3. **Re-bind the existing JS.** The current script is small and well-factored
   (`api()`, `runSearch`, `loadFeed`, `loadPlaces`, `showPlace`, `showProfile`,
   `renderFollowButton`, share polling). Keep its logic and security guards (`esc()`,
   `safeUrl()`, the `data-u` profile-link pattern — do not reintroduce inline-JS username
   injection) and update only DOM ids/classes and render templates to the new markup.
4. **Custom map identity:** swap default Leaflet markers for the prototype's pin design
   via `L.divIcon` (cuisine glyph / price tier), restyle cluster bubbles, and switch tiles
   to the Carto style the design chose (light vs dark per theme).
5. **States:** implement the prototype's loading skeletons (feed, detail), empty states,
   error toast, and the share-pipeline status timeline (queued → analyzing → review →
   published / failed) driven by the existing polling.
6. **Theme toggle** honoring `prefers-color-scheme` with a manual override persisted in
   `localStorage`, switching both tokens (`data-theme`) and map tiles.
7. **Responsive pass** at ~390px: search pill, feed bottom sheet, detail as full sheet.

## Acceptance criteria

- [ ] Visual match to `design/reelmap-design-v1.html` (side-by-side pass) in light and dark, desktop and mobile widths
- [ ] Every current demo behavior still works: register/login/logout, share submit + status polling to published pin, search (places/tags/influencers), feed + following toggle, map pan/zoom pin+cluster fetch, cluster expand, place detail with sources + both rating sources, add/edit/delete own review, profile open + follow/unfollow
- [ ] No new external dependencies beyond the existing Leaflet + Carto tiles; still a single static file, no build step
- [ ] XSS guards preserved: all API strings pass through `esc()`, hrefs through `safeUrl()`, profile links via `data-u` delegation
- [ ] Custom pins/clusters via `divIcon`; default blue Leaflet markers gone
- [ ] Auth/share live in modals; signed-out users can still browse map, search, feed, and place detail

## Verification

```bash
docker compose exec php84 php artisan queue:work --once &  # or the running worker
open http://localhost:8080/demo.html
```

Manual walkthrough (seeded DB): browse logged-out → register → share a YouTube link →
watch the pipeline timeline reach published → pin appears → open detail → leave a review →
open sharer profile → follow → feed "following" scope shows the share. Repeat the visual
pass at 390px width and in dark mode. Confirm view-source shows no framework/build
artifacts.

## Gotchas

- The polling and out-of-order-search-response guards (`searchSeq`) are easy to drop in a
  rewrite — port the script, don't regenerate it from scratch.
- Leaflet `divIcon` default class adds a white box — pass `className:""` (the old cluster
  icon already does this).
- Bottom-sheet-over-map on mobile: keep the map interactive; pointer-events on the sheet
  container only, not a full-screen overlay.
- Dark/light tile swap requires replacing the `L.tileLayer` — Leaflet won't restyle in
  place; keep a reference and `setUrl()` or re-add the layer on theme change.
- This file doubles as the API's smoke-test surface (PROMPT.md workflow) — don't remove
  the little-used bits (caption-only submit path, failed-share state rendering).
