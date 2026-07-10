# T-054 — Store readiness: policies, review checklist, EAS submit

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-049, T-050
- **Target paths:** `apps/mobile/`, `docs/`
- **Spec refs:** [07-risks-decisions.md#risk-register](../07-risks-decisions.md#1-risk-register), [05-mobile-app.md#expo-eas-workflow](../05-mobile-app.md#6-expo--eas-workflow-rn-beginner-oriented)

## Context

R-02 (app-store rejection) is a top launch risk: Reelmap is a UGC app, so Apple Guideline 1.2 (report/block/moderation), 5.1.1(v) (in-app account deletion), 4.2 (minimal functionality), and Google's UGC policy are hard gates. Moderation/reporting (T-049) and GDPR deletion (T-050) are done — this task closes the remaining UGC gaps (user blocking), hosts the legal docs, answers both privacy questionnaires, and submits production builds via EAS to TestFlight and the Play internal track (M5 exit criterion). App code lives in the separate app repo created by T-001.

## Implementation steps

1. **Gap check + user blocking (Apple 1.2)**: reporting exists (T-049) but blocking does not — add a minimal `blocks` table (`user_id`, `blocked_user_id`, unique pair), `POST /api/v1/users/{username}/block` + `DELETE .../block`, and filter blocked users' content out of feed/search/profiles for the blocker. Mobile: "Block user" in the same overflow menu as Report on `/users/[handle]` and share cards. Also surface a **moderation contact** (support email + link to content policy) in Settings → About/Help.
2. **Legal documents (IR-8)**: privacy policy, terms of service, and content/UGC policy hosted at stable public URLs (static pages, e.g. `https://reelmap.app/privacy`, `/terms` — marketing site or a minimal static bucket; source kept in `docs/legal/`). Privacy policy must cover: data collected (shares, location use, platform tokens), subprocessors (Stripe, OpenRouter, Google Places, Sentry, Expo), retention (ADR-010 72 h originals, NFR-11 90-day payloads), GDPR rights + contact. Link both URLs in the app (Settings) and in both store listings.
3. **App privacy questionnaires**: fill Apple's privacy "nutrition labels" (App Store Connect) and Google's Data safety form; keep the canonical answers in `docs/store/privacy-answers.md` so both stay consistent: identifiers, email, coarse+precise location (map + redemption geofence), user content (shares/photos), purchase history (redemptions), diagnostics (Sentry) — with purposes and "data deleted on account deletion" per T-050.
4. **iOS review checklist** (`docs/store/ios-checklist.md`), each item verified on a physical device production/preview build:
   - UGC (1.2): report content ✓ (T-049), block user ✓ (step 1), moderation contact ✓, EULA/content policy link ✓.
   - Account deletion in-app (5.1.1(v)): Settings → Privacy → Delete ✓ (T-050).
   - Sign in with Apple present since Google login is offered (parity rule, `05-mobile-app.md §1.4`).
   - 4.2 minimal functionality: submission notes narrative — map + AI analysis + offers exceed a thin wrapper.
   - Purpose strings in `app.config.ts` `infoPlist`: `NSCameraUsageDescription` (QR scan), `NSPhotoLibraryUsageDescription` (screen-recording upload), `NSLocationWhenInUseUsageDescription` (map + redemption verify), push notification prompt copy.
   - Payment-policy note (R-10): redemptions are physical-world dining services — external payment permitted (TheFork/Groupon precedent) — written into App Review notes.
   - Demo account for review (seeded user + a seeded share/place/offer) in App Review notes.
5. **Android checklist** (`docs/store/android-checklist.md`): Data safety form, UGC policy compliance (report/block visible), account-deletion URL (Play requires a web deletion path too — reuse the API-backed form), target API level current, closed-testing requirement (12 testers / 14 days for new personal accounts — verify the track started in M1 per §6.3; escalate if not, this gates production).
6. **Store assets**: app icon, screenshots per required device sizes (iPhone 6.7"/6.5", iPad if targeted — otherwise mark iPhone-only; Play phone + 7"/10" tablet), feature graphic (Play), description/keywords/subtitle; store copy in `docs/store/listing.md`.
7. **EAS production submit** (`05-mobile-app.md §6.2–6.3`): confirm `eas.json` production profile (`autoIncrement: true`, `EXPO_PUBLIC_API_URL=https://api.reelmap.app`, `channel: production`) and `submit.production` credentials (ASC API key, Play service-account JSON — stored in EAS secrets, never the repo). Run `eas build --profile production --platform all`, then `eas submit --platform ios` (→ TestFlight) and `eas submit --platform android` (→ internal track; first-ever Play upload is manual per §6.3). Verify EAS Update `channel: production` + `runtimeVersion { policy: "appVersion" }` so post-submit OTA is safe.
8. **Docs**: `docs/store/submission-runbook.md` — the exact command sequence, credential locations, and the manual physical-device share-sheet check (from T-053 gotchas) as a pre-submit gate.

## Acceptance criteria

- [ ] Privacy policy + terms + content policy hosted at public URLs, linked in-app and in both store listings; sources in `docs/legal/`.
- [ ] Apple privacy nutrition labels and Google Data safety form completed, consistent with `docs/store/privacy-answers.md`.
- [ ] Apple UGC requirements demonstrably met on a device build: report content, block user, moderation contact, in-app account deletion; Sign in with Apple present alongside Google login.
- [ ] Block user API + UI implemented; blocked users' content excluded from the blocker's feed/search/profiles (tested).
- [ ] iOS and Android review checklists completed and committed with each item checked/dated; App Review notes include demo account + external-payment reasoning (R-10).
- [ ] EAS production builds submitted: iOS build visible in TestFlight, Android build on the Play internal track (M5 exit criterion).
- [ ] EAS Update production channel verified safe (runtimeVersion policy `appVersion`) after submission.

## Verification

```bash
cd apps/mobile
npx expo-doctor && npx expo prebuild --no-install   # config plugins sane
eas build --profile production --platform all       # both builds succeed
eas submit --platform ios                            # → App Store Connect / TestFlight
eas submit --platform android                        # → Play internal track
cd ../api && php artisan test --filter=Block         # block API tests green
curl -sI https://reelmap.app/privacy | head -1       # 200
```

Manual: install the TestFlight build on a physical iPhone — walk the checklist: share a reel via the real share sheet, report + block a user, delete the demo account, confirm purpose-string prompts show the right copy. In App Store Connect confirm privacy labels render; in Play Console confirm Data safety + internal-track availability to testers.

## Gotchas

- **Apple 4.2 / UGC rejections** are the modal failure: reviewers reject UGC apps missing *block* (report alone is insufficient) or with unreachable moderation contact. Also expect a rejection if the demo account lands on an empty map — seed it in the production DB before submitting.
- Account deletion must be reachable **in-app without emailing support** (5.1.1(v)); a webview to a form is borderline — the T-050 native flow is the safe path. Google additionally requires a **web** deletion URL in Data safety.
- Sign in with Apple parity: if Google/social login ships, Apple sign-in is mandatory and must be a first-class button (not buried).
- **EAS credentials**: let `eas credentials` manage iOS certs/profiles — hand-managed profiles break the share-extension target (two bundle ids: app + `.ShareExtension`, both need provisioning). Play: first upload is manual; the service-account JSON needs release-manager permission before `eas submit` works.
- Play closed-testing rule (12 testers/14 days) for new personal accounts is a calendar dependency — if the track wasn't started early (M1 per spec §6.3), production access is blocked regardless of code readiness.
- Don't ship an OTA update between review approval and release that materially changes reviewed behavior (store-policy risk, §6.4 policy).
- Location permission: ask when-in-use only, at point of need (map open / redeem) — an at-launch blanket prompt with a vague purpose string draws rejections.
