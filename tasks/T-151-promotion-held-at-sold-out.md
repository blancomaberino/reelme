# T-151 — A promotion can be held at sold-out by accounts that never walk in

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-042 (offers CRUD), T-043 (redemptions), T-127 (quota counter)
- **Target paths:** `apps/api/app/Http/Requests/Offers/OfferWriteRequest.php`,
  `apps/api/app/Services/Redemptions/RedemptionGuards.php`,
  `apps/api/app/Console/Commands/ReconcileOfferQuotas.php`,
  `packages/contracts/schemas/offer.json`,
  `apps/api/tests/Feature/Monetization/`

Found 2026-08-20 by the T-127 review fan-out (payments lens). **This is a
capability T-127 created**, and it is worth being explicit about that: while
the counter was dead the cap did not exist, so neither did this.

## The shape

T-127 reserves a slot at **issue** and holds it for the 24h TTL. It comes back
only when `reelmap:redemptions:expire` sweeps (hourly). Nothing throttles
issuance *per offer* except `quota_per_day`, which is `nullable` with no floor
tied to `quota_total` (`OfferWriteRequest.php:78-80`, verified on `main` at
`c87a2ed`). Every other velocity guard in `RedemptionGuards` is keyed on the
**account**: `quota_per_user` defaults to 1, plus `MAX_ISSUES_PER_DAY` and
`MAX_ISSUES_PER_WEEK`.

So `quota_total` throwaway accounts, one issue each — comfortably inside every
existing guard — hold a 50-slot offer at `remaining_quota: 0` and re-issue each
day as slots return. Every real diner is refused `offer_not_redeemable`, the
venue's pin loses `has_active_offer`, and the venue books zero covers from a
promotion it is paying to run.

Nothing detects it. An offer at 100% issued / 0% redeemed is indistinguishable
from a popular one in every log and admin surface T-127 added.

## This needs a product decision before code

There is no clean one-line fix, and the obvious ones each cost something real:

- **Require `quota_per_day` whenever `quota_total` is set.** Bounds the damage
  per day but does not stop it, changes the offer-creation contract, and forces
  a number on every operator who did not want a daily cap.
- **Reserve at verify rather than issue.** Removes the faucet entirely but
  breaks the promise the cap makes — "first 50 diners" would over-issue codes
  and refuse people at the till, which is worse for the venue than a slow day.
- **Shorten the TTL or sweep more often.** Narrows the window; 06 §3 sets 24h.
- **Detect rather than prevent.** Report offers at ≥`quota_total` held with zero
  `redeemed` rows, and let a human look. Cheapest, and honest about the fact
  that this is abuse, not a rule violation.

Bring the trade to the owner and record the answer as an ADR before writing
code. Do not silently pick one — the spec's `quota_per_user`/`quota_per_day`
table (06 §2.2) is the thing being amended.

## Acceptance criteria

- [ ] The decision is written up as an ADR in `07-risks-decisions.md`, naming
      what is being traded and why, before the implementation lands
- [ ] Whatever is chosen, the abuse case is covered by a test that FAILS on
      today's code — N throwaway accounts holding an N-slot offer, with the
      assertion being that a real diner can still get in (or that an operator
      is warned), not merely that a number changed
- [ ] If validation changes, it is proven both ways: a legitimate offer still
      validates, and the newly-refused shape returns 422 with a usable message
- [ ] Any new contract field or rule is reflected in
      `packages/contracts/schemas/offer.json` and the generated TS
- [ ] Existing offers are not broken by a newly-required field — say what
      happens to rows already in the database

## Gotchas

- The release-on-expiry behaviour is **correct** (06 §2.3) and is what bounds
  each round to ~25h. Do not "fix" this by holding slots forever; that trades
  an abuse case for a guaranteed one, since an offer would then retire itself
  on abandoned codes.
- `quota_per_user` and the partial unique index already cap one diner to one
  live code per offer. The cost of the attack is accounts, not requests — rate
  limiting does not touch it.
- Requiring `quota_per_day` interacts with the UTC-day window
  `RedemptionGuards::assertOfferRedeemable()` documents as a known
  simplification. If a daily cap becomes mandatory it is worth revisiting
  whether "day" should mean the venue's day.
