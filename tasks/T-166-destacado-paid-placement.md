# T-166 — Destacado — paid placement that can never touch the near-me answer

- **Phase:** GROW · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-158
- **Target paths:**
  `apps/api/database/migrations/`,
  `apps/api/app/Models/Builders/PlaceQueryBuilder.php`,
  `apps/api/app/Http/Controllers/Api/V1/PlaceController.php`,
  `apps/api/app/Filament/`,
  `apps/mobile/src/components/`

## Why

Owner decision D4 (08 section 9.3): a restaurant pays a flat monthly fee to sit
at the top of one neighborhood or one cuisine, visibly labeled.

This is the first revenue line, ahead of pay-per-redemption, because it needs no
payment rail (sold by hand, a flag in Filament, invoiced by bank transfer), no QR
scanner, no staff training and no diners. That fits the deferred Mercado Pago
decision exactly.

The guardrail is the whole design: promoted placement lives in browse and
discovery lists, always labeled, and **never reorders the "open now, near me"
answer**. That single query is the trust the app rests on, and selling it is the
one move that cannot be undone. The guard belongs in a test, not in a
convention.

## Acceptance

- A promoted place appears at the top of the browse/discovery lists its promotion is scoped to, carrying a visible, non-dismissible label
- **A test asserts a promoted place does NOT change the ordering of the Tonight / near-me open-now result** for any input -- this test is the point of the task
- A promotion is scoped (neighborhood or cuisine), time-boxed, and disappears on expiry without a manual step
- Filament CRUD for promotions with every create/edit/expire action audit-logged
- A hidden, unpublished or moderator-actioned place cannot be promoted, and losing that status ends the promotion
- No dependency on any payment rail: the task ships complete with manual invoicing

## Notes

Filed 2026-09-03 from owner decision D4. This reorders 06-monetization: Destacado is revenue line #1 and the built redemption rail becomes the performance add-on for venues that already see volume. The near-me guard test is a blocking acceptance criterion, not a nice-to-have.
