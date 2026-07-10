# T-047 — Mobile: offers, redemption display, restaurant QR-verify screens

- **Phase:** M4 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-043
- **Target paths:** `apps/mobile/app/offers/`, `apps/mobile/app/restaurant/verify.tsx`
- **Spec refs:** [05-mobile-app.md#screen-inventory](../05-mobile-app.md#screen-inventory), [06-monetization.md#attribution-redemption-flow](../06-monetization.md#3-attribution--redemption-flow)

## Context
This is both ends of the redemption handshake on the phone: the diner's offer browse + code/QR display (05-mobile-app screens #17–18) and the restaurant staff's scanner (screen #20) hitting T-043's issue/verify endpoints. Offers browse also hangs off place detail (T-033). Requires a dev-client build (camera, location — not Expo Go). App code lives in the separate app repo created by T-001.

## Implementation steps
1. Install/config: `expo-camera` (CameraView barcode scanning), `react-native-qrcode-svg`, `expo-location`, `expo-brightness`. Add iOS `infoPlist` purpose strings (camera "Scan customer redemption QR codes", location "Confirm you are at the restaurant when verifying") and Android permissions in `app.config.ts`; document that a new dev-client build is required.
2. Offers browse (`apps/mobile/app/offers/index.tsx`, screen #17): list + map toggle of nearby active offers via `GET /api/v1/offers?near=lat,lng&radius_m=&active=1` (device location, graceful fallback to map-center); offer cards (title, discount, place, validity, attribution influencer); tap → offer detail with `terms` shown **before** issuing (06 §2.2). Also render the offers section on place detail (screen #9) if T-033 left the stub.
3. Redemption screen (`apps/mobile/app/offers/[id]/redeem.tsx`, screen #18):
   - "Redeem" CTA → `POST /api/v1/redemptions` `{offer_id, share_id?}` passing the referral context (share/place-source the diner navigated from — thread it through route params so T-043 freezes attribution correctly) with an `Idempotency-Key`.
   - Display state machine per 05 §testing note: `active → verified → expired`. Big QR of `qr_payload` (`react-native-qrcode-svg`) + grouped alphanumeric code, expiry countdown from `expires_at`, screen-brightness bump while QR visible (restore on blur).
   - Poll `GET /api/v1/redemptions/{id}` (`poll_interval` ~3s while `issued` and screen focused) → success state on `redeemed` (also reachable via `redemption.verified` push deep link, 05 §push table); expired state with "get a new code" CTA.
   - Error mapping: `redemption_invalid` reasons + quota/velocity/cooldown messages surfaced as friendly copy.
4. Restaurant verify screen (`apps/mobile/app/restaurant/verify.tsx`, screen #20 — spec calls it `/restaurant/scan`; keep the tasks.json filename and register the route): 
   - Camera permission request flow (denied → settings CTA + manual entry fallback).
   - `CameraView` with `barcodeScannerSettings: { barcodeTypes: ['qr'] }`; debounce/lock after first scan until result dismissed (no rapid duplicate submits).
   - Manual code entry field (auto-uppercase, strips dashes) as an always-available alternative.
   - On scan/submit: get current location via `expo-location` (foreground permission; geofence input for T-043) → `POST /api/v1/redemptions/verify` `{code, place_id, lat, lng}`.
   - Result sheet states: valid (green — diner handle, offer title, party/terms per 06 §3), `expired`, `already_redeemed` (idempotent replay renders as "already verified ✓", not an error), `wrong_place`, `not_found`, geofence-flagged warning. "Scan next" resets.
   - Entry point under Profile → "My restaurant" for `is_restaurant_owner` users only (guard the route).
5. State/query wiring per 05 conventions: TanStack Query keys `['offers', filters]`, `['redemptions', id]`; mutations `retry: 0`; countdown via local timer synced to server `expires_at` (never client-created expiry).
6. Jest component tests: offer card rendering; redeem screen state machine (issued → verified via mocked poll, issued → expired via timer); verify result sheet for each error reason; scanner lock prevents double submit (mock camera event fired twice → one mutation).

## Acceptance criteria
- [ ] Diner can browse nearby/active offers (list + map toggle) and open an offer with terms shown before redeeming
- [ ] Redeem flow issues via `POST /redemptions` with referral context and Idempotency-Key; screen shows QR + code + live expiry countdown with brightness bump
- [ ] Redemption screen transitions issued → verified (poll or push) → success state, and issued → expired with re-issue CTA
- [ ] Restaurant verify screen scans QR via `expo-camera` (QR-only barcode settings) and supports manual code entry fallback
- [ ] Foreground location is captured and sent with verify for the T-043 geofence; permission-denied path still allows verify attempt (server flags it)
- [ ] All verify outcomes rendered distinctly: success, expired, already_redeemed (treated as success-ish replay), wrong_place, not_found
- [ ] Double-scan/double-tap cannot fire two verify mutations (tested)
- [ ] Camera/location permission flows handled (request, denied, settings deep link); purpose strings present in `app.config.ts`
- [ ] Routes gated: verify screen only for restaurant owners; component tests green; `tsc --noEmit` + eslint clean

## Verification
```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npx jest offers restaurant
eas build --profile development --platform ios   # new native modules ⇒ new dev client
```
Manual on device (dev client, two accounts): diner issues a code on phone A; owner scans phone A's QR with phone B inside/near the seeded place → success sheet; scan again → "already verified"; wait past expiry on a fresh code → expired state. API side: `php artisan test --filter=Redemption` still green.

## Gotchas
- **Camera permissions**: `expo-camera` needs a dev-client rebuild — this screen silently no-ops in Expo Go. iOS review rejects missing/vague purpose strings.
- QR payload is the signed `qr_payload`, not the bare code — the scanner must accept both (QR → payload, manual entry → code) and the client never tries to parse/validate the payload itself.
- Scanner fires `onBarcodeScanned` many times per second while the QR is in frame — lock on first hit or you'll double-submit verify (server is idempotent, but the UI would flash two sheets).
- Countdown drift: compute remaining time from server `expires_at` vs `Date.now()` each tick; don't decrement a local counter that survives background/foreground.
- Brightness: restore previous brightness on navigation blur/unmount, not just on success — leaving a phone at max brightness is a battery complaint.
- Geofence UX: GPS indoors is bad; the server flags rather than hard-fails ambiguous locations (06 §3) — mirror that softness in copy ("location couldn't be confirmed, flagged for review"), don't block the visit at the counter.
- Offline at the counter is the norm-adjacent case: verify requires network; show a clear retry affordance, and never optimistically mark redeemed client-side.
