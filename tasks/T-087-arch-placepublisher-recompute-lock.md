# T-087 — ARCH/P0: lock PlacePublisher::recompute() count-then-save

- **Phase:** ARCH (P0 correctness) · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-024 (publish flow), T-023 (resolve/place)
- **Target paths:** `apps/api/app/Services/Places/PlacePublisher.php`,
  `apps/api/app/Jobs/PublishShare.php`

## Context (audit finding, 2026-07-21)

`PlacePublisher::activateAndCount()` runs `recompute()`/`rollCounters()` **outside** the
publish transaction (deliberately, so a Scout failure doesn't roll back the publish), reading
`$place->sources()->...->count()` then `$place->save()` with **no `lockForUpdate()`**
(PlacePublisher.php:29-63). Two shares publishing to the same place concurrently (two
influencers posting the same restaurant) can each read the source count before the other's row
is visible; last `save()` wins with a smaller count — under-counting `shares_count` and
possibly missing the second-source activation trigger. Self-correcting on the next event, wrong
until then. `PlaceMerger` already locks both rows for exactly this reason.

## Implementation

- Wrap the count read + counter write in `Place::lockForUpdate()` inside a short transaction.
- Leave the Scout/tag work outside the lock (as today) — only the counter read+write needs it.

## Acceptance criteria

- [ ] `recompute()`/`rollCounters()` read+write counters under a row lock
- [ ] Two concurrent `activateAndCount()` paths on one place converge to the correct
      `shares_count` and `active` status (test serializes via locks and asserts final state)
- [ ] Gates: `composer lint` + `stan` + `test` green

## Log

- **2026-07-21** — Filed from the architecture audit.
