# T-154 — The ledger's alarm logs an unbounded payload and its exit code reaches nobody

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-044 (double-entry ledger)
- **Target paths:** `apps/api/app/Services/Ledger/InvariantReport.php`,
  `apps/api/app/Console/Commands/VerifyLedgerInvariants.php`,
  `apps/api/routes/console.php`,
  `apps/api/tests/Feature/Monetization/LedgerTest.php`

Found 2026-08-20 by the T-127 review fan-out, in the class T-127's own report
was modelled on. Both halves were fixed for the quota reconciler and
deliberately not reached into the ledger subsystem from that diff.

## 1. The exit code has no reader

`VerifyLedgerInvariants`' docblock justifies `return self::FAILURE` as *"so the
scheduler surfaces it as a failed run rather than a log line nobody greps
for."* That is not true in this codebase: `schedule:run` discards a task's exit
code, and there is no `->onFailure()`, `emailOutputOnFailure()`, ping, or
`ScheduledTaskFailed` listener anywhere in `app/`, `routes/` or `bootstrap/`.

So the one alert in the system that means **the books are wrong** fires into
nothing. `Log::critical` is written, but the claim the class makes about the
exit code is false. T-127 added `->onFailure()` to
`reelmap:offers:reconcile-quotas` for exactly this reason and left the ledger
sibling alone as out of scope; that asymmetry is now the odd one.

## 2. The payload is unbounded

`VerifyLedgerInvariants.php:40` passes `$report->unbalanced` whole into
`Log::critical`, and prints one `$this->line()` per offending row. Verified on
`main` at `c87a2ed`: `InvariantReport` exposes `unbalanced` and
`singleEntryTransactions` with no cap.

The condition that fills those arrays is exactly the one the command exists to
catch — a bad migration, a hand-written UPDATE, a code path inserting entries
directly — and such a cause tends to hit many transactions at once. The alert
about the outage becomes part of the outage: a multi-megabyte log record is
truncated or dropped by the shipper at the moment it matters most.

`QuotaDriftReport` (T-127) solved this with a `SAMPLE_SIZE` constant, a
`sample()` and an `omitted()`, and its docblock names `InvariantReport` as its
model. Lift the discipline back into the model.

## Implementation

Either a small shared trait/base report both use, or the same three members
added to `InvariantReport`. Prefer sharing only if it is genuinely the same
shape — the two reports differ in severity (`critical` vs `warning`), payload
and the presence of a `--fix` path, so a forced common interface that no caller
consumes polymorphically would be worse than a little duplication.

## Acceptance criteria

- [ ] A drifting ledger produces an alert something actually receives — the
      scheduled failure is wired, and a test asserts the wiring rather than the
      exit code alone
- [ ] The `critical` log record is bounded: a count plus a sample, with the
      omitted number stated, proven by a test seeding more violations than the
      cap and asserting what the record carries
- [ ] The stdout listing is capped too, with a "… N more." line — the scheduler
      captures that output
- [ ] `VerifyLedgerInvariants`' docblock no longer claims the exit code is
      surfaced unless it is
- [ ] Exit codes are unchanged: healthy → success, any violation → failure

## Gotchas

- Do **not** downgrade `Log::critical` here. The quota counter's `warning` is
  justified by being repairable from rows that remain the source of truth; an
  unbalanced ledger is not — that is money already misrecorded and the remedy
  is a reversing entry a human writes.
- `LedgerService::record()` refuses to write an imbalance, so this command "can
  never" find anything — which is the entire reason it runs. Seed the drift the
  way `LedgerTest` already does, by writing rows straight through the factory
  with hand-set idempotency keys, bypassing the service.
- The two-shape design (sum-based check plus a `count(*) < 2` pass for
  half-written transactions) is deliberate; a cap must not collapse them into
  one number.
