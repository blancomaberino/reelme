# T-045 — Stripe Connect Express: onboarding, payouts, webhooks

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-044, T-038
- **Target paths:** `apps/api/app/Services/Payments/`, `apps/api/app/Http/Controllers/StripeWebhookController.php`
- **Spec refs:** [06-monetization.md#revenue-share-mechanics](../06-monetization.md#4-revenue-share-mechanics), [03-api-design.md#webhooks](../03-api-design.md#4-webhooks)

## Context
Influencer earnings sitting in the ledger (T-044) become real money via Stripe Connect **Express**: hosted KYC onboarding, platform-triggered `Transfer`s, and webhooks that reconcile the ledger (06-monetization §4.3). Influencer claiming (T-038) determines who may onboard. This ships the `payouts` table (M4 migration 21), a payments service layer, and the signature-verified webhook endpoint. It unlocks the wallet (T-046) and the M4 exit criterion "payout request creates Stripe transfer (test mode)". App code lives in the separate app repo created by T-001.

## Implementation steps
1. Install `stripe/stripe-php` (resolve latest stable); config `services.stripe` (`secret`, `webhook_secret`, `connect` settings). Migration `create_payouts_table` per 02-data-model §3.16: `user_id` FK→users, `stripe_transfer_id` nullable, `amount` bigint, `currency` char(3), `status` (`PayoutStatus`: `pending`, `processing`, `paid`, `failed`, `reversed`), `period_start`/`period_end` date, `failure_reason`, `paid_at`. Indexes: unique(`stripe_transfer_id`) where not null; unique(`user_id`,`period_start`,`period_end`,`currency`); index(`status`). Also migration `create_stripe_events_table` (03 §4.1): `stripe_event_id` unique, `type`, `payload` jsonb, `processed_at`.
2. `Services/Payments/StripeConnectService` behind an interface (fake implementation for tests):
   - `createOrGetAccount(User $u): string` — create Express account (`type: express`), persist `users.stripe_connect_account_id` (unique column exists per 02 §3.1).
   - `createOnboardingLink(User $u): string` — `AccountLink` (`type: account_onboarding`) with `return_url`/`refresh_url` deep-link routes the mobile webview intercepts (05-mobile-app screen #22).
   - `syncAccountStatus(User $u, ?Account $a = null): ConnectStatus` — maps `charges_enabled`/`payouts_enabled`/`requirements.currently_due`; sets `users.stripe_connect_onboarded_at` when complete.
   - `transfer(Payout $p): Transfer` — `\Stripe\Transfer::create` with `idempotency_key: "payout:{$p->id}"`, `transfer_group`, destination = connect account.
3. `Services/Payments/PayoutService::request(User $u, string $currency = 'EUR'): Payout`, used by `POST /wallet/payouts` (T-046) and the monthly command:
   1. Guards: Connect onboarded + `payouts_enabled` (403 otherwise); available balance = `LedgerService::balance(influencer_earnings, $u)` ≥ config `payouts.minimum_minor` (**2500** = €25.00 per 06 §4.3; 03's example shows 1000 — config wins) else 422 `insufficient_balance`.
   2. In one DB transaction: create `payouts` row (`pending`, amount = full available balance, period window) + ledger **hold**: `debit influencer_earnings / credit payout_clearing` (key `payout:{id}:hold`) so the balance can't be double-requested.
   3. Dispatch queued job (queue `payouts`) → `transfer()` → store `stripe_transfer_id`, `status = processing`. Stripe failure → `failed`, reverse the hold (key `payout:{id}:hold-reverse`), notify admin + user.
4. Monthly payout run (06 §4.3): `php artisan payouts:run` scheduled 1st business day — selects users with payable ≥ threshold and completed KYC, calls `PayoutService::request()` per user; per-user failures don't abort the run.
5. `StripeWebhookController` at `POST /api/v1/webhooks/stripe` (03 §4.1): verify `Stripe-Signature` with `\Stripe\Webhook::constructEvent` (reject 400 on bad signature), insert into `stripe_events` (unique `stripe_event_id`; duplicate ⇒ 200 immediately — replay-safe), queue processing on `payouts` queue, return 200 fast. CSRF-exempt, generous rate limit. Handlers:
   - `account.updated` → `syncAccountStatus`; notify user when onboarding completes or new `requirements_due` appear.
   - `transfer.*` success / `payout.paid` → payout `paid` + `paid_at`; settle ledger per T-044 convention (payout_clearing is terminal in the v1 enum — record settlement memo, no unbalanced entry).
   - `payout.failed` / `transfer.reversed` → payout `failed`, reverse hold (credit back to `influencer_earnings`), notify user.
   - `charge.refunded` → compensating reversal of the associated redemption postings via `LedgerService::voidRedemption` path.
6. Testing setup: Pest tests use the fake `StripeConnectService` + recorded webhook fixture JSONs (`tests/Fixtures/stripe/*.json`) with signatures generated from the test webhook secret; optionally a `stripe-mock` docker service for client-shape tests (not in CI-critical path). Cover: onboarding link + status sync, payout below threshold rejected, happy payout writes hold + transfer id, webhook signature rejection, event replay processed once, `payout.failed` restores balance.

## Acceptance criteria
- [ ] Express account creation + hosted onboarding link generation work; `users.stripe_connect_account_id` / `stripe_connect_onboarded_at` tracked; status endpoint data (`onboarded`, `payouts_enabled`, `requirements_due`) available for T-046
- [ ] No payout possible until `payouts_enabled` on the connected account (06 §4.3), tested
- [ ] Payout request enforces minimum threshold (config, default €25.00) and returns `insufficient_balance` below it
- [ ] Payout request atomically creates the `payouts` row and the ledger hold (`debit influencer_earnings / credit payout_clearing`); available balance drops immediately; double-request finds no available balance
- [ ] Stripe transfer created in test mode with idempotency key `payout:{id}`; `stripe_transfer_id` stored (unique)
- [ ] Webhook endpoint rejects invalid/missing `Stripe-Signature` with 400; valid events are stored in `stripe_events` before processing
- [ ] Replayed webhook events (same `stripe_event_id`) are processed exactly once
- [ ] `account.updated` syncs Connect status; `payout.failed` flips payout to `failed`, reverses the hold, and restores the wallet balance (ledger stays balanced) — all tested with fixtures
- [ ] `payouts:run` scheduled command pays every eligible user and skips ineligible ones without aborting

## Verification
```bash
cd apps/api
php artisan test --filter=Stripe
php artisan test --filter=Payout
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Manual (test mode, real Stripe keys in `.env`):
```bash
stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
php artisan payouts:run --dry-run   # then without --dry-run for one seeded user
stripe trigger account.updated
```
Expected: tests green; live-listen run shows a Transfer in the Stripe test dashboard, payout row reaching `paid`, and `php artisan ledger:verify` still reporting zero violations.

## Gotchas
- **Webhook replay/idempotency**: Stripe redelivers and can deliver out of order (`payout.failed` after a retry of `account.updated`). The `stripe_events` unique insert must happen before any side effect, and handlers must be state-machine-safe (ignore `paid` on an already-`failed` payout, log for admin).
- **Connect KYC states** are not binary: `details_submitted` ≠ `payouts_enabled`; `requirements.currently_due` can reappear after onboarding (re-KYC). Always gate transfers on live `payouts_enabled`, never on the cached `stripe_connect_onboarded_at` alone.
- Account links expire in minutes and are single-use — generate fresh per request (`POST /wallet/connect/onboarding-link` is "create/refresh"), never store them.
- Transfers vs payouts: the platform creates **Transfers** to the connected account; Stripe's own `payout.*` events on Express accounts describe Stripe→bank movements. Decide which event marks our `payouts` row `paid` (transfer success is the practical v1 trigger) and document it in the handler.
- Currency: EUR only in v1 (06 §4.3) — hard-fail transfers in any other currency; amounts are minor units end-to-end.
- Negative balances from voids after payout are carried against future earnings — the threshold check must use the signed ledger balance, no clawback transfers in v1 (06 §4.4).
- Don't verify webhooks by parsing JSON before signature check; use the raw request body (Laravel: `$request->getContent()`) or signatures never match.
