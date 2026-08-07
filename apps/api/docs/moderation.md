# Moderation, reports and takedowns (T-049)

Reelmap is a user-generated-content app. Apple Guideline 1.2 and Google's UGC
policy both require a report path and evidence of actual moderation before the
app can ship, and R-07/IR-2 require a way for a rightsholder to get their
material removed. This is how both work.

## Reporting — the one public route

`POST /api/v1/reports` with `{reportable_type, reportable_id, reason, details?}`.

| | |
|---|---|
| `reportable_type` | `place` · `share` · `user` · `source_post` · `offer` — morph **aliases**, never class names |
| `reason` | `spam` · `wrong_place` · `inappropriate` · `copyright` · `fraud` · `other` |
| 201 | filed |
| 409 | you already filed this exact report — **a success from the user's side**. The app treats it as done and shows the same "thanks, we're on it" confirmation as a 201 |
| 422 | unknown type/reason, a target id that does not exist, or your own account |

Unique on `(reporter, target, reason)`. Without that, one motivated person can
manufacture a pile of reports that looks like consensus — and the queue sorts by
count. The reason is part of the key on purpose: "this is spam" and "this is my
footage" are different complaints with different handling.

**There is no admin REST surface** (03 §2.16 is binding). Triage and takedown
live in Filament, which keeps the most destructive operations in the system
behind one authorization surface instead of two.

## In the app

Report is a first-class control, not an overflow-menu item — a reviewer looks
for a *visible* path. It sits in the action row on place detail and beside
Follow on a public profile. The sheet is shared (`src/components/report-sheet.tsx`);
three near-identical copies would drift, and the one that drifted would be the
one the reviewer opened.

## Triage — `/admin/reports`

Default view is unhandled reports, **oldest first**. The metric a store reviewer
asks about is time-to-response, and newest-first quietly starves the reports
that have waited longest. Urgent reasons (`copyright`, `fraud`,
`inappropriate`) badge red — they carry legal, financial and review consequences
that grow by the hour.

Each row shows a **total count against the same target**. One report is a
complaint; six is a pattern, and an admin deciding without that number is
deciding on a fraction of the evidence.

Actions — every one requires a written note:

| Action | What it does |
|---|---|
| **Take down** | Routes to the existing moderators: `ShareModerator` (unpublish + recount pins) or `PlaceModerator` (`PlaceStatus::Hidden`). |
| **Ban user** | The T-008 mechanism: revoke every token, soft-delete. Financial history untouched. |
| **Dismiss** | Closed with no action. |

"Also close other reports about this same target" is on by default. Six reports
about one share are one decision, and making an admin close them individually is
how a queue stops being worked.

> **The note is not ceremony.** A takedown dispute, a DMCA counter-notice and an
> app-store appeal all ask the same question — *why did you remove this* — and an
> audit trail of bare timestamps answers none of them. Grep
> `moderation.report.actioned`.

### What has no take-down, and why

`source_post` and `offer` are reportable but not removable from this queue, and
the action says so rather than reporting a no-op as success:

- a **source post** is shared between users — removing it takes other people's
  places with it. Copyright complaints about one go through the takedown flow.
- an **offer** is the venue's business record; pulling it is an offer-status
  change, not a moderation hide.

## Takedowns / DMCA — `/admin/takedowns`

**Intake is `dmca@reelmap.app`,** entered into Filament by ops. Deliberately no
public endpoint: a self-service takedown would let anyone unpublish anyone
else's places by asserting a claim, and verifying the claim is the part that
needs a human.

Actioning claims the notice atomically (`received`/`counter_notice` →
`processing`) before touching anything: two admins working the queue can both
press the button before either finishes, and without the claim both runs remove
the same content and write competing records of what was removed. A second
press hands back what the first run recorded.

Log the notice first and match the post later — a notice usually arrives as a
bare URL, and refusing to record it until someone finds the row is how notices
get lost. "Action it" then:

1. unpublishes **every** share citing that post (a popular reel has several),
2. removes the `place_sources` rows citing it,
3. deletes the stored media (objects **and** rows),
4. nulls the post's `caption`, `oembed_json` and `transcript_json`,
5. **keeps the `source_posts` row**, and **keeps the places** (FR-30).

> **The place survives. This is the whole design.** A rightsholder is objecting
> to their footage, not to a restaurant existing — and other people may have
> contributed the same place independently. Deleting the pin would answer a
> copyright complaint by destroying unrelated users' work. The `source_posts`
> row survives for the same reason a receipt does: other analytics reference it,
> and a counter-notice asks what was there.

The outcome is written to `outcome_json` (shares, sources, media, media_failed,
places kept, places revived), which is what a response letter is composed from.
`media` counts objects actually **gone** — a delete the bucket refused is
counted in `media_failed`, because telling a rightsholder we removed a file that
is still in storage is the one number here that must never be optimistic.

`places_revived` are places whose last source this notice removed. They are kept
(FR-30) but returned to `pending` with `needs_admin_review`, because a pin with
no provenance left belongs in the review queue rather than silently on the map.

`counter_notice` is a real status, not a note on `closed`: the DMCA gives the
uploader a right of reply, and a system that cannot represent "they disputed it"
cannot show the reply was considered.

## Log lines

`moderation.report.actioned` · `moderation.takedown.processed` ·
`moderation.takedown.media_delete_failed`

Same standard as `docs/gdpr.md`: no direct identifiers, but `user_id`/`admin_id`
are pseudonymous and application logs are in scope for the controls that implies.
