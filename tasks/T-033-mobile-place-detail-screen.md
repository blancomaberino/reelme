# T-033 — Mobile: Place detail screen

- **Phase:** M2 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030, T-010
- **Target paths:** `apps/mobile/app/place/[slug].tsx`
- **Spec refs:** [../05-mobile-app.md#screen-inventory](../05-mobile-app.md) (§3 #9), [../03-api-design.md#places](../03-api-design.md) (§2.6)

## Context

The Place detail screen is where the map and feed converge: everything Reelmap knows about one restaurant, with the provenance that makes the product trustworthy — every source video linking out to the original post with influencer + sharer attribution (an M2 exit criterion). It consumes T-030's `GET /places/{slug}` + `/places/{slug}/sources` and is navigated to from the map bottom sheet (T-032), feed and search (T-034). App code lives in the separate app repo created by T-001; route file per tasks.json is `app/place/[slug].tsx` (05-mobile-app writes `places/[id]` — slug-based routing is canonical here, matching T-030's slug show endpoint).

## Implementation steps

1. **Route + data.** `app/place/[slug].tsx` with `useLocalSearchParams<{slug: string}>()`. Two queries via typed hooks in `src/api/hooks/`: `usePlace(slug)` → `GET /places/{slug}?include=sources` and (if sources exceed the embed) `usePlaceSources(slug)` as an infinite query on `GET /places/{slug}/sources`. Query keys from the `queryKeys` factory (`['places', slug]`, `['places', slug, 'sources']`). Handle `meta.redirected_from` (merged place) transparently.
2. **Header section:** name, `cuisine_primary`, price tier rendered as `€€€` glyphs (1–4), tag chips (tap → future tag-filtered search, stub OK), `shares_count`, confidence-agnostic public presentation (no raw AI confidence on public screens).
3. **Info section:** address lines + city, opening hours from `opening_hours_json` (Google-style periods → "Open now · closes 23:00" summary line with an expandable weekly list; handle null hours), phone (tap → `Linking.openURL('tel:…')`), website link-out.
4. **Mini-map:** small fixed `MapView` (`scrollEnabled={false}`, `zoomEnabled={false}`, `pointerEvents="none"` wrapper) centered on the place with a single marker (`tracksViewChanges={false}`); tapping it navigates to the Map tab centered on the pin (router push with lat/lng params consumed by the map screen).
5. **Source videos section:** one card per place_source — thumbnail (signed URL from the API), platform badge (instagram/x/tiktok/youtube glyph parsed from `platform`), caption excerpt, **link out to the original post** via `Linking.openURL(source_post.url)` (per §3 #9; embedded YouTube player optional/deferred). Attribution row on each card: influencer (`@handle`, avatar) and sharer (`@username`) — tap targets stubbed until M3 profiles (`/users/[handle]` exists in M3); render non-navigable in M2. Mark the `is_primary` source visually (e.g. "First shared by").
6. **Action row:**
   - **Directions:** `Linking.openURL` with platform-appropriate URL — iOS `http://maps.apple.com/?daddr=<lat>,<lng>&q=<name>`, Android `geo:<lat>,<lng>?q=<lat>,<lng>(<encoded name>)`.
   - **Share:** React Native `Share.share({ message, url })` with the place name and a canonical web/deep link (`reelmap://place/<slug>` plus https fallback when the web domain exists).
7. **States:** loading skeleton; 404 → friendly "place not found" with back action; offline/error → retry. Offers section placeholder is **not** rendered in M2 (ships M4).
8. **Tests** (jest-expo + RNTL + msw with `@reelmap/contracts` fixtures): renders all extracted fields from fixture; source card fires `Linking.openURL` with the original post URL (mock `Linking`); directions builds the right URL per `Platform.OS`; hours summary for open/closed/null fixtures; 404 state.

## Acceptance criteria

- [ ] Screen at `app/place/[slug].tsx` loads via `GET /places/{slug}?include=sources` with typed TanStack Query hooks and structured query keys
- [ ] Shows extracted info: name, cuisine, price tier glyphs, tag chips, address, opening hours summary + expandable weekly hours, phone/website links
- [ ] Mini-map renders the place pin, non-interactive, and taps through to the Map tab centered on the place
- [ ] Source videos section lists every place_source: thumbnail, platform badge, caption excerpt, and link-out to the original post URL via `Linking.openURL`
- [ ] Each source card shows influencer attribution (handle + avatar) and sharer attribution (username); primary source visually marked
- [ ] Directions button opens Apple Maps (iOS) / geo intent (Android) with the place coordinates and name
- [ ] Share button invokes the native share sheet with place name + deep link
- [ ] Merged-place redirects, 404, loading, and error/retry states handled
- [ ] Component tests cover field rendering, link-out URL, directions URL per platform, hours edge cases, 404

## Verification

```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npx jest app/place src/api/hooks/__tests__/usePlace
npx expo start --dev-client
```

Manual on device (against seeded local API with at least one multi-source place): navigate from a map pin's bottom sheet → detail shows name/tags/price/hours/address; scroll to sources → two cards with different influencers; tap a card → Instagram opens on the original reel; tap Directions → Maps app opens routed to the place; tap Share → OS share sheet shows the deep link; open a merged place's old slug → terminal place renders.

## Gotchas

- **Signed thumbnail URL expiry:** R2 presigned URLs expire; with `staleTime` caching, an old cached response can hold dead URLs — keep image components tolerant (fallback placeholder on error) and place-detail `staleTime` modest (≤ 60s) rather than the map's 120s.
- **`Linking.openURL` to social apps:** Instagram/TikTok URLs open the native app when installed and Safari/Chrome otherwise — don't `canOpenURL` gate on custom schemes (iOS requires `LSApplicationQueriesSchemes`); just open the https URL.
- **Opening-hours math:** Google-style periods can span midnight and be absent entirely; compute "open now" in the place's local context naively (device timezone is acceptable for M2 — note it) and never crash on malformed/empty periods.
- **Mini-map inside a ScrollView:** an interactive MapView steals scroll gestures — disable interaction and wrap with `pointerEvents="none"`, using an overlay `Pressable` for the tap-through.
- **Sharer privacy:** the API may return an anonymized sharer (`is_public=false`) — render "a Reelmap user" rather than crashing on null handle.
- **Attribution taps in M2:** `/users/[handle]` and influencer profiles don't exist until M3 — keep the rows visually tappable-looking but inert (or toast "profiles coming soon"), and leave a TODO referencing T-036/T-039.

## Log

- **2026-07-12 — DONE (PR #37, squash `0979e9a`).** `app/place/[slug].tsx` on `GET /places/{slug}?include=sources`. Header, info (hours summary + expandable weekly, tap-to-call, website), **native react-native-maps mini-map** (Apple Maps iOS, taps through to `/(main)/map` with lat/lng params for T-032), source provenance cards (platform badge, caption, link-out to original post, influencer+sharer attribution, primary marked), Directions (Apple/`geo:`) + native Share, loading/404/error states.
- Pure tested helpers: `opening-hours` (open-now/closes-at, midnight + week-boundary + 24/7 sentinel, null-tolerant), `directions`, `format` (€ glyphs, platform icon, relative time), `linking` (http(s) allow-list + rejection-swallowing opener).
- **Introduced `react-native-maps`** — native module, needs a dev-client rebuild (`npx expo run:ios`); Expo Go won't work.
- Adversarial review fixed 7 findings: **HIGH** 24/7 place (Google day-0 no-close sentinel) shown "Closed" 6 days; **MED** unhandled `Linking.openURL` rejections; **LOWs** scheme allow-listing on API URLs, a11y labels, staleTime comment.
- 52 jest tests green; typecheck + expo lint clean. **Verified E2E on iPhone 17 Pro / iOS 26.5 via Maestro** (`e2e/place-detail.yaml`): deep link → live API render → native mini-map → dishes → scrolled source card.
- **Gotchas**: Filament-style — a `<Pressable accessibilityLabel>` collapses its children in the a11y tree, so Maestro sees the card's label (`"Open original instagram post"`), not the inner text. Custom-scheme deep links (`reelmap://…`) trigger an iOS "Open in Reelmap?" confirm dialog whose appearance is prior-foreground-state-dependent — handle with an optional `tapOn "Open"`. jest-expo's `Linking.openURL` is a persistent mock; `clearAllMocks()` per test to reset its call log.
