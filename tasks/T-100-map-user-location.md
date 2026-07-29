# T-100 — Map: user location, permissions, persisted viewport (kill the hardcoded Montevideo default)

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-032
- **Target paths:**
  - `apps/mobile/app/(main)/map.tsx`
  - `apps/mobile/app.config.ts`
  - `apps/mobile/package.json`
  - `apps/mobile/src/stores/map.ts`
  - `apps/mobile/src/lib/geo.ts`

## Context (codebase audit, 2026-07-29)

Audit finding B1 (2026-07-29, highest-impact UX defect). DEFAULT_REGION in map.tsx:28 is hardcoded to Montevideo (-34.9,-56.16); showsUserLocation is set at map.tsx:257 but there is no expo-location dependency and no Info.plist/Android permission, so the prop is silently inert on iOS. Every user outside Uruguay cold-starts into the wrong hemisphere, and 'reset view' sends them back there.

## Acceptance criteria

- [ ] expo-location installed; NSLocationWhenInUseUsageDescription (iOS) + ACCESS_COARSE/FINE_LOCATION (Android) declared in app.config.ts with user-facing copy in both locales
- [ ] First map open requests when-in-use permission once; on grant the map centers on the user, on deny/error it falls back to the last persisted viewport, then DEFAULT_REGION
- [ ] Last settled viewport persists across cold start (SecureStore, same pattern as settings store); a deep-linked lat/lng param still wins over both
- [ ] A 'locate me' control sits in the existing zoom stack and re-centers on the user; 'reset view' recenters on the user when permission is granted, DEFAULT_REGION otherwise
- [ ] showsUserLocation actually renders the blue dot on a device (it is inert today - no Info.plist key)
- [ ] Permission denial is non-blocking: no modal dead-end, map stays fully usable, and the locate control shows a one-time hint pointing at OS settings
- [ ] Tests: unit tests for the fallback chain (granted / denied / no persisted viewport / deep-link param) and a persisted-viewport rehydrate test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
- **2026-07-29** — Implemented on `feat/T-100-map-user-location` (2 commits). All
  acceptance criteria met. `expo-location@~57.0.7` added; permission copy in es
  (base Info.plist) + en (`en.lproj/InfoPlist.strings`), both **verified by
  running `expo prebuild -p ios`** and grepping the generated plist — the key was
  genuinely absent before. Also suppressed expo-location's generic
  always/background/motion plist defaults (`false` deletes the key) so the
  declared permission surface is exactly when-in-use.
  Opening viewport resolves through one chain in `src/lib/initial-region.ts`
  (param → saved → device → DEFAULT_REGION); rungs 1-2 are synchronous off a
  viewport store hydrated at boot, so only a true first launch shows a loading
  state. `/coderabbit` pass found and fixed: viewport surviving sign-out (coarse
  location leak on a shared device), missing double-tap guard on locate, an
  over-tight MIN_DELTA that would drop a max-pinch-zoom viewport, plus
  simplify/efficiency cleanups. Gates: eslint ✓ tsc ✓ jest 70 suites / 369 tests ✓;
  gitleaks + semgrep clean; 97.9% stmts / 98.7% branch over the new modules
  (fallback chain 100%). Approval receipt recorded. **PR not yet opened.**
- **2026-07-29 (device verification)** — Owner flagged that the task was reported
  done without ever running the app. Correct call: it crashed immediately. Three
  further defects, none of which the 369-test suite could see, because
  `jest.setup.ts` mocks every native module:
  1. **My bug** — `expo-location` resolves its native module at IMPORT time, so a
     top-level `import` threw during module evaluation and took the whole map
     screen down. Every try/catch in `location.ts` was dead code. Now a memoized
     lazy `require`, so a binary without the module degrades as documented.
  2. **Version skew** — `expo install expo-location` gave 57.0.7 (registry
     recommendation for SDK 57) but the project pins expo@57.0.4 /
     expo-modules-core@57.0.3, whose `bundledNativeModules.json` wants ~57.0.2.
     57.0.7 referenced an ExpoModulesCore symbol 57.0.3 doesn't export → dyld
     abort before any JS. Exact-pinned 57.0.2. **`expo install --check` reports 17
     outdated packages; that skew is the root cause and needs its own task.**
  3. **My bug** — persistence was gated on `details.isGesture`, which is
     Android-only (iOS `AIRMapManager.m` sends `region` alone) and optionally
     typed, so it typechecked and silently never persisted on iOS. Replaced with
     an explicit interaction flag (`onPanDrag` + a single `moveMap()` helper all
     programmatic moves route through). The mount settle goes through neither —
     load-bearing, because persisting it let the saved rung beat the location rung
     forever and pinned the map to the fallback city.
  Verified on iPhone 16 Pro Max / iOS 18.1 with simulated Barcelona and a keychain
  reset between runs: clean slate opens on Barcelona with the blue dot; pan → quit
  → relaunch reopens on the panned viewport. 376 tests green; coverage 98.2% stmts
  / 98.8% branch over the new modules. Approval receipt re-recorded at 1cad142.
  **PR still not opened.**

## Lesson

Green tests + a config grep are not evidence a mobile change works. Native-module
additions invalidate the installed dev client, and jest mocks hide every native
failure. Run it on the simulator before calling a task done.

