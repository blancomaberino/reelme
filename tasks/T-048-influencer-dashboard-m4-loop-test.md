# T-048 — Influencer dashboard metrics + M4 loop integration test

- **Phase:** M4 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-046, T-047
- **Target paths:** `apps/api/app/Http/Controllers/InfluencerDashboardController.php`, `apps/api/tests/Feature/Monetization/`
- **Spec refs:** [06-monetization.md#influencer-program](../06-monetization.md#5-influencer-program), [ROADMAP.md#m4](../ROADMAP.md#m4--monetization)

## Context
Closing M4: the influencer-facing funnel dashboard (06-monetization §5.2) that makes earnings legible ("this reel drove 12 paid visits"), plus the phase exit-criteria test proving the whole loop — offer → redemption → verify → balanced ledger → payout — works end to end in test mode (ROADMAP §M4). Everything it reads exists after T-041..T-047. App code lives in the separate app repo created by T-001.

## Implementation steps
1. Route + controller: `GET /api/v1/me/influencer/dashboard` (auth: user with a claimed influencer, else 403). Note: 03-api-design has no dashboard route yet — add it under the `/me` namespace and add the response schema to `packages/contracts/schemas` in the same PR (03 §5 rule).
2. Funnel per 06 §5.2, aggregated overall, per place, and per source post: `shares` (published place_sources whose source_post belongs to the influencer) → attributed place-page views (add a lightweight counter — increment on `GET /places/{id}` with attribution context; acceptable v1 proxy) → offer taps (issued redemptions, `attributed_influencer_id = me`) → `redemptions issued` → `redemptions redeemed` → earnings (ledger credits).
3. Money block from `LedgerService` (T-044): pending (available) balance, paid-out total, escrow note if any pre-claim escrow was transferred; payout history summary + Stripe onboarding status (reuse T-046 wallet internals — don't duplicate balance math). Top places by earnings: group `influencer_earnings` credits by redemption → offer → place, order desc, limit 5.
4. Query implementation: single service class `Services/Influencers/DashboardMetrics` returning a DTO; use a handful of indexed aggregate queries (indexes on `redemptions.attributed_influencer_id` and `ledger_entries(account, user_id, currency)` already exist) with `?period=30d|90d|all`. Cache 5 minutes per influencer.
5. Feature tests for the endpoint: seeded influencer with 2 places, mixed issued/redeemed/expired/void redemptions → funnel counts and earnings-by-place exact; 403 for non-influencers; unclaimed influencer identity has no dashboard.
6. **M4 full-loop integration test** `tests/Feature/Monetization/M4LoopTest.php` (fake Stripe service from T-045; no network):
   1. Seed: place published via pipeline factories with `place_sources` → `source_post` → influencer (claimed by user I) + sharer share; restaurant user R with verified `place_claims`; offer (active, €3.00 fee context, `influencer_share_bps`).
   2. Diner D: `POST /redemptions` with the share referral context → assert code issued, attribution frozen to influencer + share.
   3. R: `POST /redemptions/verify` `{code, place_id}` within geofence → 200, `status: redeemed`.
   4. Ledger assertions: exactly one transaction group for the redemption; **sum(debits) = sum(credits)** per currency (global invariant helper); `restaurant_receivable` debited F; `influencer_earnings` credit for user I = F × bps/10000.
   5. Wallet: `GET /wallet` as I shows the increased available balance.
   6. Payout: top balance above threshold (or lower config in test) → `POST /wallet/payouts` → payouts row `pending→processing` with fake transfer id, hold entries posted; simulate transfer-success webhook fixture → `paid`; ledger still balanced.
   7. Dashboard: `GET /me/influencer/dashboard` reflects 1 issued, 1 redeemed, earnings by that place.
   8. Fraud-rejection sub-cases (may live in this file or assert T-043's suite covers them, per M4 exit criteria): duplicate redemption attempt, expired code, wrong-restaurant verify — all rejected.
   9. **Escrow variant**: same loop with an *unclaimed* influencer → share credited to escrow (`influencer_earnings`, `user_id` null, per 06 §4.2/§5.3); nobody's wallet moves; then claim the influencer (T-038 flow) → escrow balance transfers to the new user's payable balance in one transaction.
7. Wire the mobile influencer dashboard consumption if trivial (wallet screen already shows money; a dashboard screen may be deferred — API + test are this task's deliverable per acceptance).

## Acceptance criteria
- [ ] `GET /api/v1/me/influencer/dashboard` returns the funnel (shares → views → offer taps → issued → redeemed → earnings) overall, per place, and per source post, plus balances, payout history, Stripe status, top places by earnings
- [ ] Contracts schema added for the dashboard response; endpoint policy-gated to claimed influencers
- [ ] Funnel numbers proven exact against a seeded mixed-status dataset (void/expired excluded from "redeemed" and earnings)
- [ ] M4 full-loop test passes: seeded offer → redemption issued → verified by restaurant account → ledger balanced (sum debits = sum credits) → influencer wallet balance increased → payout request creates a (fake/test-mode) Stripe transfer and reaches `paid` via webhook fixture
- [ ] Loop test runs in CI with no network (fake Stripe, fixtures)
- [ ] Fraud rejections asserted per ROADMAP M4 exit criteria: duplicate redemption, expired code, wrong-restaurant verify
- [ ] Escrow path tested: unclaimed influencer earnings accrue to escrow, claiming transfers the full balance to `influencer_payable`-equivalent (user-scoped `influencer_earnings`)
- [ ] `php artisan ledger:verify` clean after the full loop test dataset

## Verification
```bash
cd apps/api
php artisan test --filter=Monetization        # includes M4LoopTest, Ledger, Redemption, Wallet
php artisan test tests/Feature/Monetization/M4LoopTest.php
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: entire Monetization suite green in one run without network; this is the M4 phase gate — when it passes and T-041..T-047 are `done`, M4's ROADMAP exit criteria are met.

## Gotchas
- The dashboard must aggregate from **denormalized attribution** (`redemptions.attributed_influencer_id`) and the ledger — never by re-walking share → source_post joins, which break when shares are deleted (02 §3.14 note).
- "Views" has no tracked source yet — the place-view counter is a v1 proxy; label it as such in the API (`views_tracked_since`) so charts aren't read as historical truth.
- Escrow balances (`user_id` null) must appear in the *influencer identity's* claim teaser, not in any user dashboard, until claim; after claim, don't double-count the transfer transaction as new earnings in the funnel period.
- The loop test must exercise the real HTTP endpoints (`postJson`), not services directly — it's the contract-level proof; keep model factories for setup only.
- Beware test-ordering flakiness with Redis rate limiters from T-043 (velocity limits) — use `RateLimiter::clear()`/array store in the loop test or the second run in a suite hits the 3/day issue cap.
- Timezone traps in `?period=` windows and payout `period_start/end` (dates, not timestamps) — pin the test clock (`Carbon::setTestNow`).
