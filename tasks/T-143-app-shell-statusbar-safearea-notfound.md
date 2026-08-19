# T-143 — App shell: no StatusBar anywhere, no bottom safe-area on pushed screens, no `+not-found`

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-004 (Expo app scaffold), T-047 (offers, redemption display,
  restaurant QR-verify screens)
- **Target paths:** `apps/mobile/app/_layout.tsx`,
  `apps/mobile/app/+not-found.tsx`, `apps/mobile/app/offers/[id]/redeem.tsx`,
  `apps/mobile/app/restaurant/verify.tsx`, `apps/mobile/app.config.ts`

Mobile review 2026-08-19, findings **MOB-5**, **MOB-9**, and the reviewer's note
that there is no `+not-found` route.

Three app-shell concerns that belong to nobody in particular — all S, all one
PR's worth of files, all visible in the first ten seconds on a device.

## Context

### MOB-5 — no StatusBar is set anywhere

`grep -rn StatusBar app src app.config.ts` returns **nothing** (verified).
`app.config.ts` sets no `androidStatusBar` and no `ios.statusBarStyle`,
`userInterfaceStyle` is `'automatic'`, and every screen uses
`headerShown: false` so react-navigation never sets it either. The dark canvas
is near-black.

**Failure:** a user on a dark-mode phone opens the app on either platform and
the clock, battery and signal icons are dark-on-dark — invisible on every
screen. `expo-status-bar` **is already a dependency** (`package.json:46`). This
is one `<StatusBar style="auto" />` in `app/_layout.tsx` that nobody ever added.

### MOB-9 — no bottom safe-area on pushed full-screen routes

`offers/[id]/redeem.tsx:103,230` and `restaurant/verify.tsx:239,356` are pushed
routes with no tab bar, using `<SafeAreaView edges={['top']}>` and
`scroll: { flexGrow: 1, padding: space.md }`. No screen in the app reads
`insets.bottom` except `sheet-shell.tsx:112` and `connection-banner.tsx:64`.

**Failure:** on the diner's redemption screen the "Close" / "Get another"
button — **the only way out** — sits under the home indicator / Android gesture
bar. On the till screen the manual-code input and "Check" button, i.e. the
fallback for when the QR will not scan, do the same. Both are the moment a
paying customer is standing at a counter, which is why a two-character fix gets
filed rather than absorbed.

### No `+not-found` route

`app/` has `+native-intent.tsx` and nothing else prefixed (verified). An
unmatched deep link shows expo-router's default "Unmatched Route".

It belongs here rather than with T-137 because it is chrome, not a boundary —
but the connection is worth knowing: T-137 establishes that **anyone can send
this app a URL**, so "what an unmatched one looks like" stops being
hypothetical.

## Acceptance criteria

- [ ] The status bar is legible on both platforms in light **and** dark mode on
      the near-black canvas — verified by screenshot on a device in both modes,
      not by reading the config
- [ ] The redemption screen's "Close" / "Get another" button and the till
      screen's manual-code input and "Check" button clear the home indicator and
      the Android gesture bar — screenshotted with gesture navigation on
- [ ] An unmatched deep link lands on a real `+not-found` screen with a way back
      into the app
- [ ] Each fix is asserted where it can be (edges, route existence) and
      screenshotted where it cannot

## Gotchas

- **MOB-4 came from the same root cause and is deliberately NOT in this task.**
  It is an auth boundary, and mixing it into a chrome PR is how it gets skimmed.
  It is T-142.
- `edges={['top','bottom']}` interacts with the scroll container's padding —
  check that the fix does not double-pad on a device without a home indicator.
- A `+not-found` screen that offers only "go home" is fine; one that offers
  nothing is another dead end.
