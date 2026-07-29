# T-101 — Accessibility: WCAG AA palette contrast, Dynamic Type, screen-reader status announcements

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-060
- **Target paths:**
  - `apps/mobile/src/theme/colors.ts`
  - `apps/mobile/src/theme/__tests__/`
  - `apps/mobile/app/shares/[id]/status.tsx`
  - `apps/mobile/src/components/`

## Context (codebase audit, 2026-07-29)

Audit findings B3/B4/B8 (2026-07-29). Measured light-scheme contrast: muted #938776 on background = 3.1:1 (124 uses), placeholder = 2.7:1, gold = 3.3:1, primary-as-link = 3.5:1, green = 3.8:1, and white onPrimary on primary #CF5C34 = 4.0:1 - the primary button's own label fails AA. Candidate fix: primary #B84D28 for text-bearing fills gives 5.1:1 (verify with a contrast checker before committing hex values). ~230 fixed fontSize declarations and zero font-scale handling app-wide. App Store accessibility review risk; cost grows with every new screen.

## Acceptance criteria

- [ ] Every foreground/background token pair used for text meets WCAG AA (4.5:1 normal, 3:1 large) in BOTH light and dark schemes
- [ ] A jest test computes relative luminance for the documented token pairs and fails the build on regression - the palette cannot silently drift back
- [ ] onPrimary-on-primary (every CTA label) passes: primary darkened for text-bearing fills, with a distinct darker pressed shade retained
- [ ] muted / placeholder / gold / green darkened to pass; the MERCADO art direction is preserved (small L* moves, reviewed against design/reelmap-design-v1, not a re-brand)
- [ ] Layouts with fixed heights around text (stepper nodes, badges, chips, buttons) survive iOS Larger Text at the largest non-accessibility size without clipping - verified on simulator
- [ ] Share status changes are announced to screen readers (accessibilityLiveRegion on the terminal block + announceForAccessibility on transition)
- [ ] Interactive controls missing accessibilityHint where the label alone is ambiguous get one (only 1 hint exists across the whole app today)

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
