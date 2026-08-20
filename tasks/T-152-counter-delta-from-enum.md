# T-152 — The counter's delta lives in three call sites, and its enum helper has no callers

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-127 (offer quota counter)
- **Target paths:** `apps/api/app/Services/Redemptions/OfferQuotaCounter.php`,
  `apps/api/app/Enums/RedemptionStatus.php`,
  `apps/api/app/Services/Redemptions/RedemptionIssuer.php`,
  `apps/api/app/Services/Redemptions/RedemptionVerifier.php`,
  `apps/api/app/Services/Ledger/RedemptionVoider.php`,
  `apps/api/app/Console/Commands/ExpireRedemptions.php`,
  `apps/api/tests/Feature/Monetization/`

Found 2026-08-20 by the T-127 review fan-out (altitude lens). T-127 shipped the
cheap half of this — a comment naming the fourth transition — deliberately
leaving the structural half here.

## There are four transitions, not three

`offers.redemptions_count` is maintained at three call sites: issue (+1),
void (−1), expire (−N). The fourth is **verify** (`issued → redeemed`), which
moves the counter by **zero** — and is correct only because
`RedemptionStatus::holdingQuota()` happens to contain both states.

Nothing structurally records that. Narrow `holdingQuota()` to `[Redeemed]` when
refunds land and the change looks total — every `whereIn`, `hasTotalQuotaLeft()`
and `OfferQuotaReconciler` follow the enum automatically — while `claim()` keeps
adding +1 at issue and `RedemptionVerifier` keeps adding 0. The writers and the
reconciler would then disagree **permanently**, and the nightly job would report
drift on every offer forever, which is indistinguishable from the regressed-
writer alarm it exists to raise.

`RedemptionStatus::holdingQuota()`'s own docblock already anticipates the set
changing: *"three hand-written `whereIn` clauses is three places to forget
`void` when refunds land."* The counter is now a fourth place, and unlike the
queries it does not follow the enum.

### `holdsQuota()` is dead

Verified on `main` at `c87a2ed`: `RedemptionStatus::holdsQuota()` — the
per-case predicate — has **no callers anywhere**. Only the static
`holdingQuota()` list is used. Either it becomes the mechanism below or it
should go; a predicate nobody calls is a comment that costs a test.

## Implementation

One entry point that derives the delta from the enum rather than from the
caller's memory:

```php
OfferQuotaCounter::applyTransition(
    int $offerId, ?RedemptionStatus $from, RedemptionStatus $to, int $rows = 1
): bool
```

computing `holdsQuota($to) - holdsQuota($from)`. Issue passes `(null → Issued)`,
verify passes `(Issued → Redeemed)` and provably moves nothing, void and expire
pass their pairs. The enum becomes the single definition of the counter, the way
it is already the single definition of the queries.

## Acceptance criteria

- [ ] Narrowing `RedemptionStatus::holdingQuota()` in a test makes the WRITERS
      follow it — proven by a test that changes the set and asserts the counter
      still agrees with the rows, which fails on today's code
- [ ] Verify (`issued → redeemed`) is expressed as a transition that computes to
      zero, not as an absence of code
- [ ] `holdsQuota()` either backs the new mechanism or is deleted; it does not
      remain uncalled
- [ ] The claim still returns a business decision the issuer can refuse on, and
      still happens inside the existing `lockForUpdate` transaction — T-127's
      concurrency tests pass unchanged
- [ ] Every T-127 mutation still bites: removing the cap predicate, the release
      floor, the void guard or the expiry transaction each turns tests red

## Gotchas

- This reshapes three call sites and the claimed/refused return contract. The
  correctness T-127's tests pin has to be re-pinned, not assumed — run the
  mutation table again rather than trusting a green suite.
- `claim()` must keep carrying `quota_total` in its own `WHERE`. The delta is
  what changes; the guarded conditional UPDATE is not.
- Expire passes `$rows` = what its guarded UPDATE actually flipped, never what
  it asked to flip. A generalized entry point must not lose that distinction.
