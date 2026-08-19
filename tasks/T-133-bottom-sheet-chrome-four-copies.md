# T-133 — Four hand-rolled bottom sheets; two hide their own input behind the keyboard

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-104 (design tokens + shared components)
- **Target paths:** `apps/mobile/src/components/sheet-shell.tsx`,
  `apps/mobile/src/components/place/save-to-list.tsx`,
  `apps/mobile/src/components/place/menu-sheet.tsx`,
  `apps/mobile/src/components/place/add-to-list-search.tsx`,
  `apps/mobile/src/components/map/quick-share.tsx`

Code review 2026-08-19, finding **CR-15** — MINOR by severity, and the cleanest
illustration of the wave's root cause.

## Context

`sheet-shell.tsx:37` exists **explicitly** to prevent this. Its own comment:

> Two copies of a modal is how the second one ends up 2pt off and a scheme
> behind.

It also documents that `SafeAreaView` reports a **zero bottom inset inside a
Modal**.

Only three components use it. `save-to-list.tsx:50`, `menu-sheet.tsx`,
`add-to-list-search.tsx` and `quick-share.tsx` each re-implement the chrome using
the `<SafeAreaView edges={['bottom']}>` pattern SheetShell documents as **broken**,
and none labels its backdrop `Pressable`.

The visible consequence: map pin → "Guardar" → "Nueva lista" mounts an
`autoFocus` `TextInput` and its Create button at the bottom of a bottom-pinned
modal with **no `KeyboardAvoidingView`** — the keyboard covers both.
`report-sheet.tsx` and `suggest-edit-sheet.tsx` have it; these do not.

A component wrote down the bug it prevents, and was then bypassed four times.

## Implementation

Invoke **`/frontend-design`** — this is a visual change and CLAUDE.md requires it.

Expect `SheetShell` to need a variant or two (scrollable content, a
keyboard-avoiding form) rather than four callers bending to a shape that does not
fit them. **If SheetShell cannot absorb a case, extend it.** A fifth hand-rolled
sheet with a comment explaining why is still a fifth hand-rolled sheet.

## Acceptance criteria

- [ ] `save-to-list`, `menu-sheet`, `add-to-list-search` and `quick-share` all
      render through `SheetShell`; a check proves no fifth hand-rolled copy of the
      chrome exists
- [ ] map pin → Guardar → Nueva lista keeps the `autoFocus` `TextInput` **and**
      its Create button visible with the keyboard up — verified on the simulator
      and reported with the click path
- [ ] Every backdrop `Pressable` carries an accessibility label
- [ ] SheetShell's zero-bottom-inset workaround exists in exactly one place

## Gotchas

- **A keyboard overlap is invisible to jest by construction.** Verification has to
  happen on a device: navigate with Maestro (never by setting a launch URL from
  the simulator CLI — see CLAUDE.md), screenshot the open sheet with the keyboard
  up, and restore the simulator afterwards.
- **File overlap:** `quick-share.tsx` is also touched by T-131 (the missing quota
  guard). No dependency; whichever lands second rebases.
- Four sheets converging on one shell will produce visual diffs on screens nobody
  asked you to change. That is expected — but screenshot each one, because "2pt
  off" is exactly what SheetShell's comment warns about and it will not show up in
  a test.
