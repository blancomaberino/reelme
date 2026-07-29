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
