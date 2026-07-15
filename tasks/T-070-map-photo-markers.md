# T-070 — Identifiable map markers: photo bubbles + zoom-aware dots

- **Phase:** M2 (discovery surface) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-029 (map API), T-032 (mobile map), T-013 (post-image keyframes), T-034/T-030 (thumbnail resolving)
- **Target paths:** `apps/api/app/Services/Map/MapViewport.php`, `apps/mobile/src/components/map/pin-glyph.tsx`, `apps/mobile/src/components/map/place-marker.tsx`, `apps/mobile/app/(main)/map.tsx`, `apps/mobile/src/api/places.ts`, `apps/api/public/demo.html`
- **Spec refs:** `03-api-design.md#map`, `05-mobile-app.md`

## Context

Requested 2026-07-14: map pins were blank terracotta teardrops with only a price
glyph — impossible to tell apart when zoomed in (you're staring at empty markers
with no idea which place is which). They should work like Google Maps: show the
place's photo inside the marker, plus the name, so a pin is identifiable at a
glance; and when zoomed out, collapse to a simple dot rather than a full bubble
that loses its meaning and clutters the map.

### Already-available (don't re-build)

- Places already have imagery via their **primary source post** — the
  `ResolvesThumbnail` trait (T-034/T-030) resolves a signed ffmpeg/keyframe
  thumbnail or the oEmbed poster. `MapViewport` already eager-loads
  `primarySource.sourcePost`. So this is exposure + rendering, not new pipeline.
- The map already **clusters** (server grid below zoom 15, client supercluster
  at/above), so marker density is a solved problem — dots only stand in for
  lone singletons, never for crowds.

## Implementation

- **API:** add `thumbnail_url` to the map pin (`MapViewport::pin()`) via
  `ResolvesThumbnail`; eager-load `primarySource.sourcePost.mediaAssets`. `null`
  when a place has no imagery. Shared engine → discovery + profile + influencer
  maps all inherit it.
- **Mobile:** `PinGlyph` renders a Google-style **photo bubble** (circular
  thumbnail, MERCADO-accent ring, pointer tail) + the place **name**; falls back
  to the price teardrop when there's no poster. Below `DETAIL_BAND` (13) a lone
  place collapses to a **dot**.
- **react-native-maps gotchas:** keep `tracksViewChanges` on until the poster
  settles (else the marker freezes on a blank frame); **remount** the marker per
  detail level (fixed-size stage + explicit `anchor`) so a changed anchor doesn't
  drift the dot off-position on zoom-out.
- **Web demo (Leaflet):** bring `pinIcon` to parity (dot / photo bubble / teardrop
  by zoom; `encodeURI` the image URL, keep `esc()` on the name).

## Acceptance criteria

- [x] Map pins carry `thumbnail_url` (primary reel poster; `null` without a source), covered by `MapPlacesTest`
- [x] Mobile marker shows the photo bubble + name when zoomed in; price-teardrop fallback with no poster
- [x] Lone place collapses to a dot when zoomed out; density still clustered
- [x] Marker stays on its real position across zoom in/out (no anchor drift); no blank-frame freeze
- [x] Web demo pins at parity
- [x] Gates green: API Pint/PHPStan/Pest; mobile eslint/tsc/jest (new `place-marker.test.tsx`)

## Progress

- **2026-07-15** — Done. **PR #81** (`feat/t070-map-photo-markers` → `main`, squash-merged, merge `8e3e97a`). All gates green: API Pint (375 files) + PHPStan L6 + 113 Map/Media/Profile/Feed tests; mobile eslint + tsc + 187 jest tests. Verified on device: photo bubble at neighborhood zoom, dot holds its position across zoom. `DETAIL_BAND` left as a one-line tunable for the photo-vs-dot threshold.
