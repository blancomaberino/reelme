# T-128 — `place.json` is wrong in both directions: hours nobody can render, reviews the schema does not have

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-102 (contracts: schemas + conformance tests), T-033 (mobile
  place detail), T-059 (native reviews)
- **Target paths:** `packages/contracts/schemas/place.json`,
  `packages/contracts/src/generated/`, `apps/mobile/src/lib/opening-hours.ts`,
  `apps/mobile/app/place/[slug].tsx`, `apps/mobile/src/api/places.ts`,
  `apps/api/tests/Feature/Places/PlaceContractTest.php`

Code review 2026-08-19, findings **CR-2 (BLOCKING)** and **CR-4 (IMPORTANT)**.
One task: same schema file, same regeneration step, same contract test — two PRs
would collide in `packages/contracts/src/generated/place.ts`.

## Context

### CR-2 — opening hours have never rendered for any user

Every writer stores a **flat list of strings**:

- `GooglePlacesGeocoder.php:100` → `array_values($weekdayText)`
- `WebsiteBusinessSource:104` → the same
- `SuggestPlaceEditRequest:70-71` → validates array-of-string
- `PlaceResource:102` passes it through untouched

Mobile types it `{ periods?, weekday_text? }`, and `opening-hours.ts:38`
`summarizeHours` reads `hours?.periods` and `hours?.weekday_text` off a plain
**array** — both `undefined` — returning `{openNow: null, label: null, weekly: []}`.
`place/[slug].tsx:220` gates the entire hours row on `hours.label`, which is
always null.

**Hours are fetched from Google, billed for, stored and sent — and no user has
ever seen them.**

Why the contract missed it: `place.json:40` types the field
`["object","array","null"]`, so it validates both shapes and pins neither, and
the generated type is `{} | unknown[] | null`. That is the real lesson — *a union
that admits the wrong shape is not a contract*, and it is what let the mobile
side invent its own reading. `src/api/suggestions.ts:19` even carries a comment
asserting the API writes Google's object form; false for every API code path.

### CR-4 — the contract is invalid for 100% of production place-detail responses

`PlaceResource.php:156` emits `reviews`. `place.json:7` is
`additionalProperties: false` with no such property.

It is structurally unobservable because `PlaceContractTest.php:74` validates
`?include=sources,offers` — **the one include-set the app never sends**.
`usePlace.ts:12` sends `sources,reviews,offers`.

Consequence: nothing generated from `place.json` can carry reviews, so `AppReview`
is hand-written (`places.ts:95`) and **has already drifted** — it omits
`updated_at` and `author.name`, both of which `ReviewResource:24` sends. A rename
in `ReviewResource` ships green and lands as blank review rows.

## Implementation

- Narrow `opening_hours` to an array of strings, regenerate, change
  `summarizeHours` to take `string[]`, and make the hours row render.
- Add `reviews` to `place.json`, regenerate, delete the hand-written `AppReview`
  and its drift.
- Change the contract test to the include-set the app actually sends — and
  **generalise it**. A contract test exercising an include-set no client sends is
  a gate that cannot fail: the same shape as T-114 and T-116. If the app's
  include-set can be derived rather than restated in two places, derive it.
- Reconcile every writer with the narrowed type, including the ones the review
  did not name: `PlaceMerger` and the Filament `PlaceForm`.

## Acceptance criteria

- [ ] `opening_hours` is an array of strings, regenerated, `summarizeHours` takes
      `string[]`, **and the hours row renders on a real place** — verified on the
      simulator and reported with the click path
- [ ] `place.json` declares `reviews`; the generated type replaces the
      hand-written `AppReview` and carries `updated_at` and `author.name`
- [ ] `PlaceContractTest` validates `?include=sources,reviews,offers` and FAILS
      when a field is renamed in `ReviewResource`
- [ ] Every writer is reconciled: geocoder, `WebsiteBusinessSource`,
      `SuggestPlaceEditRequest`, `PlaceMerger`, Filament `PlaceForm`
- [ ] The stale comment at `src/api/suggestions.ts:19` is corrected or deleted

## Gotchas

- **Regeneration is a CI gate.** `.claude/settings.json` regenerates contracts on
  a schema edit; stale generated output is an automatic CI failure. Commit the
  regenerated files.
- Existing rows are already `string[]`, so no data migration is needed — but
  check for any row written by an older path before assuming that.
- Verify the hours row **on device**. This is a rendering bug that every test in
  the suite agreed was fine; jest agreeing again proves nothing. Navigate with
  Maestro — never by setting a launch URL from the simulator CLI, per CLAUDE.md's
  wiring rules — and restore the simulator afterwards.
- Launch the `contract-consistency-reviewer` agent before the PR — this task is
  exactly its brief: Resource ↔ schema ↔ mobile TS, where `tsc` and Pest each see
  only one seam.
