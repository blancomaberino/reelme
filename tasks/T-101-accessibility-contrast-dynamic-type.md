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

- [x] Every foreground/background token pair used for text meets WCAG AA (4.5:1 normal, 3:1 large) in BOTH light and dark schemes
- [x] A jest test computes relative luminance for the documented token pairs and fails the build on regression - the palette cannot silently drift back
- [x] onPrimary-on-primary (every CTA label) passes: primary darkened for text-bearing fills, with a distinct darker pressed shade retained
- [x] muted / placeholder / gold / green darkened to pass; the MERCADO art direction is preserved (small L* moves, reviewed against design/reelmap-design-v1, not a re-brand)
- [x] Layouts with fixed heights around text (stepper nodes, badges, chips, buttons) survive iOS Larger Text at the largest non-accessibility size without clipping - verified on simulator
- [x] Share status changes are announced to screen readers (accessibilityLiveRegion on the terminal block + announceForAccessibility on transition)
- [x] Interactive controls missing accessibilityHint where the label alone is ambiguous get one (only 1 hint exists across the whole app today)

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
- **2026-07-30** — Implemented; PR #148. All seven criteria met, each verified on
  device (iPhone 16 Pro Max / iOS 18.1, driven with Maestro at the largest
  non-accessibility text size, both schemes).

  **Method worth reusing.** The candidate hexes were solved rather than eyeballed:
  convert each token to OKLCH, hold hue and chroma, and walk lightness until the
  token clears 4.5:1 against *every* surface it actually carries text on. That
  produces the minimum move per token and makes "is this a re-brand?" a measurable
  question — final drift is ≤3.5° hue, chroma never up. The spec's candidate
  `primary #B84D28` was close but lands at 4.49:1 as link text on `--bg`; `#B54A25`
  clears every case.

  **Corrections to the filing.** (a) "~230 fixed fontSize declarations and zero
  font-scale handling" overstates it — RN's `Text` scales by default and nothing in
  the app opts out, so the fontSize declarations were never the problem. The fixed
  *container heights* around them were, and there were ~8, not 230. (b) The audit
  missed the map markers (`pin-glyph`, `cluster-marker`), which duplicated the
  palette as literals and carried white text at 4.01:1 and 3.35:1. They now read
  the light scheme off the palette.

  **Scope added:** `apps/api/public/demo.html` and `design/tokens.md`, since
  tokens.md requires the three to stay in lockstep. An accessibility floor is now
  stated in tokens.md so a future design sync cannot silently restore the old hexes.

  **Accepted cost:** `muted` and `placeholder` end up ~6 luminance units apart — AA
  on a warm light canvas leaves almost no room between "tertiary" and "placeholder".
  The hierarchy is genuinely compressed; there is no palette that keeps both the
  separation and the ratio.

  **Deferred option:** if market-gold reads too deep in daily use, split the token —
  keep it bright for non-text use (star glyph, chip fills, pins, where 3:1 suffices)
  and add a darker `goldText` for numerals. Not done here because the acceptance
  asked for "gold darkened".
