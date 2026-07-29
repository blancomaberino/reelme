# T-106 — Place model decomposition wave 2: field-locking, Scout, geo and scopes out of the model

- **Phase:** ARCH (audit wave 2) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-096
- **Target paths:**
  - `apps/api/app/Models/Place.php`
  - `apps/api/app/Models/Concerns/`
  - `apps/api/app/Models/Builders/PlaceQueryBuilder.php`
  - `apps/api/tests/`

## Context (codebase audit, 2026-07-29)

Audit finding A2 (2026-07-29). T-096 already took Place from 787 to 636 lines by extracting PlaceAggregations - this is the remaining half, not a duplicate. Place still carries five unrelated concerns and has 203 edges in the knowledge graph (the single highest), so its blast radius is the whole app. Do this BEFORE M4 monetization (T-042/T-043) adds offers and redemptions to the model.

## Acceptance criteria

- [ ] Field-locking (lockedFields / isFieldLocked / lockFields / withoutLockedFields) extracted to a Concerns\LocksFields trait or collaborator
- [ ] Scout projection (toSearchableArray / shouldBeSearchable / makeAllSearchableUsing) extracted to Concerns\SearchesAsPlace
- [ ] PostGIS I/O (setPoint / coordinates / normalizeName) extracted to Concerns\HasGeoPoint
- [ ] The 6 query scopes move to a PlaceQueryBuilder returned from newEloquentBuilder(); call sites keep the same fluent API
- [ ] Place drops below ~350 lines and owns persistence + relationships only
- [ ] No behavior change: PlaceResource / PlaceSummaryResource output byte-identical, contract tests + search tests green
- [ ] Gates green: composer lint + stan + test

## Notes

Filed from the 2026-07-29 architecture / design-patterns / UX audit (graphify knowledge
graph over 818 files, then direct reads of the hot paths). Follow the CLAUDE.md workflow:
branch from `main`, `/frontend-design` for any UI work, `/coderabbit` before the PR.

## Log

- **2026-07-29** — Filed from the codebase audit.
