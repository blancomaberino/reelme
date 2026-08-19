# T-141 — The review composer's star rating is unannounceable to VoiceOver

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-059 (native reviews), T-101 (accessibility: contrast,
  Dynamic Type, screen-reader status)
- **Target paths:** `apps/mobile/src/components/place/review-composer.tsx`,
  `apps/mobile/src/i18n/`,
  `apps/mobile/src/components/place/__tests__/`

Mobile review 2026-08-19, finding **MOB-13**.

## Context

`review-composer.tsx:75-79` — the five star pressables carry
`accessibilityLabel={`${n}`}` and nothing else. No `accessibilityState`, no
unit. **This control alone gates the Publish button** (`disabled={rating < 1}`
at `:102`).

On place detail, VoiceOver reads "1, botón … 5, botón" with no indication these
are stars, and after tapping, nothing distinguishes filled from outlined. The
current rating is **unannounceable**, so a screen-reader user cannot confirm
what they are about to publish — on the one control that decides whether they
can publish at all.

### Another one-copy-missed-the-fix case

Every other selection surface in the app does this correctly:
`chip-select.tsx:38`, `price-select.tsx:31`, `filter-sheet.tsx:87`. T-101
established the pattern; this control shipped without it. **Read one of those
three before writing anything.**

### Why it is separate from T-139 / T-140

Those are virtualization work on list screens; this is an accessibility fix on a
form control. Bundling an a11y label into a FlashList PR is how the a11y half
gets skimmed.

## Acceptance criteria

- [ ] Each star announces what it is and whether it is currently selected —
      `accessibilityState={{ selected: n <= rating }}` plus a unit-bearing
      label, asserted per star
- [ ] The label comes from `t()` in both dictionaries, with a plural rule — not
      a bare number
- [ ] A screen-reader user can determine the CURRENT rating before publishing,
      and the Publish gate announces why it is disabled
- [ ] Verified with VoiceOver on a device, and what it reads recorded in the PR

## Gotchas

- **An accessibility fix asserted only in jest has not been heard.** RNTL will
  happily confirm a prop exists while VoiceOver reads something useless. Turn it
  on.
- Five separate pressables may not be the right control at all — consider
  whether an `accessibilityRole="adjustable"` group reads better than five
  buttons. If you keep five, each must still announce the whole state, because a
  screen-reader user lands on one star, not on the row.
- `t('review.stars', { count: n })` needs the plural form in **both**
  dictionaries; i18n currently has no key drift and this must not introduce any.
