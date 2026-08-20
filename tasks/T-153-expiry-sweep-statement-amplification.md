# T-153 — The expiry sweep issues four round trips per (chunk × offer)

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-127 (offer quota counter)
- **Target paths:** `apps/api/app/Console/Commands/ExpireRedemptions.php`,
  `apps/api/app/Services/Redemptions/`,
  `apps/api/tests/Feature/Monetization/OfferQuotaCounterTest.php`

Found 2026-08-20 by the T-127 review fan-out. Filed as a **watch item, not a
defect** — the current shape is correct and was measured as acceptable at
realistic sizes. It is here so the trade is a decision when volume arrives,
rather than a surprise.

## The trade T-127 made

T-127 needed the status flip and the slot release to commit together, per
offer, because a kill between them retires an offer permanently. It kept the
existing `chunkById(500)` id-range iteration and grouped each chunk by
`offer_id`, so the sweep went from *2 statements per chunk* to *`BEGIN` +
`UPDATE redemptions` + `UPDATE offers` + `COMMIT` per (chunk × distinct
offer)* — and redemption ids interleave across offers, so a 500-id chunk can
span up to 500 of them.

Measured by the review's database pass: at 5,000 lapsed codes over 500 active
offers that is ~20,000 round trips versus 21 before, i.e. **0.2s–4s once an
hour**. It would need roughly 500,000 expiries/hour to reach a 7-minute run.
The same pass was explicit that the real defect had been the missing
transaction, not the cardinality.

## What to do when it does matter

Drive the loop **by offer** instead of by id range: one
`SELECT offer_id ... WHERE status = 'issued' AND expires_at <= ? GROUP BY
offer_id`, then per offer a single transaction whose
`UPDATE redemptions SET status = 'expired' WHERE offer_id = ? AND status =
'issued' AND expires_at <= ?` needs no 500-element `whereIn` bind list at all
and hits `redemptions_offer_id_status_index` directly. That is four round trips
per distinct offer **for the whole run**, and each offer row is locked once
instead of once per chunk it appears in. Keep a `WHERE id IN (SELECT id ...
LIMIT 500)` inner bound and loop until it returns 0, so the 500-row lock
ceiling inside one offer survives.

`UPDATE ... RETURNING offer_id` per chunk does **not** work here: it forces the
decrements outside the flip's transaction, which is exactly the atomicity T-127
bought.

## Acceptance criteria

- [ ] Only pick this up when there is a measurement showing it matters — record
      the observed sweep duration and lapsed-code volume in the PR. A rewrite
      with no number attached is not this task
- [ ] The flip and the release still commit together per offer; the T-127 test
      that fails the release inside the transaction and asserts the flip rolled
      back still passes
- [ ] A code redeemed in the gap between the read and the write still releases
      NO slot — the existing test for that must survive the new iteration model
- [ ] The multi-page test still spans more than one page of whatever paging
      replaces `chunkById`, with one offer recurring across pages, or is
      replaced by something that proves the same property
- [ ] Statement counts before and after are in the PR

## Gotchas

- `chunkById` is load-bearing today precisely because the callback writes rows
  out of the scope it is paging (`overdue()` filters `status = issued`). It
  pages on `id > $lastId` rather than on offset, which is why that is safe. Any
  replacement must reason about the same shrinking result set — a one-word
  change to `chunk()` would silently skip half of every large sweep, and the
  501-row test exists to catch exactly that.
- Extracting a `RedemptionExpirer` service was suggested alongside this and
  would make the sweep unit-testable without booting the console kernel. Worth
  doing together if this is picked up; not worth doing alone.
