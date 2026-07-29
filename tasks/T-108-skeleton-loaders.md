# T-108 — Skeleton loaders for place detail + my-places (replace spinner-first loading)

- **Phase:** ARCH (audit wave 2) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-104
- **Target paths:**
  - `apps/mobile/src/components/skeleton.tsx`
  - `apps/mobile/app/place/[slug].tsx`
  - `apps/mobile/app/(main)/places.tsx`

## Context (codebase audit, 2026-07-29)

Audit finding B7 (2026-07-29). ActivityIndicator appears in 18 files; exactly 1 skeleton exists. Place detail and my-places have known stable layouts, so they are the highest-value candidates. A spinner makes a 400ms fetch feel slower than a skeleton makes an 800ms one.

## Acceptance criteria

- [ ] A reusable Skeleton primitive (respecting the token scales and both color schemes) replaces the bare ActivityIndicator on place detail and the my-places list
- [ ] Skeleton geometry matches the real layout closely enough that content does not visibly jump on load
- [ ] The skeleton respects reduce-motion: no shimmer animation when the OS setting is on
- [ ] Loading, empty and error remain three visually distinct states on both screens
- [ ] Uses /frontend-design per CLAUDE.md

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
