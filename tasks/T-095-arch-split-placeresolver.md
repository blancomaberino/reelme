# T-095 — ARCH/P1: split PlaceResolver (783 lines) + drop duplicate Jaro-Winkler

- **Phase:** ARCH (P1 maintainability) · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-023 (resolve/place dedup)
- **Target paths:** `apps/api/app/Services/Places/PlaceResolver.php`,
  `apps/api/app/Services/Places/`
- **Record the split as an ADR in the plan (07-risks-decisions.md).**

## Context (audit finding, 2026-07-21)

`PlaceResolver` (783 lines) mixes five concerns: the dedup decision tree
(`resolveOne`/`resolveLocked`/`resolveViaProfile`), raw PostGIS SQL (`scanCandidates`, :367),
a **hand-rolled Jaro-Winkler** (:717-782) that duplicates the Postgres `similarity()` trigram
call already computed at :373, place creation + column clamping (`create`/`createFromProfile`/
`truncate`/`priceRange`/`countryCode`), and per-canonical distributed locking. Largest
blast-radius file in Services.

## Implementation

- Extract `PlaceDedupMatcher` (scanCandidates / fuzzy / similarity).
- Extract `PlaceFactory` (create / createFromProfile / truncate / priceRange / countryCode).
- Keep `PlaceResolver` as a thin orchestrator over them + `GeoHints`/`InstagramProfileLocator`.
- Remove the hand-rolled Jaro-Winkler in favor of the Postgres `similarity()` already computed
  — or, if a second signal is genuinely wanted, document why in the ADR.

## Acceptance criteria

- [ ] Behavior-preserving: existing PlaceResolver tests pass unchanged
- [ ] New unit tests exercise the extracted matcher/factory in isolation
- [ ] Jaro-Winkler duplication removed or ADR-justified
- [ ] ADR recorded; Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit.
