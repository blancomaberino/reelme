# T-043 — Redemptions: issue code/QR + verify endpoint + anti-fraud

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-042
- **Target paths:** `apps/api/app/Http/Controllers/RedemptionController.php`, `apps/api/app/Services/Redemptions/`
- **Spec refs:** [06-monetization.md#attribution-redemption-flow](../06-monetization.md#3-attribution--redemption-flow), [02-data-model.md#redemptions](../02-data-model.md#314-redemptions)

## Context
The redemption is the payable event of the entire business model: a diner gets a single-use code/QR for an offer, restaurant staff verifies it, and that verified visit is what the restaurant pays for and the influencer earns from (06-monetization §1, §3). This task ships the `redemptions` table (M4 migration 19), issue + verify endpoints with the full anti-fraud table from 06 §3, and exactly-once semantics at the DB level. T-044 hooks the ledger into the verify transaction. App code lives in the separate app repo created by T-001.

## Implementation steps
1. Migration `create_redemptions_table` per 02-data-model §3.14: `offer_id` FK→offers (**RESTRICT** delete), `user_id` FK→users, `code` char(10) unique, `qr_payload` varchar(255), `status` (`RedemptionStatus`: `issued`, `redeemed`, `expired`, `void`, default `issued`), `issued_at` default now(), `expires_at`, `redeemed_at`, `redeemed_by_user_id` FK→users, `attributed_influencer_id` FK→influencers (SET NULL), `attributed_share_id` FK→shares (SET NULL), `fee_amount` bigint nullable (minor units, set at redemption), `currency` char(3) nullable. Indexes: unique(`code`); (`offer_id`,`status`); (`user_id`); (`attributed_influencer_id`); (`attributed_share_id`); **partial unique(`offer_id`,`user_id`) where `status = 'issued'`** — the DB guard for "one active redemption per user/offer".
2. `Services/Redemptions/RedemptionIssuer`:
   - Code: 10-char Crockford base32 from `random_bytes` (02 says char(10); 06's "8-char" is superseded), retry on unique collision; display-format with dashes client-side only — store bare.
   - `qr_payload`: signed token variant of the code (`URL::temporarySignedRoute` or HMAC token embedding code + redemption id) so scanned QRs can't be forged from a guessed code.
   - `expires_at = now() + 24h` (06 §3 sequence).
   - **Attribution frozen at issue** (02 §5): resolve `attributed_share_id` from the diner's referral context (client sends the `share_id`/place-source context it navigated from), fall back to the place's `is_primary` place_source; `attributed_influencer_id` from that share's `source_posts.influencer_id` (last-touch).
   - Issue-time checks (each a typed exception → `redemption_invalid` 422 with `details.reason`): `Offer::isRedeemable()` (T-042), partial-unique active code, `quota_per_user`, velocity (Redis `RateLimiter`: max 3 issues/day and 10/week per diner), cooldown (same diner, same place, ≤ 1 redeemed per 7 days), self-dealing (diner = verified operator of the place → blocked).
3. `Services/Redemptions/RedemptionVerifier::verify(User $staff, string $code, Place $place, ?Point $staffLocation)` — the exactly-once core:
```php
return DB::transaction(function () use (...) {
    $r = Redemption::where('code', $code)->lockForUpdate()->first();
    if (!$r) throw RedemptionInvalid::notFound();
    if ($r->status === RedemptionStatus::Redeemed) return VerifyResult::replay($r); // idempotent
    if ($r->status !== RedemptionStatus::Issued) throw RedemptionInvalid::state($r);
    if ($r->expires_at->isPast()) throw RedemptionInvalid::expired($r);
    if ($r->offer->place_id !== $place->id) throw RedemptionInvalid::wrongPlace();
    $this->geofence->assertWithin($staffLocation, $place, 500); // ST_DWithin, log+flag on fail
    $updated = Redemption::whereKey($r->id)->where('status', 'issued')
        ->update([...redeemed fields...]);           // guarded state flip
    if ($updated !== 1) throw RedemptionInvalid::alreadyRedeemed();
    event(new RedemptionVerified($r->refresh()));    // T-044 ledger listener joins this tx
    return VerifyResult::fresh($r);
});
```
4. Verify-side anti-fraud per 06 §3 table: endpoint auth = Sanctum user with `ownsPlace($place)` (policy); staff velocity limit 30 verifies/hour with admin alert threshold; geofence failures logged + flagged (admin-overridable), not silently passed; flag (don't block) operator redeeming their own claimed influencer's offers.
5. Controller + routes per 03-api-design §2.13: `POST /api/v1/redemptions` (body `{offer_id, share_id?}`, `Idempotency-Key` header honored per 03 §1), `GET /api/v1/redemptions/{id}` (owner), `POST /api/v1/redemptions/verify` (body `{code, place_id}`, restaurant auth, rate limit 30/min per restaurant account, response shape per 03 §3.4 incl. `attribution` block), `GET /api/v1/me/redemptions`, `GET /api/v1/places/{id}/redemptions` (restaurant log). Errors: `redemption_invalid` with `details.reason` ∈ `{expired, already_redeemed, wrong_place, not_found}`.
6. Expiry sweep: scheduled command flips overdue `issued` → `expired` (never billable, 06 §2.3); push notification to diner on verify (`redemption.verified`) and to owner (`offer.redeemed`) reusing T-027 infrastructure.
7. Pest tests (`tests/Feature/Monetization/RedemptionTest.php`): issue happy path with frozen attribution; each fraud rule as its own test (duplicate active code 409/422, daily/weekly velocity, cooldown, self-dealing, geofence out-of-range, staff velocity); verify happy path; **concurrency test** — two parallel verifies of one code (e.g. `Bus`/pcntl or sequential with a stale model) → exactly one `redeemed`, second gets idempotent replay of the prior result; expired code; wrong-place code.

## Acceptance criteria
- [ ] `POST /api/v1/redemptions` issues a unique 10-char Crockford base32 code + signed `qr_payload` with `expires_at` = issue + 24h
- [ ] `attributed_influencer_id` + `attributed_share_id` are denormalized at issue time from the diner's referral context (fallback: primary place_source) and never recomputed
- [ ] Partial unique index (`offer_id`,`user_id`) where `status='issued'` exists and a second issue attempt while one is active is rejected
- [ ] `POST /redemptions/verify` marks redeemed **exactly once** — guarded UPDATE inside a transaction; concurrent second call returns the prior result (idempotent on `code`), proven by test
- [ ] Verify requires a Sanctum user with a verified claim on `place_id`; wrong place → `redemption_invalid` / `wrong_place`
- [ ] Geofence: staff device beyond 500 m of `places.location` (PostGIS `ST_DWithin`) fails verification, is logged + flagged, and is admin-overridable
- [ ] Velocity limits enforced and tested: 3 issues/day + 10/week per diner; `quota_per_day` per offer; 30 verifies/hour per staff account
- [ ] Cooldown (same diner/place, 7 days) and self-dealing (diner = operator) blocks tested
- [ ] Expired and already-redeemed codes rejected with `details.reason` per 03 §3.4; expiry sweep command flips stale `issued` → `expired`
- [ ] Offers cannot be deleted while redemptions reference them (FK RESTRICT)

## Verification
```bash
cd apps/api
php artisan test --filter=Redemption
php artisan test --filter=Monetization
vendor/bin/pint --test && vendor/bin/phpstan analyse
```
Expected: green, including the double-verify concurrency test. Manual: issue via API, `POST /redemptions/verify` twice with the same code — first returns `status: redeemed`, second returns the identical prior payload, DB has exactly one `redeemed_at`.

## Gotchas
- **Double-submit on verify** is the trap this task exists for: staff double-taps, retries on flaky Wi-Fi, or two devices scan the same QR. Only the row-lock + `where('status','issued')` guarded UPDATE combination is safe; a read-then-write without the guard loses the race.
- Idempotent replay must return the **original** result (03 §1 semantics), not an error — the restaurant UI treats "already redeemed just now by you" as success.
- Code format: 02 says char(10), 06 says 8-char — follow 02 (canonical DB spec). Exclude Crockford ambiguous chars (I, L, O, U). `RLM-7F3K-92QX`-style dashes in 03's example are display formatting; normalize input by stripping dashes/uppercasing before lookup.
- Attribution FKs are SET NULL but money must survive — that's fine here because T-044's ledger rows are the immutable copy (02 §3.14 note); never join through `shares` at payout time.
- Geofence needs the staff device location in the verify request body; missing location ⇒ flag-and-log path, not a hard 500. Spoofing is acknowledged v1 residual risk.
- `fee_amount`/`currency` stay NULL at issue; they're set at redemption time (by T-044's config-driven fee) — don't compute the fee at issue or offers repriced mid-flight bill wrong.
- Route shape: 03-api-design's `POST /api/v1/redemptions` `{offer_id}` is canonical, not tasks.json's `/offers/{id}/redemptions` shorthand.
