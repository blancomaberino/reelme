# T-042 — Offers CRUD (API + Filament + restaurant mobile screens)

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-041
- **Target paths:** `apps/api/app/Http/Controllers/OfferController.php`, `apps/mobile/app/restaurant/`
- **Spec refs:** [06-monetization.md#restaurant-program](../06-monetization.md#2-restaurant-program), [03-api-design.md#offers](../03-api-design.md#212-offers)

## Context
Verified operators (T-041) publish `offers` — the thing diners redeem and the unit the flat pay-per-redemption fee hangs on (06-monetization §2.2–2.3). This task ships the `offers` table (M4 migration 18), owner-scoped CRUD, diner browse endpoints, Filament moderation, and the restaurant offer-management mobile screens (05-mobile-app screen #19). It unlocks T-043 redemptions. App code lives in the separate app repo created by T-001.

## Implementation steps
1. Migration `create_offers_table` per 02-data-model §3.13: `place_id` FK→places (cascade), `created_by_user_id` FK→users, `title` varchar(160), `description`, `discount_type` (`OfferDiscountType`: `percent`, `fixed_amount`, `free_item`), `discount_value` integer, `terms`, `starts_at` (not null), `ends_at` nullable (null = open-ended), `quota_total` nullable, `quota_per_user` default 1, `redemptions_count` counter cache, `influencer_share_bps` smallint default 1000, `status` (`OfferStatus`: `draft`, `active`, `paused`, `expired`, `archived`, default `draft`). Indexes: (`place_id`,`status`), (`starts_at`,`ends_at`). CHECK: `discount_type <> 'percent' OR discount_value BETWEEN 1 AND 100`. **Also add nullable `quota_per_day` integer** — required by 06-monetization §2.2 quotas but absent from the 02 table; comment the addition.
2. Enums `OfferDiscountType`, `OfferStatus` in `app/Enums`; `Offer` model with casts, factory (states: `active`, `expired`, `quotaExhausted`), and scopes: `active()` = `status = active AND starts_at <= now() AND (ends_at IS NULL OR ends_at >= now())`.
3. Domain guard used here and by T-043: `Offer::isRedeemable(): bool` — active window, `quota_total` not exhausted (compare `redemptions_count` of non-void redemptions), `quota_per_day` not exhausted today.
4. `OfferPolicy`: `create` requires `$user->ownsPlace($place)` (T-041 helper); `update`/`delete` require ownership of the offer's place. Register in `AuthServiceProvider`.
5. Routes per 03-api-design §2.12, controller in `App\Http\Controllers\Api\V1`:
   - `GET /api/v1/offers` (public): filters `?place_id=&near=lat,lng&radius_m=&active=1`; `near` uses `ST_DWithin` on `places.location`; cursor pagination.
   - `GET /api/v1/offers/{id}` (public): terms, validity window, redemption limits, place summary.
   - `POST /api/v1/offers` (restaurant), `PATCH /api/v1/offers/{id}` (pause/update), `DELETE /api/v1/offers/{id}` (archive, not hard delete — set `status = archived`).
   - FormRequest validation: max 90-day validity window per offer (06 §2.2), percent 5–50 business rule on create (06 §2.2 field table; DB CHECK is looser 1–100).
6. Surface offers on place detail: `GET /api/v1/places/{id}?include=offers` returns active offers (T-030 resource extension); add `has_active_offer` to map pins if not already present (03 §3.3). Add/extend contracts schemas per 03 §5.
7. Filament `OfferResource`: list all offers with place + status; admin **Pause** action (post-hoc moderation, 06 §2.2 — no pre-approval gate).
8. Mobile (`apps/mobile/app/restaurant/offers.tsx`, screen #19): offer list for the operator's place(s), create/edit form (title, discount type/value, validity window, quotas, terms), pause/archive actions; TanStack Query mutations against the endpoints above; entry point under Profile → "My restaurant" (05-mobile-app §tab rules — no dedicated tab). Jest component tests for the form validation states.
9. Pest feature tests per endpoint: happy path, authz denial (non-owner 403), validation error shape, rate-limit headers present (03 §5); diner browse returns only `active`-window offers when `active=1`.

## Acceptance criteria
- [ ] `offers` table matches 02-data-model §3.13 (plus documented `quota_per_day` addition) with CHECK constraints and indexes
- [ ] Owner-only management: create/update/delete rejected with 403 for users without a verified claim on the place (policy-tested)
- [ ] `POST /offers` validates: percent 5–50, validity window ≤ 90 days, `starts_at` required
- [ ] `DELETE` archives (status `archived`), never hard-deletes; `PATCH` supports pausing
- [ ] Diners browse active offers via `GET /offers?active=1&near=…` and see them embedded on place detail via `?include=offers`
- [ ] `Offer::isRedeemable()` enforces validity window, `quota_total`, and `quota_per_day`; unit-tested for each exhaustion case
- [ ] Filament admin can pause any offer
- [ ] Mobile restaurant screens: list, create, edit, pause work against local API; component tests green
- [ ] All list endpoints cursor-paginated with the standard envelope

## Verification
```bash
cd apps/api
php artisan test --filter=Offer
vendor/bin/pint --test && vendor/bin/phpstan analyse
cd ../mobile && npx tsc --noEmit && npx jest restaurant
```
Expected: all green. Manual: seed a verified claim, create an offer from the mobile form, see it on `GET /api/v1/places/{id}?include=offers`, pause it in Filament, confirm it disappears from `?active=1` browse.

## Gotchas
- Two quota semantics coexist: `quota_per_user` (02, per diner, enforced at issue in T-043) vs `quota_total`/`quota_per_day` (offer-wide). Keep enforcement in `isRedeemable()` so T-043 has a single source of truth.
- `redemptions_count` counter cache must count only billable/at-risk redemptions consistently — decide (and test) whether `void`/`expired` decrement it, otherwise quotas leak.
- `discount_value` meaning depends on `discount_type`: percent (1–100), **minor units** for `fixed_amount` (never floats/euros), item count for `free_item`.
- `ends_at IS NULL` = open-ended: every window query needs the `OR ends_at IS NULL` branch — easy to drop in the `near` + `active` combined query.
- `status = expired` is not auto-maintained by the DB; either compute activeness from the window (preferred, as in `active()` scope) or add a scheduled command — don't trust the column alone.
- Route naming: 03-api-design uses flat `/api/v1/offers` (not `/places/{id}/offers`, and not the mobile spec's `/restaurant/offers`); the API spec is canonical — mobile calls the flat routes.
