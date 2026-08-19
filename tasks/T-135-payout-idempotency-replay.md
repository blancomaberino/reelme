# T-135 — A retried payout carrying the same Idempotency-Key is told it has insufficient balance

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-045 (Stripe Connect Express), T-046 (wallet API + screens)
- **Target paths:** `apps/api/app/Http/Controllers/Api/V1/WalletController.php`,
  `apps/api/app/Services/Payments/PayoutService.php`,
  `apps/api/tests/Feature/Wallet/`

Code review 2026-08-19, finding **CR-9 (IMPORTANT)** — concurrency, on a money
screen.

## Context

`WalletController.php:141` does an unlocked check-then-create, so two concurrent
requests with the same key both miss the lookup. The loser violates
`payouts_idempotency_key_per_user`, and
`catch (UniqueConstraintViolationException)` translates **every** unique violation
into `insufficientBalance()`.

Its comment reasons only about the one-live-payout-per-period index, and is
**correct for that one**. The same catch now silently swallows a second constraint
that means something entirely different.

What the user sees: a phone on a bad connection retries `POST /wallet/payouts`
with the same key before the first response lands, and is told it has insufficient
balance for money that is visibly on screen. No money is lost. The user is told
something **false on a money screen** — the exact failure the controller docblock
says the idempotency key prevents.

## Implementation

In the catch, re-read the payout by `(user_id, idempotency_key)` and return it as
a replay **before** falling back to `insufficientBalance()`.

Keep the fallback. The other index is a real condition with the right answer.

### The test is the hard part

This is what makes the task `executor-high` rather than a two-line change. **Two
sequential requests will not reproduce it** — the second finds the row and replays
correctly. The bug lives in the window between the lookup and the insert, so the
test has to *force* the violation (insert the row inside a hook, or drive two
connections) and assert on the **response**, not on the row count.

**A test that passes without ever raising `UniqueConstraintViolationException`
has tested nothing.**

## Acceptance criteria

- [ ] Two requests with the same `Idempotency-Key` return the SAME payout —
      asserted by forcing the unique violation, not by calling the endpoint twice
      sequentially and hoping
- [ ] The catch distinguishes the idempotency index from the one-live-payout
      index; the latter still answers `insufficientBalance`, and both branches are
      asserted separately
- [ ] No second payout row is created, and no second Stripe call is made
- [ ] A genuine insufficient balance still answers `insufficientBalance`

## Gotchas

- Money and concurrency is the strongest area of this codebase and the review
  found **nothing else** wrong with it. Match the standard already set next door:
  `RedemptionVerifier` uses `lockForUpdate` + a guarded conditional `UPDATE` + a
  unique index.
- Postgres **aborts the transaction** on a constraint violation — T-054's trap
  (a): create-and-catch for idempotency returned a 500 via 25P02 on every
  statement after the catch. The re-read must happen on a usable connection.
- Replaying must be idempotent all the way out: the same payout, the same
  response body, and no second side effect.
