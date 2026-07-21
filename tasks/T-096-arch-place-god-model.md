# T-096 — ARCH/P1: decompose Place god model + guard the discount SQL/PHP twin

- **Phase:** ARCH (P1 maintainability + latent-bug guard) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-023 (place/resolve), T-079 (card discounts), T-031 (tags/Scout)
- **Target paths:** `apps/api/app/Models/Place.php`, `apps/api/app/Services/Places/`,
  `apps/api/tests/`

## Context (audit finding, 2026-07-21)

`Place` (787 lines) spans Scout indexing (`toSearchableArray`/`shouldBeSearchable`), tag
aggregation (`aggregatedTags`), discount/card aggregation (`aggregatedDiscounts`/`discountCard`
plus the raw-SQL twins `DISCOUNTS_JSONB`/`DISCOUNT_CARD_SQL` kept in lockstep with the PHP by
**comment discipline alone**), PostGIS point I/O, curated-field locking, and five scopes. Not a
bug today, but high blast-radius and the SQL/PHP twin is an unenforced "two places must never
disagree" landmine.

## Implementation

- Extract `aggregatedTags()`/`aggregatedDiscounts()`/`discountCard()` into a `PlaceAggregations`
  value/service class; `Place` keeps persistence + relationships + geo I/O + locked-field API.
- Add a Pest test asserting the `DISCOUNT_CARD_SQL`/`DISCOUNTS_JSONB` constants and their PHP
  twins produce identical output over a fixture set of discount snapshots.

## Acceptance criteria

- [ ] Aggregation logic extracted; `Place` no longer owns it
- [ ] No output change: `PlaceResource`/`PlaceSummaryResource` byte-identical; contract drift green
- [ ] SQL↔PHP twin-drift test passes over fixtures
- [ ] Gates: `composer lint` + `stan` + `test` green

## Notes

Coordinate with **T-095** (both live in `Services/Places`).

## Log

- **2026-07-21** — Filed from the architecture audit.
