# T-155 — "Open now" can only come back on structured hours, not on a guess

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-128 (the flat `string[]` hours contract)
- **Target paths:** `apps/api/app/Services/Geo/GooglePlacesGeocoder.php`,
  `apps/api/app/Services/Geo/BusinessDetails.php`,
  `apps/api/database/migrations/` (new column),
  `apps/api/app/Http/Resources/PlaceResource.php`,
  `packages/contracts/schemas/place.json`,
  `apps/mobile/src/lib/opening-hours.ts`,
  `apps/mobile/app/place/[slug].tsx`

**This task starts with an owner decision, not with code.** It is filed to hold
the question open, not to imply the answer is yes.

## What was removed, and why that was right

Before T-128 the collapsed hours row on the place detail read **"Open now ·
closes 23:00"** in `c.green`, or **"Closed"** in `c.danger`. T-128 deleted
`summarizeHours()` and the row now shows the static label "Opening hours".

The removal was correct and should not be quietly undone. `summarizeHours()`
parsed `{periods, weekday_text}` — a shape **the API has never sent**. Every
writer stores a flat list of strings, so the parse yielded `undefined`, the
label was always `null`, and the row rendered for nobody for months. When the
row was fixed to render, keeping a status cue would have meant inferring
open/closed from prose like `"Monday: 11:00 AM – 11:00 PM"`, whose weekday
ordering is locale-dependent, whose meridiem is sometimes absent, and which
carries no timezone. A confidently wrong **"Closed"** on a place that is open —
sending someone away from a restaurant that wanted their business — is worse
than showing no status at all.

Four independent reviewers reached that conclusion separately during the T-128
audit. The UI reviewer also flagged, correctly, that a real affordance was lost:
someone opening this screen to decide *"can I go now?"* gets no signal until they
tap. Both things are true, which is why this is a task and not a bug.

## The decision

**Is a live open/closed cue worth the data work it requires?** Restoring it
honestly is not a UI change — it needs structured hours and a timezone the
system does not currently store. If the answer is no, close this task and the
static label stands as the deliberate end state.

If yes, the sub-decision that matters more: **what is shown when the data is
absent, partial, or stale?** The only acceptable answer is *nothing* — the row
falls back to today's static label. A status cue that guesses when it does not
know recreates the exact bug this task exists because of.

## What "yes" actually requires

1. **The structured data is already paid for and thrown away.**
   `GooglePlacesGeocoder::BUSINESS_FIELDS` requests `opening_hours`, and Google
   returns `{open_now, periods, weekday_text}` — but `mapBusinessDetails()` takes
   `weekday_text` and drops `periods` on the floor
   (`GooglePlacesGeocoder.php:106`). So the input exists at no extra API cost;
   it is discarded at the mapper.
2. **A timezone does not exist anywhere.** No `places` column, and
   `utc_offset_minutes` is not in `BUSINESS_FIELDS`. A place's hours are
   meaningless without it — the server and the device can both be somewhere
   else. Adding that field may move the Places SKU, so **price it before
   committing**; the comment at `GooglePlacesGeocoder.php:27` says the current
   field set was chosen with billing in mind. Prefer a real IANA zone over a
   fixed UTC offset, because an offset is wrong for half the year wherever DST
   applies.
3. **A NEW nullable column and contract field, beside `opening_hours` — never
   folded into it.** `opening_hours` stays the flat `string[]` the client renders
   verbatim. The whole of T-128 was one field carrying two rival shapes; a
   union that admits both is what hid the original bug, and
   `schema-strictness.test.ts` now fails a schema that unions object with array.
4. **The hard cases are the whole job**, and each needs a test: a span crossing
   midnight (open 20:00–02:00), a 24-hour place, a day with no entry at all
   (closed vs unknown — not the same thing), a device in a different timezone
   from the place, and a DST transition. `open_now` from Google is computed at
   *fetch* time and is stale by the time it is cached — do not serve it.
5. **Only then the UI**, reusing the tokens the removed styles used (`c.green` /
   `c.danger`) rather than inventing new ones, and keeping the collapsed row a
   single line.

## Non-goals

- Reordering, translating, or otherwise "fixing" `weekday_text`. It is the
  source's own wording and language, and the client renders it verbatim by
  design.
- Highlighting "today" in the expanded list from `weekday_text` position —
  its ordering is not a fixed weekday index. That becomes possible for free once
  `periods` exists, and not before.

## Acceptance criteria

- [ ] The owner's decision is recorded — either this task is closed with the
      static label as the intended end state, or the answer to "what shows when
      the data is missing" is written down before code starts
- [ ] If proceeding: structured periods and a timezone are stored, served on a
      NEW nullable contract field, and `opening_hours` remains an unchanged flat
      `string[]`
- [ ] A place with no structured data, partial data, or no timezone shows the
      static label and NO status cue — asserted by a test, not by inspection
- [ ] Midnight-crossing, 24-hour, closed-day, unknown-day, foreign-timezone and
      DST-transition cases each have a test asserting the rendered status
- [ ] Google's `open_now` is not served to the client
- [ ] The Maestro flow asserts the cue on a seeded place whose status is known
      at the time the flow runs, or asserts its absence — never a bare screenshot
- [ ] `packages/contracts` regenerated and committed; the schema-strictness
      suite still passes (no object/array union, `additionalProperties: false`,
      non-empty `required`)

## Notes

Filed 2026-09-03 from the T-128 agency audit (six reviewers over the branch;
PR #201). The UI lane raised it as a lost affordance and the UX lane
independently confirmed that computing no status was the right call given the
available data — so the finding is real and the fix is a data task, deliberately
not folded into T-128's diff.
