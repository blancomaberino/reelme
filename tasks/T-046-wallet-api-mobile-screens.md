# T-046 — Wallet API + mobile wallet/earnings screens

- **Phase:** M4 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-045
- **Target paths:** `apps/api/app/Http/Controllers/WalletController.php`, `apps/mobile/app/(tabs)/wallet.tsx`
- **Spec refs:** [03-api-design.md#wallet](../03-api-design.md#214-wallet), [05-mobile-app.md#screen-inventory](../05-mobile-app.md#screen-inventory)

## Context
The wallet is the read/act surface over T-044's ledger and T-045's payout rail: influencers (and later restaurant owners) see balances derived from `ledger_entries`, request payouts, and complete Stripe onboarding (05-mobile-app screens #21–22). API endpoints are fully specified in 03-api-design §2.14; this task wires them plus the conditional Wallet tab. App code lives in the separate app repo created by T-001.

## Implementation steps
1. `WalletController` in `App\Http\Controllers\Api\V1`, routes per 03-api-design §2.14 (all `user` auth, wallet-eligible = `is_influencer || is_restaurant_owner`, else 403):
   - `GET /api/v1/wallet` — payload per 03 §3.5: `balance.available` = `LedgerService::balance(influencer_earnings, $user)` minus holds; `balance.pending` = amounts held in `payout_clearing` for in-flight payouts; `lifetime_earnings` = sum of `influencer_earnings` credits; `connect` block from `StripeConnectService::syncAccountStatus` (cached, refresh async); `minimum_payout` from config; `recent_entries` (last 5).
   - `GET /api/v1/wallet/ledger` — cursor-paginated `ledger_entries` where `user_id = me`, mapped to the API shape (`type`, `direction`, `amount`, `currency`, `memo`, `created_at`); never expose other users' rows.
   - `POST /api/v1/wallet/payouts` — delegates to `PayoutService::request()` (T-045); honors `Idempotency-Key` header (03 §1); errors: `insufficient_balance` (422), 403 when not Connect-onboarded.
   - `GET /api/v1/wallet/payouts` — payout history with statuses.
   - `POST /api/v1/wallet/connect/onboarding-link` — fresh AccountLink URL; `GET /api/v1/wallet/connect/status`.
   Add contracts schemas for each response per 03 §5.
2. Restaurant-owner view of the same wallet: for `is_restaurant_owner`, `GET /wallet` additionally returns fees owed (debit balance of `restaurant_receivable` scoped via their place claims) — read-only in M4 (invoicing ships late in phase per 06 §7); keep the response shape shared.
3. Mobile Wallet tab (`apps/mobile/app/(tabs)/wallet.tsx`, screen #21): balance card (available/pending), infinite ledger list (`['wallet','ledger', page]` query keys, `staleTime: 0` per 05 §state rules), payout button (disabled below `minimum_payout`, confirm sheet → `POST /wallet/payouts` mutation with generated Idempotency-Key), Stripe status banner (onboarding incomplete / requirements due → CTA).
4. Tab gating per 05-mobile-app tab rules: `href: session.user?.is_influencer || session.user?.is_restaurant_owner ? '/wallet' : null` — expo-router hides the tab when `href` is null (spec names influencers; restaurant owners get it too since tasks.json scopes the tab to "influencer/restaurant roles").
5. Stripe onboarding webview (`apps/mobile/app/stripe-onboarding.tsx`, screen #22): `react-native-webview` loading the URL from `POST /wallet/connect/onboarding-link`; intercept navigation to `return_url`/`refresh_url` (via `onShouldStartLoadWithRequest`) → close webview, refetch `GET /wallet` + `/wallet/connect/status`.
6. Push/deep links: handle `wallet.payout` notification type routing to `/wallet` (05 §push table; notification itself sent by T-045 handlers via T-040 infra).
7. Tests — API (Pest): balance math against seeded ledger scenarios (earned, held, paid out, voided-negative), 403 for plain diners, payout request happy + insufficient + not-onboarded, ledger pagination + ownership isolation, rate-limit headers present. Mobile (Jest): wallet screen renders balances, payout button disable logic, onboarding banner states; tab hidden for non-eligible session.

## Acceptance criteria
- [ ] `GET /wallet` returns available/pending balance, lifetime earnings, connect status, minimum payout, recent entries exactly in the 03 §3.5 shape — all money integers in minor units + currency
- [ ] Balances are derived from `ledger_entries` only (no cached columns); available reflects payout holds immediately
- [ ] `GET /wallet/ledger` is cursor-paginated and only ever returns the caller's entries
- [ ] `POST /wallet/payouts` creates the payout via T-045 (threshold + onboarding enforced) and is idempotent under `Idempotency-Key` replay
- [ ] `POST /wallet/connect/onboarding-link` returns a fresh hosted onboarding URL; `GET /wallet/connect/status` reports `onboarded`, `payouts_enabled`, `requirements_due`
- [ ] Wallet endpoints return 403 for users who are neither influencer nor restaurant owner; mobile hides the Wallet tab for them
- [ ] Mobile wallet screen: balance card, infinite ledger history, payout request flow (disabled under threshold), Stripe onboarding webview completing round-trip and refetching wallet
- [ ] API feature tests + mobile component tests green

## Verification
```bash
cd apps/api
php artisan test --filter=Wallet
vendor/bin/pint --test && vendor/bin/phpstan analyse
cd ../mobile && npx tsc --noEmit && npx jest wallet
```
Manual: seed a claimed influencer with verified redemptions, open Wallet tab in the dev client — balance matches `php artisan tinker` ledger sum; request payout in Stripe test mode and watch status move to `processing`/`paid` while pending/available update.

## Gotchas
- "Pending" is defined by the ledger, not Stripe: money held in `payout_clearing` for a `pending|processing` payout is pending; don't double-subtract it from available.
- Wallet must never round or float: format cents → display string only at the UI edge (`Intl.NumberFormat` with currency).
- `staleTime: 0` + refetch-on-focus for wallet queries (05 §state rules) — a diner-turned-influencer seeing a stale zero balance after their first redemption is the top support complaint to avoid.
- The onboarding webview's `return_url` fires before Stripe finishes async verification — treat return as "refetch status", not "onboarded"; the banner keys off `payouts_enabled`.
- Escrowed (unclaimed-influencer) earnings have `user_id = null` and must NOT leak into anyone's wallet; they surface only via the claim flow ("€212 waiting", T-038/T-044) and T-048's dashboard.
- Endpoint naming drift: mobile spec says `/wallet/stripe/onboarding-link`; 03-api-design's `/wallet/connect/onboarding-link` is canonical — mobile calls the canonical route.
