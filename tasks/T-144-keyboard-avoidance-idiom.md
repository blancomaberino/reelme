# T-144 — The two biggest forms have no keyboard avoidance, and the five that do use the wrong Android idiom

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-104 (design tokens + shared components)
- **Target paths:** `apps/mobile/app/shares/[id]/review.tsx`,
  `apps/mobile/app/profile/edit.tsx`,
  `apps/mobile/src/components/auth-screen-layout.tsx`,
  `apps/mobile/app/restaurant/offer.tsx`,
  `apps/mobile/app/settings/privacy.tsx`,
  `apps/mobile/src/components/place/report-sheet.tsx`,
  `apps/mobile/src/components/place/suggest-edit-sheet.tsx`

Mobile review 2026-08-19, finding **MOB-6**.

## Context

Two halves.

**The two largest forms in the app have no keyboard avoidance at all.**
`shares/[id]/review.tsx:211` — the share-correction flow, the product's core
correction path — and `profile/edit.tsx:71` are bare ScrollViews with no
`KeyboardAvoidingView` and no `automaticallyAdjustKeyboardInsets`. Tap the
address or dish fields low in the form and the keyboard covers both the field
and the Publish button, with no way to scroll it up.

**All five sites that DO use it use the wrong Android idiom.**
`auth-screen-layout.tsx:22`, `restaurant/offer.tsx:175`,
`settings/privacy.tsx:232`, `report-sheet.tsx:142` and
`suggest-edit-sheet.tsx:162` each pass

```tsx
behavior={Platform.OS === 'ios' ? 'padding' : undefined}
```

— the pre-edge-to-edge idiom that relied on Android window resize, which
**RN 0.86 / SDK 57 no longer does by default**. So on Android the same failure
now happens in the "type DELETE to confirm" account-deletion sheet.

## Sibling of T-133, not a duplicate

This is the explicit decision, recorded because a silent duplicate was the
alternative.

- **T-133 owns the sheet chrome** — SheetShell adoption across `save-to-list`,
  `menu-sheet`, `add-to-list-search`, `quick-share`.
- **T-144 owns the keyboard-avoidance idiom** app-wide.

The file sets **do not overlap at all**, and neither task blocks the other, so a
`depends_on` between them would be false and would stall whichever was picked
second. A cross-reference was added to T-133's notes instead, so that task does
not hand-roll a sixth copy of the broken idiom while removing copies of
something else.

If T-144 lands first, T-133 consumes its primitive. If T-133 lands first, T-144
retro-fits SheetShell.

## Implementation

**Do not fix this by wrapping seven components in seven `KeyboardAvoidingView`s
with corrected props.** Five copies already drifted together — that *is* the
finding. One primitive, seven consumers.

Android needs an explicit `behavior="padding"` with a `keyboardVerticalOffset`,
not `undefined`. Verify the offset against a screen with a header and one
without; a single constant will not fit both, and that is a reason to put the
logic in the primitive rather than in each caller.

## Acceptance criteria

- [ ] `shares/[id]/review.tsx` keeps the address and dish fields **and** the
      Publish button reachable with the keyboard up, on both platforms —
      screenshotted on a device
- [ ] `profile/edit.tsx` likewise
- [ ] ONE keyboard-avoidance primitive; all seven sites use it, and Android gets
      an explicit `behavior="padding"` with a `keyboardVerticalOffset`
- [ ] The Android account-deletion sheet ("type DELETE to confirm") is reachable
      with the keyboard up — it is the regression this idiom already caused
- [ ] A check proves no site passes
      `Platform.OS === 'ios' ? 'padding' : undefined` any more

## Gotchas

- **Verify on a device, both platforms.** A keyboard overlap is invisible to
  jest by construction, and the Android half is specifically about a platform
  behaviour that changed *under* the app. Navigate with Maestro per CLAUDE.md
  and restore the simulator afterwards.
- `automaticallyAdjustKeyboardInsets` on a ScrollView is a legitimate
  alternative to `KeyboardAvoidingView` on iOS and does nothing on Android.
  If the primitive uses it, it still owes Android an answer.
- The review screen is the one that matters most. If time runs short, that is
  the one to get right on a device — it is the flow the product is built around.
