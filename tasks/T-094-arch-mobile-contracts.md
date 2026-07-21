# T-094 — ARCH/P1: mobile consumes @reelmap/contracts (kill hand-duplicated API types)

- **Phase:** ARCH (P1 contract safety) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-005 (contracts extraction), T-004 (expo scaffold)
- **Target paths:** `apps/mobile/package.json`, `apps/mobile/src/api/types.ts`,
  `apps/mobile/src/api/places.ts`, `apps/mobile/src/api/profile.ts`, `packages/contracts/`

## Context (audit finding, 2026-07-21 — flagged by 2 agents)

Contract drift is enforced schema↔generated-TS (`packages/contracts` Jest diff) and schema↔API
(PHP `ApiSchema` contract tests). But the types the **mobile app actually codes against** are a
third, hand-maintained copy: `types.ts:1` / `places.ts:1` carry stale TODOs ("switch to
@reelmap/contracts once ...") and `grep @reelmap/contracts apps/mobile` finds **zero imports** —
even though `packages/contracts/schemas/{place,place-summary,place-source,user-profile,
influencer-profile}.json` and their generated TS already exist. A renamed/removed API field
breaks nothing at typecheck time, only at runtime on-device.

## Implementation

- Add the `@reelmap/contracts` workspace dependency to `apps/mobile`.
- Derive `src/api/{types,places,profile}.ts` from the generated types (import / `Pick<>` /
  composition) instead of hand-mirroring the Laravel resources; remove the stale TODOs.
- Add a CI check tying mobile to the schema (schema-conformance test over a fixture response, or
  a typecheck that fails on drift).

## Acceptance criteria

- [ ] Mobile API shapes derive from `@reelmap/contracts`; no hand-duplicated resource types
- [ ] Renamed/removed API field fails typecheck/CI, not just runtime
- [ ] Gates: mobile `eslint`+`tsc`+`jest`; contracts tests green

## Notes

Relates to CI **T-006** (add the drift check there).

## Log

- **2026-07-21** — Filed from the architecture audit.
