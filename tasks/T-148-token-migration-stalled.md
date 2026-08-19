# T-148 — The design-token migration stalled, under a rule that reads as if it were closed

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-104 (design tokens + ScreenHeader + Button variants),
  T-114 (the mobile lint gate cannot fail)
- **Target paths:** `apps/mobile/eslint.tokens-legacy.js`,
  `apps/mobile/eslint.config.js`, `apps/mobile/src/components/sheet-shell.tsx`,
  `apps/mobile/src/components/map/pin-glyph.tsx`,
  `apps/mobile/app/(main)/map.tsx`

Mobile review 2026-08-19, finding **MOB-15**. No user-visible failure today —
this is the drift field regrowing under a rule that reads as if it were closed.

## Context

### The exemption list has never shrunk

`eslint.tokens-legacy.js` still holds **all 55 files it was born with**
(verified: 55 entries), and `git log --follow` shows exactly **one** commit —
T-104 (`62755dd`) — across 39 subsequent commits, several of which edited exempt
files (`(main)/map.tsx` in T-047) without migrating them.

The rule's own header says *"this list may only ever get shorter."*

### Two holes make it weaker than it reads

- `TOKENIZED_PROPS` covers 4 of roughly 10 spacing properties — no `margin*`,
  `width`, `height`, `lineHeight`, `borderWidth` — so a **post-token** file has
  already drifted: `sheet-shell.tsx:140,142` carry `marginBottom: 10` and `4`.
- The config states *"nothing hard-codes a hex"*, which `pin-glyph.tsx:136,152`
  (`#2B2320`, `#8A5E12`) and `cluster-marker.tsx:56` contradict.

### Same shape as T-114, one layer down

There, a lint gate ran and reported nothing. Here, a lint rule runs and reports
**less than its own comment claims**. In both cases the artefact that was
supposed to hold the line reads as if it is holding it — which is the reason to
do this while T-114/T-116 are fresh.

The honest outcomes are:

- burn the list down on a schedule **and** widen `TOKENIZED_PROPS`; **or**
- delete the aspiration from the header so it stops reading as a live invariant.

**What is not acceptable is leaving a comment that describes a policy nobody is
running.**

## Acceptance criteria

- [ ] The exemption list is SHORTER than the 55 files it was born with, and the
      header states a real policy — a schedule that is being kept, or no
      aspiration at all
- [ ] `TOKENIZED_PROPS` covers the properties it currently misses (`margin*`,
      `width`, `height`, `lineHeight`, `borderWidth`) — proven by the
      post-token drift it should have caught (`sheet-shell.tsx:140,142`)
- [ ] The "nothing hard-codes a hex" claim is true, or removed —
      `pin-glyph.tsx:136,152` and `cluster-marker.tsx:56` currently contradict it
- [ ] The 11 dead style entries in `app/(main)/map.tsx:640-673` (`locateHint*`,
      `zoom*`) are deleted, since `map-controls.tsx` owns them now
- [ ] Widening the rule FAILS on a deliberately introduced violation — proven
      both ways, the T-114 discipline

## Gotchas

- **Widening `TOKENIZED_PROPS` will light up the 55 exempt files at once.** That
  is expected. Decide fix-vs-baseline per file, and keep the baseline *listed*
  rather than silent — the T-114 lesson.
- Some hard-coded hexes are legitimately not tokens (a brand glyph, a map pin
  that must not follow the theme). If so, the config's claim is what is wrong,
  and saying so is a valid outcome — but say it, in the file.
- The dead styles in `map.tsx` are cheap and worth taking here: the next edit to
  that hint's styling lands on the dead copy. That is this wave's root cause in
  its smallest form.
