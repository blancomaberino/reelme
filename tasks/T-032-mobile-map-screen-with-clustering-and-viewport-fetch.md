# T-032 — Mobile: Map screen with clustering and viewport fetch

- **Phase:** M2 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-029, T-010
- **Target paths:** `apps/mobile/app/(tabs)/map.tsx`, `apps/mobile/src/components/map/`
- **Spec refs:** [../05-mobile-app.md#map-ux](../05-mobile-app.md) (§4), [../03-api-design.md#map](../03-api-design.md) (§2.7, §3.3)

## Context

With `GET /map/places` live (T-029) and the authed API client from T-010, this task builds the core discovery surface: the Map tab with viewport-driven fetching, cluster markers, a filter bar, and a bottom-sheet place preview. It is the app's default tab and the M2 centerpiece; T-033's Place detail screen is its primary navigation target. App code lives in the separate app repo created by T-001; the tab route file follows the router layout T-004 scaffolded (tasks.json: `app/(tabs)/map.tsx` — reconcile with 05-mobile-app's `(main)/map/index.tsx` naming by using whatever group directory the scaffold actually has).

## Implementation steps

1. **Dependencies.** `npx expo install react-native-maps @gorhom/bottom-sheet react-native-reanimated react-native-gesture-handler` and `npm i supercluster`. `react-native-maps` on Android needs the Google Maps key config plugin in `app.config.ts` (`PROVIDER_GOOGLE`); iOS uses `PROVIDER_DEFAULT` (Apple Maps, no key). **Requires a dev-client rebuild** (`eas build --profile development`) — Expo Go will not work; note it in the PR description.
2. **Data hook** `src/api/hooks/useMapPlaces.ts`:
   - Fetch on `onRegionChangeComplete` only (never `onRegionChange`), debounced 400 ms.
   - Pad the requested bbox ~40% beyond the viewport so small pans hit cache.
   - **Quantized query keys:** round the padded bbox to a zoom-dependent grid and band the zoom (`zoomBand = clamp(round(zoom))`) before keying: `['places', 'map', quantizedBbox, zoomBand, filters]` via the central `queryKeys` factory in `src/api/keys.ts`.
   - `useQuery` options: `staleTime: 120_000`, `placeholderData: keepPreviousData` (old pins stay visible while the new region loads — no blink).
   - Response is §3.3: `{pins, clusters}` + `meta.truncated` → when truncated, surface a "zoom in for more" chip.
3. **Cluster rendering strategy.** At `zoom < 15` the server returns clusters — render them directly. At `zoom >= 15` the server returns raw pins; feed them through **supercluster** in a `useMemo` keyed by `[placesData, zoomBand]` (radius 50 px, `maxZoom: 16`) so dense pin fields still collapse client-side per 05-mobile-app §4.1. Cluster marker = circle with count; tap → `mapRef.current.animateToRegion()` fitting `cluster.expand.bbox` (server) or `supercluster.getClusterExpansionZoom` (client).
4. **Marker memoization (the load-bearing pattern):**

```tsx
const PlaceMarker = React.memo(function PlaceMarker({ pin, selected }: Props) {
  return (
    <Marker
      identifier={pin.id}
      coordinate={{ latitude: pin.lat, longitude: pin.lng }}
      tracksViewChanges={false}          // critical Android perf lever
      onPress={onMarkerPress}            // stable module/parent-level cb, reads identifier
    >
      <PinGlyph cuisine={pin.tags[0]} priceTier={pin.price_range} selected={selected} />
    </Marker>
  );
}, (prev, next) => prev.pin.id === next.pin.id && prev.selected === next.selected);
```

   `onPress` handlers are stable (`useCallback` in the screen, id read from `event.nativeEvent.id`/`identifier`) — never inline closures per render. Flip `tracksViewChanges` true→false only for the one frame a marker's `selected` visual changes.
5. **Region handling.** Never store the map region in React state — read from the `onRegionChangeComplete` event / a ref. Screen state that must not re-render `MapView`: selected pin id and sheet content live in `useUiStore` with scoped selectors so the `MapView` subtree doesn't subscribe to sheet-content changes.
6. **Bottom-sheet preview** (`@gorhom/bottom-sheet`, ~35% snap): tapping a pin populates the sheet (thumbnail, name, cuisine/price, influencer attribution line, distance, "View place" → `/places/[slug]` i.e. T-033). Map stays interactive; tapping another pin swaps sheet content in place (no dismiss/reopen). Selected pin gets an enlarged marker state and the map centers on it offset upward so the sheet doesn't cover it.
7. **Filter bar** (`src/components/map/FilterBar.tsx`): horizontal chips for cuisine, price (1–4), tags (top tags from `GET /tags?popular=1`); `filter=following` chip rendered but disabled with "coming soon" until M3. Active filters live in `useUiStore`; changing filters feeds into the query key (step 2) so results refetch. Filters map to T-029 params: `cuisine`, `price_range`, `tags[]`.
8. **Tests** (jest-expo + RNTL + msw fixtures typed from `@reelmap/contracts`):
   - bbox quantization: nearby regions produce the **same** query key; a large pan produces a new one.
   - filter change invalidates/creates the right key.
   - marker memoization: render the screen with 50 fixture pins, trigger an unrelated state change (open sheet), assert `PlaceMarker` render count did not increase (spy via `jest.fn` in a test-only wrapper).
   - cluster tap calls `animateToRegion` with the expand bbox.
   - truncated response shows the "zoom in" chip.

## Acceptance criteria

- [ ] Map tab renders `react-native-maps` (Apple Maps iOS / Google Android) as the default tab, dev-client build documented
- [ ] Fetches fire only on `onRegionChangeComplete`, debounced 400 ms, with ~40% bbox padding
- [ ] Query keys are quantized (`['places','map', quantizedBbox, zoomBand, filters]` via the `queryKeys` factory); tiny pans reuse cache; `keepPreviousData` prevents pin blink
- [ ] Server clusters render below zoom 15; at zoom ≥ 15 client-side supercluster (radius 50 px, maxZoom 16) collapses raw pins
- [ ] Cluster tap animates to the cluster's expansion bounds; pin tap opens the bottom-sheet preview at ~35% snap with name, cuisine/price, attribution, "View place" navigation to place detail
- [ ] Tapping another pin swaps sheet content without dismissing; selected pin enlarges and map recenters offset upward
- [ ] Filter bar (cuisine / price / tags) drives the API params and refetch; following-only chip present but stubbed/disabled
- [ ] No re-render storms: `PlaceMarker` is `React.memo` comparing `(id, selected)` only, `tracksViewChanges={false}`, stable `onPress`, region never in React state — verified by the render-count test
- [ ] `meta.truncated` responses show a "zoom in for more" chip
- [ ] Component tests green: quantization stability, filter invalidation, memoization render count, cluster expansion

## Verification

```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npx jest src/api/hooks/__tests__/useMapPlaces src/components/map
# device (dev client build against local API with MapPerformanceSeeder data):
npx expo start --dev-client
```

Manual on device (API seeded with the T-029 10k seeder): open Map tab over Lisbon → clusters appear < 1s; pan slightly → no network request (cache hit, check via React Query devtools/log); zoom to street level → individual pins; tap pin → bottom sheet slides to ~35% with place preview; tap another pin → sheet content swaps; apply cuisine filter → pins refetch filtered; pan fast across the city → no dropped frames / marker flicker (watch Android particularly).

## Gotchas

- **Marker re-render storms:** any inline closure or object literal passed to `<Marker>` defeats `React.memo`; the comparator on `(id, selected)` only is deliberate — pin data is immutable per fetch. Without `tracksViewChanges={false}` Android re-rasterizes every marker every frame.
- **`onRegionChange` vs `onRegionChangeComplete`:** the former fires per gesture frame; fetching or setState there kills the map. Also never `setState` the region.
- **Query-key drift:** building keys from the raw (unquantized) bbox makes every pixel of pan a cache miss and a network call — the quantization test exists to prevent regressions.
- **supercluster index rebuilds:** constructing the index per render is O(n log n) each frame — keep it in `useMemo` keyed by `[placesData, zoomBand]` only.
- **gorhom bottom-sheet setup:** requires `GestureHandlerRootView` at the root and Reanimated babel plugin; forgetting either fails silently or crashes on Android only.
- **Cold cache + auth:** `filter=following`/`mine` need the bearer token; the public map must still work logged-out — don't gate the whole query on session.
- **Antimeridian:** clamp/normalize the padded bbox longitudes to [-180, 180]; the API 422s on wrapped boxes (see T-029).
