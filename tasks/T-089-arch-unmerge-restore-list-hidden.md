# T-089 — ARCH/P0: unmerge() restores place_list_items / hidden_places

- **Phase:** ARCH (P0 data integrity) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-035 (dedup/merge review queue)
- **Target paths:** `apps/api/app/Services/Places/PlaceMerger.php`,
  `apps/api/app/Models/PlaceMerge.php`, `apps/api/database/migrations/`

## Context (audit finding, 2026-07-21)

`PlaceMerger.php:94-107` (own comment): *"unmerge() does NOT restore these onto the resurrected
loser ... a saved place stays on the survivor after an unmerge. Acceptable for now (admin-only,
rare); a full snapshot/restore ... is a follow-up."* Result: an admin "undo merge" is not a
full undo — a user's list membership / hidden-place state permanently migrates to the wrong
place after a merge-then-unmerge cycle.

## Implementation

- Extend `PlaceMerge.source_snapshot` to capture the loser's pre-merge `place_list_items` and
  `hidden_places` rows (alongside the `place_sources` snapshot that already exists).
- In `unmerge()`, restore those rows onto the resurrected loser, mirroring the `place_sources`
  restore path.

## Acceptance criteria

- [ ] Snapshot captures loser's `place_list_items` + `hidden_places` at merge time
- [ ] `unmerge()` restores them to the loser
- [ ] Test: user saves loser to a list + hides it → merge → unmerge → membership/hidden state
      returns to the loser (asserted), not stranded on the survivor
- [ ] Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit.
