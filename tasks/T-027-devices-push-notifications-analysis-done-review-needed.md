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
