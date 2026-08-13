# T-112 — Free-text note on a suggested edit ("something else is wrong")

- **Phase:** M4 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-083 (suggested edits: table, service, queue, mobile sheet)
- **Target paths:** `apps/api/database/migrations/`, `apps/api/app/Models/PlaceEditSuggestion.php`,
  `apps/api/app/Services/Places/PlaceSuggestionService.php`,
  `apps/api/app/Http/Requests/Places/SuggestPlaceEditRequest.php`,
  `apps/api/app/Filament/Resources/PlaceEditSuggestions/`,
  `apps/api/app/Services/Gdpr/UserDataPurger.php`,
  `apps/mobile/src/components/place/suggest-edit-sheet.tsx`, `packages/contracts/schemas/`

## Context

Requested 2026-08-12, while verifying T-083. The suggest-an-edit form covers
name, street, city, phone and website. Everything else a person might want to
correct has nowhere to go — and the examples are the ones that matter most:

- "this place closed down"
- "the menu prices are out of date"
- "the photo is of a different restaurant"
- "the pin is on the wrong side of the street"

### Why not the existing report flow

The place screen ALREADY has a free-text box: the flag control files a report
with `wrong_place` + up to 2000 characters of `details`. It is the wrong home,
and knowing why is the point of this task:

- Reports land in the **triage** queue, whose verbs are take-down, ban and
  dismiss. A correction filed there is a moderation event against the venue.
- Two free-text boxes on one screen, going to two queues, means the person has
  to guess which — and moderators then find corrections in both.

So: corrections belong to the suggestion queue, abuse belongs to reports. The
two entry points must keep reading distinctly ("something here is wrong" vs
"report this place").

## Implementation

- **Migration:** `place_edit_suggestions.note` text nullable, bounded in
  validation (2000, matching `reports.details`).
- **Submit:** the "this changes nothing" 422 becomes "a field change **OR** a
  note". A note-only suggestion is valid and queues; `changes` is `{}`.
- **Owner submissions:** a verified operator's note has nothing to apply
  directly — it should queue like anyone else's rather than silently vanish into
  an auto-approved row with no patch. Decide and test this explicitly.
- **Moderation verb.** `approve()` means "apply the patch". A note-only row has
  no patch, so the queue needs **Actioned** for it: the moderator fixes the place
  by hand (or hides it), then settles the row with a required note of what they
  did. Same service, same audit discipline as approve/reject — see the
  `lockPending()` guard, which any new transition must also use.
- **Filament:** render the note in the row (it IS the finding for these), and
  make the note-only rows visibly distinct from field patches.
- **Mobile:** a multiline field at the foot of the sheet, with copy that says
  what it is for ("¿Otra cosa? Contanos"). Include it in the dirty check, so
  Enviar enables on a note alone.

## GDPR — the part that is NOT a copy of T-083

T-083 **anonymises** suggestions on erasure (keeps the row, nulls `user_id`) on
the reasoning that a field patch is the business's record, not the submitter's.

**A note is different.** It is free prose in a person's own words, which is
exactly where PII lands — and it is precisely why `UserDataPurger` *deletes*
reports (`details` is 2000 characters of the same thing) rather than anonymising
them. Adding a note therefore re-opens that decision:

- pending / rejected rows carrying a note → likely **delete** on erasure;
- approved rows → the applied patch is the venue's record; consider clearing the
  note while keeping the row, so the audit trail survives without the prose.

Whatever is chosen, `UserDataPurger` and `UserDataExporter` must BOTH be updated
and BOTH asserted by name — the T-110 lesson, which cost a blocking finding when
the export was the mirror-image gap of the purge.

## Acceptance criteria

- [ ] `note` column + validation (max 2000); a note-only suggestion is accepted
      and queues, and a submission with neither a field change nor a note is
      still refused
- [ ] Filament shows the note and offers **Actioned** on a note-only row, with a
      required record of what was done; the settled row cannot be re-decided
      (`lockPending`)
- [ ] Mobile: multiline field, included in the dirty check, with its own copy in
      both dictionaries
- [ ] GDPR: erasure handles notes per the decision above, asserted in BOTH
      `PurgeUserDataTest` and `DataExportTest` by name
- [ ] The report flow is untouched and still reads as the abuse channel; no
      second correction path
- [ ] Tests: note-only submit, note + fields, the empty-submission refusal, the
      Actioned transition, GDPR both ways; Pint/PHPStan L6/Pest + jest/tsc green

## Gotchas

- **This is a new abuse surface**: free text from any signed-in user onto an
  admin's screen. It rides the `reviews` limiter already (10/min, 100/day shared
  with reviews and reports), which is the right bucket — do not move it.
- Do not let "Actioned" become a way to settle a row that still has an
  unapplied field patch; the verb is for note-only rows.
- `changes` is `NOT NULL`; a note-only row stores `{}`, so every renderer must
  survive an empty diff (the Filament column already returns its `(nothing)`
  placeholder — check the mobile operator list too).
