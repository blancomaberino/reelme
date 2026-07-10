# T-040 — Notification center (API + mobile)

- **Phase:** M3 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-027, T-037
- **Target paths:** `apps/api/app/Http/Controllers/NotificationController.php`, `apps/mobile/app/notifications.tsx`
- **Spec refs:** [../03-api-design.md#notifications](../03-api-design.md#notifications), [../05-mobile-app.md#push-notifications](../05-mobile-app.md#push-notifications)

## Context
T-027 shipped Expo push (devices table, `share.*` notifications) but pushes are ephemeral — M3 adds the persistent in-app notification center backed by Laravel's stock `notifications` table (data-model §3.18, migrated in M0). T-037 already writes the `NewFollower` database notification; this task makes all notification classes dual-channel (database + push), exposes list/mark-read endpoints (03 §2.15), and builds the mobile screen with deep links and badge count (05 screen #23). App code lives in the separate app repo created by T-001.

## Implementation steps
1. **Standardize notification classes** under `apps/api/app/Notifications/` so every user-facing type uses `via(): ['database', ExpoPushChannel::class]` (reuse T-027's channel) and a shared `toArray()`/`toDatabase()` payload convention mirroring the push convention from 05 §5.2:
   ```php
   public function toDatabase($notifiable): array {
       return [
           'type' => 'share.published',          // stable machine type
           'url'  => "/places/{$this->place->slug}", // in-app deep link path
           'title' => '...', 'body' => '...',
           // type-specific ids for analytics: share_id / follower / offer_id / payout_id
       ];
   }
   ```
   Types required now: `share.published` (analysis done), `share.review_needed`, `share.failed` (retrofit T-027's classes to also hit the database channel), `social.follow` (T-037). Define `redemption.verified`/`offer.redeemed` and `wallet.payout` as the type-string contract only (constants/enum in contracts) — the M4 tasks emit them; the center must render unknown-but-well-formed types generically.
2. **API — `App\Http\Controllers\Api\V1\NotificationController`** (physical file per target path), routes `auth:sanctum`:
   - `GET /api/v1/notifications` → cursor-paginated `auth()->user()->notifications()` (latest first); serialize `id` (uuid), `type` (the `data.type` machine string, not the PHP class), `data`, `read_at`, `created_at`. `meta.unread_count` on every page so the app can badge cheaply; support `?unread=1` filter.
   - `POST /api/v1/notifications/read` → body `{ids: [uuid,...]}` **or** `{all: true}` (03 §2.15); scope strictly to the auth user's notifications (`$user->unreadNotifications()->whereIn('id', $ids)->update(['read_at' => now()])`); return the new `unread_count`.
3. **Contracts**: JSON Schemas for the notification resource and read request; add the notification-type string enum to `packages/contracts` so mobile switch statements are typed.
4. **Pest tests**: list returns only own notifications (authz denial for foreign ids in mark-read — foreign ids are silently ignored, not 403-leaked); pagination + `unread_count` correctness; `{all:true}` marks everything; each M3 notification class writes a database row with `type` + `url` keys (table-driven test); rate-limit headers present.
5. **Mobile — `apps/mobile/app/notifications.tsx`** (05 screen #23), pushed from a bell icon (with badge) in the Feed/Profile headers:
   - `useNotifications()` infinite query on `GET /notifications` (`FlashList`, pull-to-refresh, cursor pagination); sectioned "New" (unread) / "Earlier".
   - Row rendering per `data.type` with icon + title/body + relative time; unread rows tinted. Unknown types render title/body generically (forward-compat with M4 types).
   - Tap → `router.push(item.data.url)` (same single-switch-free convention as push taps, 05 §5.2) and optimistically mark that id read via the mutation.
   - "Mark all read" header action → `POST /notifications/read {all:true}`, optimistic clear, invalidate `['notifications']`.
6. **Badge count**: expose `useUnreadCount()` from the first page's `meta.unread_count`; drive (a) the in-app bell badge, and (b) the OS icon badge via `Notifications.setBadgeCountAsync(n)` on app foreground and after mark-read. Refresh on: screen focus, foreground push received (the 05 §5.2 foreground handler additionally invalidates `['notifications']`), and after any mark-read.
7. **Push → center coherence**: every push now has a database twin (step 1), so a tapped or missed push always appears in the center; notification tap handler and center rows navigate through the identical `data.url`, keeping one routing path.
8. **Mobile tests (jest-expo + msw)**: list renders fixtures per type with correct deep-link push on tap; mark-all clears unread styling and posts `{all:true}`; badge hook reflects `meta.unread_count`.

## Acceptance criteria
- [ ] `GET /api/v1/notifications` returns the auth user's database notifications, cursor-paginated, newest first, with `meta.unread_count` and an `?unread=1` filter; users can never see or mutate another user's notifications.
- [ ] `POST /api/v1/notifications/read` supports both `{ids: []}` and `{all: true}` and returns the updated unread count.
- [ ] Analysis done (`share.published`), review needed (`share.review_needed`), failed (`share.failed`), and follow (`social.follow`) all persist database notifications with the `{type, url, title, body}` payload convention; `redemption.*` and `wallet.payout` type strings are defined in contracts for M4.
- [ ] Mobile notification center lists notifications with per-type rendering, unread/earlier sections, pull-to-refresh, and infinite scroll; tapping a row deep-links via `data.url` (place, share review/status, user profile) and marks it read.
- [ ] "Mark all read" works optimistically; bell badge and OS icon badge reflect `unread_count` and update on focus, foreground push, and mark-read.
- [ ] Pest + jest suites cover list scoping, mark-read variants, payload shape per type, row rendering, and deep-link navigation.

## Verification
```bash
cd apps/api && composer test -- --filter=Notification && vendor/bin/pint --test && vendor/bin/phpstan
cd apps/mobile && npx tsc --noEmit && npx jest notifications
```
Manual:
```bash
# seed activity: share a reel to completion, have another user follow you, then:
curl -s http://localhost:8000/api/v1/notifications -H "Authorization: Bearer $TOKEN" | jq '.meta.unread_count, .data[0]'
curl -s -X POST http://localhost:8000/api/v1/notifications/read -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"all":true}' | jq .meta.unread_count   # → 0
```
On device (dev client): trigger a follow from a second account → push arrives AND the bell badge increments; open the center → `social.follow` row present; tap it → lands on the follower's profile; "Mark all read" → badge clears and `setBadgeCountAsync(0)` clears the icon badge.

## Gotchas
- **`type` column vs `data.type`:** Laravel stores the PHP class FQCN in the `type` column — never expose that; the API `type` field must come from `data.type`. Filtering by type should query the jsonb `data` field (it's `jsonb` per data-model §3.18), not the class column.
- **Fan-out cost:** notifications here are one-recipient events (your share finished, someone followed *you*) — there is no follower-broadcast ("X published a place") in M3. Don't add one casually: notifying N followers per publish is an N-row insert + N pushes per share; if product asks for it, it needs a queued fan-out job and batched Expo pushes, not a loop in the event listener.
- **Unread-count queries:** `unread_count` on every list page is one indexed count on (`notifiable_type`,`notifiable_id`) + `read_at IS NULL` — fine; do not maintain a counter cache for it in M3 (mark-all-read makes caches annoying), but keep the count out of `GET /me` to avoid making every session hydrate pay for it.
- **Mark-read idempotency + scoping:** filter `whereIn(id)` through the user relation so foreign uuids are silently ignored (no information leak via 403/404 differences); already-read ids are a no-op.
- **Deep links that expired:** a `share.review_needed` notification may point at a share that has since been published or deleted — target screens must degrade gracefully (redirect status→place, or "no longer available"), since old notifications are never rewritten.
- **Badge drift:** OS badge is set client-side only; always derive it from the server's `unread_count` (never increment locally) or it desyncs across devices.
- **uuid PKs:** the stock table uses uuid `id` — mobile types and mark-read payloads are string ids; don't run them through the ULID helpers used elsewhere.
