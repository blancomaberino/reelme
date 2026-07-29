# T-103 — Offline resilience: NetInfo + query-cache persistence + offline indicator

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-032, T-071
- **Target paths:**
  - `apps/mobile/app/_layout.tsx`
  - `apps/mobile/src/api/client.ts`
  - `apps/mobile/src/stores/ui.ts`
  - `apps/mobile/package.json`

## Context (codebase audit, 2026-07-29)

Audit finding B2 (2026-07-29). No NetInfo, no onlineManager/focusManager, no persistQueryClient anywhere - react-query is memory-only with staleTime 60s / retry 2. The subway-and-roaming case is precisely the 'where was that restaurant I saved?' moment the product exists for. Scope the persisted cache deliberately: do not persist other users' profiles or the public feed.

## Acceptance criteria

- [ ] onlineManager is wired to NetInfo and focusManager to AppState, so react-query pauses/resumes correctly instead of burning retries offline
- [ ] The query cache persists (24h maxAge) and rehydrates on cold start; scope is at minimum the 'mine' map and previously-opened place details
- [ ] Cold-starting with no network shows the user's own saved places from cache, not an empty map and a spinner
- [ ] An offline banner is visible while disconnected, reusing the existing useUiStore.rateLimited banner pattern; it clears on reconnect
- [ ] A failed fetch is distinguishable from an empty result in the UI (offline / error / genuinely-empty are three different states, each with copy in both locales)
- [ ] Mutations attempted offline fail with a clear message rather than a silent retry loop; no stale write is replayed unexpectedly
- [ ] Tests cover: offline cold start serves cache, reconnect refetches, offline mutation surfaces its error

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
