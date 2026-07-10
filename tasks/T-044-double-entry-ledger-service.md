# T-044 — Double-entry ledger service + entries on redemption

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-043
- **Target paths:** `apps/api/app/Services/Ledger/`, `apps/api/database/migrations/`
- **Spec refs:** [06-monetization.md#revenue-share-mechanics](../06-monetization.md#4-revenue-share-mechanics), [02-data-model.md#ledger_entries](../02-data-model.md#315-ledger_entries)

## Context
All money in Reelmap is ledger-first: restaurant fees, influencer shares, platform margin, and payouts are balanced, append-only `ledger_entries` rows — balances are never stored, always derived (06-monetization §4.2). This task ships the `ledger_entries` table (M4 migration 20), a `LedgerService` with hard invariants, and the listener that posts entries when T-043 fires `RedemptionVerified`, inside the same DB transaction. It unlocks Stripe payouts (T-045) and the wallet (T-046). App code lives in the separate app repo created by T-001.

## Implementation steps
1. Migration `create_ledger_entries_table` per 02-data-model §3.15: `transaction_uuid` uuid, `account` varchar(32) (`LedgerAccount` enum: `platform_revenue`, `influencer_earnings`, `restaurant_fees`, `restaurant_receivable`, `stripe_fees`, `payout_clearing`), `direction` (`debit`|`credit`), `amount` bigint CHECK (amount > 0), `currency` char(3), morph `reference_type`/`reference_id` (`redemption`|`payout`), `user_id` nullable FK→users (subledger owner), `idempotency_key` varchar(120) **unique**, `memo`, `created_at` only (**no updated_at**). Indexes: (`transaction_uuid`), (`account`,`user_id`,`currency`), (`reference_type`,`reference_id`). Optionally add DB triggers/permissions blocking UPDATE/DELETE; at minimum the model forbids them (`static::updating/deleting` throw).
2. `LedgerService` API shape (`app/Services/Ledger/`):
```php
final class LedgerService
{
    /** @param LedgerLine[] $lines  Throws UnbalancedTransaction, DuplicatePosting */
    public function record(string $idempotencyKeyPrefix, array $lines,
        ?Model $reference = null, ?string $memo = null): LedgerTransaction;

    public function balance(LedgerAccount $account, ?User $user = null,
        string $currency = 'EUR'): int; // minor units, credits - debits (or per account normal side)

    public function verifyInvariants(): InvariantReport; // nightly job entry point
}
// LedgerLine: readonly DTO {LedgerAccount $account, LedgerDirection $direction,
//   int $amount, string $currency, ?int $userId, ?string $memo}
```
   `record()` runs in `DB::transaction` (joins an outer one if present): asserts per-currency sum(debits) === sum(credits) **before** insert (throw `UnbalancedTransaction`), generates one `transaction_uuid`, derives each row's `idempotency_key` as `{prefix}:{n}` (e.g. `redemption:123:capture:1`), and treats a unique-violation on the key as an idempotent no-op returning the existing transaction (`DuplicatePosting` only if partial overlap).
3. Redemption posting — listener on T-043's `RedemptionVerified`, executed inside the verify transaction. Fee `F` from config `monetization.redemption_fee_minor` (default **300** = €3.00, range 200–400, 06 §2.3); influencer share = `F * offer.influencer_share_bps / 10000` (bps frozen on the offer at creation — never retroactive, 06 §4.1). Example balanced transaction at €3.00 / 5000 bps:
```text
transaction_uuid: 0197-...-af   idempotency prefix: redemption:123:capture
debit  restaurant_receivable  300 EUR  user_id: null  (subledger: place via reference)
credit platform_revenue       150 EUR
credit influencer_earnings    150 EUR  user_id: influencer's claimed_by_user_id (or null = escrow)
```
   Also set `redemptions.fee_amount = 300`, `currency = 'EUR'` in the same transaction (T-043 left them null).
4. **Escrow for unclaimed influencers** (06 §4.2, §5.3): if `attributed_influencer_id` has `claimed_by_user_id = null`, post the `influencer_earnings` credit with `user_id = null` — the escrow balance per influencer identity is derivable via `reference` → redemption → `attributed_influencer_id`. Provide `LedgerService::escrowBalance(Influencer $i): int` using that join. On influencer claim (hook into T-038's claim flow), post one transfer transaction moving the escrow balance to the claiming user's `influencer_earnings` subledger (idempotency key `influencer:{id}:claim-escrow`). 12-month escrow expiry sweep (reversing entry to `platform_revenue`) can be a stub command with a TODO — policy exists, automation is post-M4-critical-path.
5. Void/refund support (06 §4.4): `LedgerService`-based `voidRedemption(Redemption $r, string $reason)` — reversing entries (swapped directions, key `redemption:{id}:void`), redemption `status = void`; callable from Filament.
6. Corrections are **only** reversing entries; expose `reverse(LedgerTransaction $tx, string $keyPrefix)` helper.
7. Nightly invariant job (scheduled): for every `transaction_uuid`+`currency`, sum(debits) = sum(credits); no orphan keys; alert (log + admin notification) on violation.
8. Pest tests (`tests/Feature/Monetization/LedgerTest.php`): balanced write happy path; unbalanced lines throw and insert nothing (transaction rolled back); duplicate idempotency prefix is a no-op; verify-redemption posts the 3-line split per configured bps; escrow path when influencer unclaimed + claim-transfer moves it; void reverses; balance() correctness; property-style test: after N random valid postings, global sum(debits) == sum(credits) per currency.

## Acceptance criteria
- [ ] `ledger_entries` matches 02-data-model §3.15 exactly: unique `idempotency_key`, positive-amount CHECK, no `updated_at`, append-only (update/delete throws)
- [ ] `LedgerService::record()` writes all lines of a transaction atomically with one `transaction_uuid`; imbalanced input throws `UnbalancedTransaction` and persists nothing
- [ ] Replaying the same idempotency key does not duplicate entries (unique key proven by test)
- [ ] Redemption verified → exactly one balanced transaction: debit `restaurant_receivable` F / credit `platform_revenue` + credit `influencer_earnings` split by the offer's frozen `influencer_share_bps`; posted inside the same DB transaction as the status flip (kill mid-way ⇒ neither committed)
- [ ] `redemptions.fee_amount`/`currency` set at redemption from config (default 300 minor units EUR)
- [ ] Unclaimed influencer share accrues as escrow (`influencer_earnings`, `user_id` null, traceable to the influencer via the redemption reference); claiming transfers the full escrow balance in one transaction
- [ ] Void writes reversing entries only — no row is ever updated or deleted
- [ ] Balance queries per account/user/currency derive purely from entries; ledger invariant test asserts sum(debits) = sum(credits) globally and per transaction_uuid
- [ ] Nightly invariant verification job scheduled and tested

## Verification
```bash
cd apps/api
php artisan test --filter=Ledger
php artisan test --filter=Monetization
vendor/bin/pint --test && vendor/bin/phpstan analyse
php artisan ledger:verify   # invariant command: expect "OK, 0 violations"
```
Expected: all green; `ledger:verify` reports zero violations on seeded + test data.

## Gotchas
- **Currency minor units everywhere**: bigint cents, never floats; bps math is integer — define rounding (e.g. round half up) once and test €2.01 at 3333 bps; the *pair* of platform/influencer credits must still sum to F exactly (compute one side, subtract for the other).
- Spec drift to reconcile deliberately: 06 §4.2 names accounts like `restaurant_receivable:{place_id}`, `influencer_payable`, `influencer_escrow`, `platform_cash`; the canonical `LedgerAccount` enum (02) is the closed set above. Map: influencer_payable/escrow → `influencer_earnings` (claimed vs unclaimed distinguished by `user_id`); per-place subledger via the redemption reference. 02's example uses 10% share, 06's business default is 50% — the split is **config + per-offer bps**, don't hardcode either.
- `record()` must *join* the caller's transaction (Laravel nests via savepoints) — opening a second independent transaction breaks the "status flip + postings commit atomically" guarantee of 03 §3.4.
- Idempotency keys are per-row but the unit of replay is the transaction: derive row keys from one prefix and check the prefix before writing, or a crash between rows leaves a half-posted (unbalanced) group your invariant job must catch.
- Don't cache balances in columns in M4; `GET /wallet` (T-046) sums the ledger. Add covering indexes instead if slow.
- Sharer earns nothing monetary in v1 (06 §4.1) — ignore the `sharer:` credit line shown in 03 §3.4's example payload; it's ahead of the business spec.
