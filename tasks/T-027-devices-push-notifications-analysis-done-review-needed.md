# T-027 — Devices + push notifications (analysis done / review needed)

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-016, T-010
- **Target paths:** `apps/api/app/Models/Device.php`, `apps/api/app/Notifications/`, `apps/mobile/src/notifications/`
- **Spec refs:** [02-data-model.md#devices](../02-data-model.md#319-devices), [05-mobile-app.md#push-notifications](../05-mobile-app.md#5-push-notifications)

## Context

The pipeline transitions shares through the state machine and fires `ShareStatusChanged` (T-016), and the mobile app has an authenticated session (T-010). This task adds the notification leg: Expo push-token registration on both sides and pushes for `share.published` / `share.review_needed` / `share.failed` that deep-link back into the app — the "user left the app" recovery for the async pipeline. App code lives in the separate app repo created by T-001, NOT this plans folder.

## Implementation steps

### API (`apps/api`)

1. **`devices` migration + `Device` model** exactly per 02-data-model §3.19: `user_id` FK cascade, `expo_push_token` (unique), `platform` (`ios|android`), `device_name`, `app_version`, `last_seen_at`. Relation `User::devices()`.
2. **Endpoints** (`DeviceController`, `auth:sanctum`): `POST /api/v1/devices { token, platform, device_name?, app_version? }` — upsert on `expo_push_token` (re-registration moves a token to the current user, updates `last_seen_at`); `DELETE /api/v1/devices/{id}` (owner-only; also support delete-by-token for logout convenience).
3. **Expo push channel**: install `laravel-notification-channels/expo` (or a thin `ExpoPushChannel` posting to `https://exp.host/--/api/v2/push/send`, batched ≤100 messages). Channel reads recipient tokens via `routeNotificationForExpo()` returning all the user's device tokens.
4. **Notifications** in `app/Notifications/`: `SharePublished`, `ShareReviewNeeded`, `ShareFailed`. Payload convention per 05 §5.2 — `data: { type, url }`:
   | type | url |
   |---|---|
   | `share.published` | `/places/{place_id}` |
   | `share.review_needed` | `/shares/{share_id}/review` |
   | `share.failed` | `/shares/{share_id}/status` |
   Title/body copy short and place-named where available. Also write the `database` channel (table exists from M0) so M3's notification center picks these up for free.
5. **Listener** on `ShareStatusChanged`: dispatch the matching notification (queued on the `notifications` queue) only on transitions into `published`, `review`, `failed`.
6. **Receipt handling**: after sending, check Expo push tickets/receipts; on `DeviceNotRegistered`, delete/deactivate that `devices` row (queued job or inline in the channel).
7. **Tests** (Pest): device register/upsert/delete + authz; `Notification::fake()` asserting each status transition dispatches the right class with the right `data.type`/`data.url`; `Http::fake()` test of the channel payload shape + `DeviceNotRegistered` pruning.

### Mobile (`apps/mobile/src/notifications/`)

8. **Registration** (`registerForPush()` called on first authenticated launch and after login): soft pre-prompt explaining value → `Notifications.requestPermissionsAsync()` → `Notifications.getExpoPushTokenAsync({ projectId })` (projectId from `Constants.expoConfig.extra.eas.projectId`) → `POST /devices { token, platform, app_version }`. On logout: `DELETE /devices/:token`. Android: create the `default` channel with `importance: MAX` on startup (no-op on iOS). Add `expo-notifications` config plugin to `app.config.ts` if not present.
9. **Handlers**:
   - Foreground: `setNotificationHandler` shows a banner **except** when `data.url` equals the current route; `share.*` notifications additionally invalidate `['shares', id]` so an open AnalysisStatus updates instantly.
   - Tap: response listener does `router.push(data.url)` — one switch-free handler, the URL is the routing.
   - Cold start: in root layout, `getLastNotificationResponseAsync()` and stage the URL until auth resolves (same staging pattern as share intents, 05 §2.3).
10. **Tests**: unit-test the tap handler routing and the foreground suppress-on-same-route logic with mocked `expo-notifications`; msw test that registration posts the token.

## Acceptance criteria

- [ ] `devices` table matches the data model (unique `expo_push_token`), with `POST /api/v1/devices` upserting tokens for the authed user and `DELETE` removing them.
- [ ] Transitions into `published`/`review`/`failed` send an Expo push with `data: { type, url }` per the 05 §5.2 table (types `share.published`, `share.review_needed`, `share.failed`), and also persist a database notification.
- [ ] Tapping a notification deep-links to the exact screen (`/places/:id`, `/shares/:id/review`, `/shares/:id/status`), including from a cold start after auth resolves.
- [ ] Mobile requests permission behind a soft pre-prompt, registers the token with platform + app_version, creates the Android `default` channel, and unregisters on logout.
- [ ] Foreground notifications are suppressed on the target screen and invalidate the share query so open screens live-update; `DeviceNotRegistered` receipts prune dead tokens server-side.

## Verification

```bash
cd apps/api && php artisan test --filter=Device && php artisan test --filter=Notification
cd apps/mobile && npx tsc --noEmit && npm test -- --testPathPattern=notifications
```
Manual device steps (physical device — Expo push tokens are unavailable on simulators/emulators without workarounds): log in on the dev-client build → accept the prompt → confirm a `devices` row exists → share a URL, background the app → pipeline reaches `review` → push arrives → tap → lands on `/shares/:id/review`. Kill the app and repeat to verify the cold-start path. Optionally send a hand-crafted message via Expo's push tool with `data: { type: 'share.published', url: '/places/1' }`.

## Gotchas

- **Expo push tokens on simulators**: iOS simulators can't get real APNs-backed tokens and Android emulators need Play services — `getExpoPushTokenAsync` may throw or return junk. Guard with `Device.isDevice` (expo-device), skip registration otherwise, and do all push QA on physical hardware.
- `getExpoPushTokenAsync` requires the EAS `projectId` explicitly in dev builds — omitting it is the top "works in prod, fails in dev" bug.
- Tokens are per-install, not per-user: the upsert must reassign an existing token row to the newly logged-in user, or user B receives user A's share notifications on a shared device.
- Never mark delivery successful off the initial ticket — `DeviceNotRegistered` arrives in the **receipt** fetched later; skipping receipt checks leaves dead tokens accumulating and Expo may throttle the sender.
- Android without the `default` channel (importance MAX) shows nothing on 8.0+ — create it before the first notification can arrive, not lazily.
- Cold-start taps race the auth gate exactly like share intents — reuse the staging pattern; `router.push` before the navigator mounts crashes.
- iOS permission prompt is one-shot per install: always show the soft pre-prompt first; a denied user can only re-enable in Settings.

## Log

**2026-07-21 — IMPLEMENTED (branch `feat/T-027-devices-push-notifications`).** tasks.json T-027 → `in_progress`. Picked per the ROADMAP rule: ARCH phase complete → lowest incomplete phase = M1 → lowest-numbered ready pending task = T-027 (deps T-016, T-010 done).

**API (`apps/api`):**
- `devices` migration (02 §3.19 exactly: unique `expo_push_token`, `user_id` FK cascade + index, `platform`, `device_name`, `app_version`, `last_seen_at` default now; `timestamps=false`) + `Device` model + factory. `User::devices()` + `User::routeNotificationForExpo()` (all the user's tokens).
- `DeviceController` (`auth:sanctum`, `throttle:30,1`): `POST /devices {token,platform,device_name?,app_version?}` upserts on the token and **reassigns it to the current user** (per-install tokens); `DELETE /devices/{device}` accepts the numeric id (owner-only, 404 otherwise) OR the raw token (logout convenience — idempotent 204 even for an unknown/foreign token, scoped to the caller).
- Thin `ExpoPushClient` over `exp.host/--/api/v2/push` (send + getReceipts, batched ≤100, **never throws** — push is best-effort, a down service yields empty tickets). `ExpoChannel` (resolved as a class-string channel — `ChannelManager::createDriver` falls back to `container->make($class)`, no manager registration needed) sends one message per token, **prunes `DeviceNotRegistered` at the send-ticket level immediately**, and dispatches `CheckExpoReceipts` (delayed, `notifications` queue) for accepted tickets — Expo surfaces most dead tokens only in the later **receipt**, so the job polls and prunes those too.
- `ShareNotification` abstract base (queue `notifications`, `via = ['database', ExpoChannel::class]`, uniform `data:{type,url}` payload) + `SharePublished`/`ShareReviewNeeded`/`ShareFailed`. `SendShareStatusNotification` listener wired in `AppServiceProvider::boot` via `Event::listen(ShareStatusChanged::class, …)` (no EventServiceProvider here) — notifies the sharer ONLY on transitions into `published`/`review`/`failed`; `forceResetStatus` (moderation) doesn't fire the event so no re-notify.
- Config `services.expo.*` (`EXPO_PUSH_BASE`, optional `EXPO_ACCESS_TOKEN`, timeout, `EXPO_RECEIPT_DELAY_MINUTES=15`).

**Mobile (`apps/mobile/src/notifications/`):**
- `routing.ts` (pure): `data`/`url` extraction (url must start with `/`, guards against a non-route push target), `shareIdFromUrl`, `isOnTargetRoute` (query/trailing-slash-insensitive).
- `push.ts`: `registerForPush(confirm?)` (guards `Device.isDevice`; **soft pre-prompt** via injectable confirm before the one-shot OS prompt; `getExpoPushTokenAsync({projectId})` with the EAS projectId; POST /devices; **best-effort — the token fetch/POST is try/caught so a failure never crashes the app**), `unregisterPush` (DELETE by token, best-effort), `configureForegroundHandler` (suppress banner+sound on the target route), `setupAndroidChannel` (`default`, MAX importance), module-level `setCurrentPath`.
- `use-push-notifications.ts` hook (mounted once as `<PushBridge/>` in `_layout`, inside the providers): mirrors `usePathname` for suppression; one-time handler+channel setup + **cold-start tap** (`getLastNotificationResponseAsync` → push if authed, else stage in `useUiStore.pendingNotificationUrl`); foreground `addNotificationReceivedListener` → invalidate `['shares', id]`; tap `addNotificationResponseReceivedListener` → `router.push(url)`; register on `status==='authed'` (covers first authed launch AND post-login) + flush the staged cold-start url. `useAuth` `useLogout` calls `unregisterPush()` FIRST (while the token is still valid). `ui` store gained `pendingNotificationUrl`.

**KEY DEVIATION (ADR, spec NOT edited): the `share.published` deep-link is `/place/{slug}` (the mobile router's actual route — `app/place/[slug].tsx`, singular, by slug), NOT the 05 §5.2 table's `/places/:place_id`.** Review→`/shares/{id}/review`, failed→`/shares/{id}/status` match the real routes. Field name is `token` per this task md (05 §5.1's `expo_push_token` superseded).

**Gates green:** API Pint + PHPStan L6 + **Pest 891** (+19: DeviceApi, ShareStatusNotification, ExpoChannel/receipts); mobile `expo lint` + tsc + **jest 303** (+17: routing, push register/permission/suppress, hook orchestration/cold-start). Dev DB migrated (plain `migrate`, additive). `jest.setup.ts` gained global mocks (expo-notifications, expo-constants, `Device.isDevice`, `usePathname`).

**DEFERRED:** receipt-polling relies on the delayed `CheckExpoReceipts` job (Horizon/queue must run it); the other 05 §5.2 push types (`social.follow` DB-only already, `offer.*`/`redemption.*`/`wallet.*`) land with M3/M4. On-device QA (physical device — Expo tokens need real hardware) per the Verification section. **On merge flip T-027 → done.** [[reelmap-progress]]

**Pre-PR `/coderabbit` pass + PR #133 open (2026-07-21).** 3 parallel grounded review agents (no 🔴; fixed 🟡: shared `isDeviceNotRegistered` non-array guard, best-effort wrapping of the mobile cold-start/permission/channel calls, `//host` rejection in `urlFromData`, real bracketed-token delete test + receipt-map assertion). `/security-review` NO vulns. `/simplify` applied (`Http::baseUrl` like sibling clients; `share_id` in the Expo data bag → invalidate `['shares',id]` from data, removing the URL-regex + covering the `published` case; place eager-load only on the published branch). Grounding clean (gitleaks/semgrep 0). Gates re-run green: API Pint+PHPStan L6+**Pest 892**, mobile expo lint+tsc+**jest 303**. Commits `d2a393d`+`82078bb`; approval receipt written for HEAD `82078bb`. **Awaiting CI + user merge authorization — agent does not self-merge. On merge flip tasks.json T-027→done.**
