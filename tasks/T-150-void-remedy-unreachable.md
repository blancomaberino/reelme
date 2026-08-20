# T-150 — The dispute remedy nothing can reach, pointing the wrong way

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-044 (double-entry ledger), T-127 (offer quota counter)
- **Target paths:** `apps/api/app/Services/Ledger/RedemptionVoider.php`
  (moves), `apps/api/app/Events/`, `apps/api/app/Listeners/`,
  `apps/api/app/Filament/Resources/`, `apps/api/routes/api.php`,
  `apps/api/tests/Feature/Monetization/`

Found 2026-08-20 by the T-127 review fan-out. Two findings that share one file
and should be fixed in one pass, because the fix for either moves it.

## 1. `RedemptionVoider` has no production caller

Verified on `main` at `c87a2ed`: a grep of `app/`, `routes/` and `database/`
for `RedemptionVoider` returns exactly one hit, and it is a docblock mention in
`OfferQuotaCounter`. No route, no console command, no Filament action. There is
no `Redemptions` Filament resource at all, and `OfferResource` is read-only.

So 06 §4.4's dispute window — "within the dispute window an admin voids it" —
**has no surface**. The class is exercised only by tests.

### T-127 made this cost more

Before T-127 a wrong scan cost the venue one fee, which ops could correct with
a hand-written reversing entry. Now it *also* permanently consumes one of the
offer's lifetime slots, and `reelmap:offers:reconcile-quotas --fix` **cannot
give it back**: the row is `redeemed`, so it legitimately holds a slot by the
reconciler's own definition. A venue whose staff mis-scans ten times on a
50-slot offer is billed for ten visits it did not get *and* silently loses 20%
of the promotion it paid for, with no in-product remedy for either.

## 2. The dependency arrow points the wrong way

`RedemptionVerifier` (Redemptions) flips the row and dispatches
`RedemptionVerified`, which `PostRedemptionLedgerEntries` (Ledger) consumes
in-transaction. Redemptions → Ledger.

`RedemptionVoider` lives in `App\Services\Ledger` and now flips the redemption
row *and* mutates `offers.redemptions_count`. Ledger → Redemptions.

The inversion predates T-127, but T-127 is what made it load-bearing: Ledger is
now the authority on another aggregate's quota. `App\Services\Ledger` has
stopped being a leaf. The next person adding a redemption-state concern finds
two precedents pointing opposite directions and picks by coin flip.

## Implementation

Move `RedemptionVoider` into `App\Services\Redemptions`, have it dispatch a
`RedemptionVoided` event inside its existing transaction, and add a
`ReverseRedemptionLedgerEntries` listener mirroring
`PostRedemptionLedgerEntries` — non-queued, in-transaction, idempotent by key
(`LedgerService::reverse()` already is). The guarded conditional UPDATE and the
transaction boundaries T-127 added are correct and must survive the move
unchanged.

Then give it a surface. The cheapest honest one is a Filament action on a new
Redemptions resource (an operator/admin already reaches Filament, and a void is
a moderation-shaped act); a console command is acceptable as a stopgap but does
not satisfy 06 §4.4, which describes an admin doing this. Whichever it is, the
reason must be recorded — it lands on the reversal memo and it is what a
restaurant asking "why is this on my invoice" reads.

## Acceptance criteria

- [ ] A void is reachable by an authorized human through a surface a test
      drives — not by resolving the service in a test. `render()`-equivalent
      does not count; the test presses the control
- [ ] Authorization is asserted BOTH ways: an admin/operator with the right
      may void; an ordinary diner and an operator of a *different* venue may not
- [ ] Voiding through that surface reverses the fee AND returns the quota slot,
      asserted on the ledger and on `offers.redemptions_count` together
- [ ] The guarded UPDATE survives the move — the T-127 test proving a double
      void releases the slot only once still passes, against the new namespace
- [ ] `App\Services\Ledger` no longer references `App\Services\Redemptions`;
      the void path goes Redemptions → event → Ledger, like its verify sibling
- [ ] A voided redemption's reason reaches the reversal memo and the audit log

## Gotchas

- The listener must run **in** the voider's transaction, not queued — the fee
  reversal and the status flip commit together or the books disagree with the
  row. `PostRedemptionLedgerEntries` documents this constraint; copy it.
- Do not "simplify" the guarded conditional UPDATE back into a `save()` during
  the move. It is what makes a double void release the slot once, and T-127
  has a test that dies if it goes.
- `fee_amount` is deliberately left as it was on a void. It records what was
  charged; the reversal records that it was given back. Blanking it erases the
  fact a fee ever applied.
