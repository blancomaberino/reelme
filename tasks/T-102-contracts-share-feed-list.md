# T-102 — Contracts: share / feed-item / place-list schemas + conformance tests (close the pipeline drift gap)

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-094
- **Target paths:**
  - `packages/contracts/schemas/`
  - `packages/contracts/src/generated/`
  - `apps/api/tests/Feature/Shares/`
  - `apps/mobile/src/api/shares.ts`
  - `apps/mobile/src/api/__tests__/contract-conformance.test.ts`

## Context (codebase audit, 2026-07-29)

Audit finding A3 (2026-07-29). ADR-006 declares contracts the single source of truth, but the 7 schemas cover only the read-only discovery surface. ShareResource is the most complex payload in the system (222 lines) and its mobile counterpart is 12 hand-written types with nothing pinning them together - exactly where drift hurts most. T-094 pinned PlaceSummary/UserProfile/extraction; this finishes the job.

## Acceptance criteria

- [ ] share.json, feed-item.json and place-list.json exist in packages/contracts/schemas and generate TS types alongside the existing six
- [ ] API contract tests validate ShareResource (all statuses: pending/fetching/analyzing/review/published/failed/rejected, plus failure taxonomy, stage metrics, pending venues, multi-place), FeedItemResource and PlaceListResource via ApiSchema::validate()
- [ ] Mobile ShareDetail / FeedItem / PlaceList types are pinned to the generated contract types by compile-time assertions in contract-conformance.test.ts - a renamed or removed API field breaks typecheck
- [ ] The 12 hand-written types in src/api/shares.ts and 5 in src/api/lists.ts are either derived from contracts or explicitly documented as client-only view models
- [ ] Gates green: composer lint + stan + test; npm run lint + tsc --noEmit + test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
