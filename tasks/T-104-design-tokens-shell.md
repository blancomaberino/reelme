# T-104 — Design tokens (space/radius/type) + ScreenHeader + Button variants

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-060, T-101
- **Target paths:**
  - `apps/mobile/src/theme/`
  - `apps/mobile/src/components/button.tsx`
  - `apps/mobile/src/components/screen-header.tsx`
  - `apps/mobile/app/`
  - `apps/mobile/.eslintrc.js`

## Context (codebase audit, 2026-07-29)

Audit findings B5/B6/B9 (2026-07-29). Today src/theme/colors.ts IS the design system. Spacing drift: 12 (111x), 8 (84x), 10 (50x), 16 (47x), 6 (39x), 4 (38x), 14 (30x) plus one-offs at 3/5/7/9/13. Radius: 12/999/14/16 plus 2/3/4/6/8/9/10/22/28/40/44. Type: 20 distinct sizes including 17/19/21/27. 15 screens duplicate the back header. Button has 2 variants and 1 size, so only 13 of 29 screens import it. Sequenced after T-101 so tokens are defined against the corrected palette.

## Acceptance criteria

- [ ] src/theme exports space (4/8/12/16/24/32/48), radius (sm/md/lg/pill) and type (caption/body/bodyLg/title/display/hero) scales alongside the existing palette
- [ ] A single <ScreenHeader title back right?> component replaces the hand-rolled back header in all 15 screens that copy it today; the 5 drifted headerTitle style definitions collapse to one
- [ ] Button gains danger / ghost / link variants, a size prop and an icon slot; screens that hand-roll a Pressable CTA (e.g. status.tsx styles.link) migrate to it
- [ ] An eslint rule bans raw numeric fontSize / borderRadius / padding in NEW files so the drift field cannot regrow; existing files migrate opportunistically, not big-bang
- [ ] No visual regression: the migrated screens are diffed against before/after screenshots and any intentional change is called out
- [ ] Uses /frontend-design per CLAUDE.md

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
