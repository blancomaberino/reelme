# T-129 — Two mocks that make the app's front door and the map's re-query loop untestable

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-032 (map screen with clustering + viewport fetch),
  T-010 (mobile auth flow)
- **Target paths:** `apps/mobile/jest.setup.ts`,
  `apps/mobile/__tests__/welcome.test.tsx`,
  `apps/mobile/app/(main)/__tests__/map-location.test.tsx`,
  `apps/mobile/app/(main)/__tests__/map.test.tsx`

Code review 2026-08-19, findings **CR-5** and **CR-6**. Nothing here is
user-visible, and it is among the highest-leverage work in the wave: **these two
mocks are why the rest of it could ship green.**

## Context

### CR-5 — the map's pan to re-query loop is asserted nowhere

Both `useMapPlaces` mocks discard the region — `(_region: unknown, ...)` at
`map-location.test.tsx:27` and `map.test.tsx:34`.

The test at `map-location.test.tsx:373`, "still refetches for an un-remembered
settle", sets `mapData.current.truncated = true` **before** `render()`, fires
`regionChangeComplete`, then asserts the "Zoom in for more places" chip is on
screen. But the mock returns `truncated: true` from first paint, so the chip is
there regardless. Its comment — *"proving the query region moved"* — is false.

**Delete `setQueryRegion(region)` at `app/(main)/map.tsx:251` and every map test
still passes**, while the map silently pins itself to wherever it opened. That is
verbatim the bug CLAUDE.md names ("a map that never re-queried when panned"),
currently unguarded.

The pattern already exists, twice: `offers/__tests__/browse.test.tsx:237` does it
correctly, and `map.test.tsx` already captures filters via `mockFiltersSeen`. It
just was not applied to the region.

### CR-6 — the front door has no test of its only two controls

`jest.setup.ts:173` is `Link: ({ children }) => children` — `href` and `asChild`
are dropped. `welcome.tsx:25`'s only CTAs are `<Link href asChild><Button/></Link>`,
and `welcome.test.tsx` is **one test asserting the string "Reelmap" renders**.

Break either `href`, or drop `asChild` — which is what injects `onPress` into
`Button` on real expo-router, so without it the CTA is a **dead control** — and no
test fails while no new user can get past the welcome screen. `auth-flow.yaml`
does tap "Create account", but CI runs no Maestro (T-130), so it is not a gate.

The `Redirect` mock **two lines below** does capture `href`. Again, the pattern is
already in the file.

## Implementation

- Capture the region argument in the `useMapPlaces` mock; assert it equals the
  settled region after the 400ms debounce.
- Make the global `Link` mock render a pressable that calls
  `mockRouter.push(href)` and honours `asChild`.
- Press both welcome CTAs and assert where each lands.

**Prove each guard by breaking the code once.** Delete `setQueryRegion(region)`,
watch red, restore. Drop `asChild`, watch red, restore. A guard nobody has seen
fail is a guard nobody has seen.

## Acceptance criteria

- [ ] Deleting `setQueryRegion(region)` makes a test FAIL — proven, then restored
- [ ] The `useMapPlaces` mock captures its region argument and the test asserts
      it equals the settled region after the debounce
- [ ] The global `Link` mock honours `href` and `asChild` and routes through
      `mockRouter`; dropping `asChild` on `welcome.tsx` makes a test FAIL
- [ ] `welcome.test.tsx` presses BOTH CTAs and asserts where each one lands
- [ ] Whatever the corrected `Link` mock breaks elsewhere is **fixed, not
      re-stubbed**

## Gotchas

- **Expect fallout, and do not absorb it.** A `Link` mock that actually renders a
  pressable changes what a lot of screens render. Fixing those is the point —
  CLAUDE.md Golden Rule #5: *"A mock that silences a problem IS the problem."*
  This task is that rule applied to the two mocks that hide the most.
- Do not let a mock invent an identity the real component lacks (a testID, an id,
  a route). That makes the real one dead and hides every behaviour behind it.
- Single-suite `--testPathPattern` runs hang after passing on this repo
  (pre-existing worker leak) — use `--forceExit`.
