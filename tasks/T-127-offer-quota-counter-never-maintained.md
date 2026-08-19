# T-127 — `offers.redemptions_count` is read everywhere and written nowhere

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-043 (redemptions: issue/verify + anti-fraud),
  T-044 (double-entry ledger)
- **Target paths:** `apps/api/app/Services/Redemptions/RedemptionIssuer.php`,
  `apps/api/app/Models/Offer.php`,
  `apps/api/app/Console/Commands/ExpireRedemptions.php`,
  `apps/api/app/Http/Resources/OfferResource.php`,
  `apps/api/database/factories/OfferFactory.php`,
  `apps/api/tests/Feature/Redemptions/`

Code review 2026-08-19, finding **CR-1 — BLOCKING**. The only finding in this
wave that costs a third party real money.

## Context

`Offer::hasTotalQuotaLeft()` compares `redemptions_count < quota_total`, and its
docblock states the invariant:

> Compares against `redemptions_count`, which T-043 keeps as the count of
> NON-VOID redemptions: a voided or expired redemption returns its slot to the
> quota, otherwise a run of abandoned codes would silently retire an offer the
> restaurant is still paying to run.

**No code does that.** A sweep of `app/`, `database/migrations/` and
`app/Console/` finds only reads, the migration default `0`, and the factory: no
increment in `RedemptionIssuer`, no decrement in
`RedemptionVoider`/`ExpireRedemptions`, no DB trigger, no observer.
`followers_count` **is** maintained in `FollowController`, so this is an omission,
not a convention.

### In the restaurant's terms

"First 50 diners get a free dessert", `quota_total: 50`. The 51st, 500th and
5000th `POST /api/v1/redemptions` all succeed. `GET /offers/{id}` reports
`redemptions_count: 0` and `remaining_quota: 50` forever, so the operator sees no
warning, and `PlaceQueryBuilder::onlyActiveOffers()` keeps badging the sold-out
venue as having an active offer.

### Why it is green — the more important half

Every quota test uses `OfferFactory::quotaExhausted()`
(`database/factories/OfferFactory.php:86`), which hand-sets
`redemptions_count => $quota` on an **unsaved `->make()`**. The *rule* is tested.
The *maintenance* is tested nowhere and does not exist.

**Any fix that leaves that factory as the only way the counter is ever non-zero
has not done this task.**

## Implementation

### Maintain the counter; do not delete it

The reviewer offers "or delete the cache and have `hasTotalQuotaLeft()` count
rows, as the per-day guard already does". Grounding that was not in the review:

- `PlaceQueryBuilder.php:128` does
  `->orWhereColumn('redemptions_count', '<', 'quota_total')` **in SQL**, in the
  map's hot query path. Removing the column pushes a correlated subquery in
  there.
- `OfferResource.php:54` exposes it as, per its own comment, *"the operator's
  headline number"* — a contract field, generated into
  `packages/contracts/src/generated/`.

So: keep the column, maintain it, and add reconciliation.

### Where

The lock already exists. `RedemptionIssuer.php:96` takes
`Offer::query()->whereKey(...)->lockForUpdate()` precisely so quota reads and the
insert cannot interleave, with a comment spelling out the overshoot it prevents.
The increment belongs **inside that transaction**, after `assertMayIssue`.

The decrement belongs on void and on expiry — `ExpireRedemptions` and the void
path — so the docblock's promise about returning slots becomes true.

### Reconciliation

A counter cache with no reconciliation is how the next silent drift happens. A
command that recomputes from rows and reports drift is the safety net, and
running it on a seeded database is the proof.

## Acceptance criteria

- [ ] The 51st redemption of a `quota_total: 50` offer is **refused** — proven by
      issuing 51 for real through `POST /api/v1/redemptions`, never by a
      `quotaExhausted()` `->make()`
- [ ] Voiding or expiring a redemption returns its slot and the offer becomes
      redeemable again
- [ ] The counter is maintained inside the existing `lockForUpdate` transaction;
      two concurrent issues against a quota of 1 yield exactly one redemption
- [ ] `GET /offers/{id}` reports a truthful `redemptions_count` /
      `remaining_quota`, and `onlyActiveOffers()` stops badging a sold-out venue
- [ ] A reconciliation command recomputes from rows, reports drift, and reports
      zero on a seeded database

## Gotchas

- `OfferFactory::quotaExhausted()` should keep working for tests that genuinely
  want an exhausted offer — but the **quota-enforcement** tests must stop using
  it. Consider making it save, so the state it fabricates is at least reachable.
- Money and concurrency is the strongest area of this codebase and the review
  found nothing else wrong with it. Match the standard already set next door:
  `lockForUpdate` + a guarded conditional `UPDATE` + a CHECK constraint.
- A `CHECK` or partial index that makes overshoot impossible at the database
  level is worth considering alongside the counter — the counter is then an
  optimisation rather than the only thing standing between a venue and 5000 free
  desserts.
