# T-041 — Place claims: restaurant verification (API + Filament)

- **Phase:** M4 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-030, T-008
- **Target paths:** `apps/api/app/Http/Controllers/PlaceClaimController.php`, `apps/api/app/Filament/Resources/`
- **Spec refs:** [06-monetization.md#restaurant-program](../06-monetization.md#2-restaurant-program), [02-data-model.md#place_claims](../02-data-model.md#312-place_claims)

## Context
Monetization starts with a verified restaurant operator: only a user with a `verified` place claim can create offers and verify redemptions (the `restaurant` auth level in 03-api-design §2 legend). Places API (T-030) and Filament admin (T-008) exist; the `place_claims` table ships in this task per the M4 migration order (02-data-model §6, migration 17). This unlocks T-042 offers CRUD and the whole M4 loop. App code lives in the separate app repo created by T-001.

## Implementation steps
1. Migration `create_place_claims_table` exactly per 02-data-model §3.12: `place_id` FK→places (cascade), `user_id` FK→users (cascade), `method` varchar(24), `status` varchar(16) default `'pending'`, `evidence_json` jsonb nullable, `verified_at`, `reviewed_by_user_id` FK→users (SET NULL). Indexes: **partial unique(`place_id`) where `status = 'verified'`** (one verified owner per place), index(`user_id`), index(`status`). Add CHECK constraints for the enum columns (varchar + CHECK, not native enums).
2. Enums in `app/Enums`: `ClaimMethod` (02 values: `phone_otp`, `email_domain`, `document`, `google_business` — **plus `website`**, required by 06-monetization §2.1 but missing from the 02 enum table; note the addition in the migration comment) and `ClaimStatus` (`pending`, `verified`, `rejected`).
3. `PlaceClaim` model + factory + `PlaceClaimPolicy`. Add a reusable authorization helper used by later tasks: `User::ownsPlace(Place $place): bool` = has a `place_claims` row with `status = verified` for that place. This is the canonical definition of the `restaurant` role ("user with an approved place_claims row for the target place", 03-api-design §5).
4. Controller under `App\Http\Controllers\Api\V1`, route `POST /api/v1/places/{place}/claims` (03-api-design §2.6, auth: user). Request validation per method:
   - `phone_otp`: fully automated — generate a 6-digit code, deliver to the place's Google-Places-listed `places.phone` via a `ClaimPhoneVerifier` service behind an interface (fake driver in tests/dev); second endpoint action verifies the entered code and flips status to `verified`.
   - `website`: generate a claim token stored in `evidence_json`; queued job fetches `https://{place.website}/.well-known/reelmap-verify.txt` or a meta tag containing the token (06 §2.1); match → `verified`.
   - `document`: accept an uploaded doc reference (presigned upload path on the media disk) in `evidence_json`; stays `pending` for Filament manual review (SLA 2 business days).
5. Verification side-effects in one place (`PlaceClaimService::approve()`): set `verified_at`, set `users.is_restaurant_owner = true` on first verified claim (02 §3.1), catch the partial-unique violation for competing claims and escalate to admin instead of 500.
6. Filament: `PlaceClaimResource` approval queue — list `pending` claims with place, user, method, rendered `evidence_json` (doc preview link); Approve/Reject actions recording `reviewed_by_user_id`. Rejection requires a reason stored in `evidence_json`.
7. Expose claim state on the place API: add `is_claimed` (verified claim exists) to `PlaceResource` (T-030) so clients can render the claimed badge; update the contracts schema per 03-api-design §5.
8. Pest feature tests: happy path per method (fake phone/website drivers), authz denial, validation error shape, second verified claim on same place rejected/escalated, role flag set.

## Acceptance criteria
- [ ] `POST /api/v1/places/{id}/claims` accepts `method` + evidence per spec for `phone_otp`, `website`, `document`; response uses the standard envelope
- [ ] `place_claims` migration matches 02-data-model §3.12 including the partial unique index on (`place_id`) where `status='verified'`
- [ ] Phone and website methods verify fully automatically (fake drivers in tests); document method lands in the Filament queue
- [ ] Filament approval queue lists pending claims and Approve/Reject works, recording `reviewed_by_user_id`
- [ ] Approval sets `users.is_restaurant_owner = true` and `verified_at`; `User::ownsPlace()` returns true only for the verified claimant on that place
- [ ] A second verified claim on the same place is impossible at DB level; competing pending claims escalate to admin rather than erroring
- [ ] Place API responses include a claimed badge field (`is_claimed`) once a claim is verified
- [ ] Feature tests cover happy paths, wrong-code/wrong-token failures, authz denial, and validation error shape

## Verification
```bash
cd apps/api
php artisan migrate:fresh --seed
php artisan test --filter=PlaceClaim
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: all PlaceClaim tests green; manual check — create a claim via API as a seeded user, approve it in `/admin`, then `GET /api/v1/places/{id}` shows `is_claimed: true`.

## Gotchas
- **Enum mismatch between specs:** 02-data-model's `ClaimMethod` lacks `website` while 06-monetization §2.1 requires it. Add `website` to the enum + CHECK constraint and leave a note; do not silently drop the website method.
- The partial unique index only guards `verified`; multiple `pending` claims per place are legal and expected (competing claims). Handle the unique-violation race in `approve()` inside a transaction.
- Website verification fetches an external URL — never do it inline in the request; queue it, set a strict timeout, and pin to the domain from `places.website` (SSRF: reject redirects to other hosts/IP literals).
- Do not build `/api/v1/admin/*` routes — all admin ops live in Filament (03-api-design §2.16 note).
- Re-verification after 12 months of operator inactivity / ownership dispute (06 §2.1) is a rule to note in the model, not automation to build now.
