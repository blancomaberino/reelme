# T-161 — The review screen asks one question, not forty-two

- **Phase:** GROW · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:**
  `apps/mobile/app/shares/[id]/review.tsx`,
  `apps/mobile/src/i18n/es.ts`

## Why

Straight after tapping Share on a reel, the user is handed four sections, roughly
thirteen text fields and 42 chips (9 category + 23 vibe + 10 dietary), a candidate
picker and a draggable pin -- with exactly two exits: Confirmar or Descartar.
Every ambiguous extraction is therefore a fork where one branch is data entry and
the other is losing the place you just saved.

The escape already exists and is offered only on the failure path:
`usePublishBestGuess`. This task promotes it to the default and moves everything
else behind a disclosure. Nothing is removed -- correcting a field must keep
working exactly as it does now.

## Acceptance

- The primary action publishes the best guess without requiring any field to be touched; a test presses it on a low-confidence extraction and asserts a published place
- Name, location confirmation and the primary action are visible without scrolling; every other field remains reachable behind a disclosure
- Correcting a field and publishing still produces the corrected values (regression test over the existing path)
- Discard is no longer the only alternative to completing the form
- The screen keeps its keyboard avoidance and VoiceOver labels (relates to T-144, T-141)

## Notes

Filed 2026-09-03. Pure surfacing task -- publishBestGuess already exists at share.tsx and is wired only to failures. No fields are deleted; they are relocated.
