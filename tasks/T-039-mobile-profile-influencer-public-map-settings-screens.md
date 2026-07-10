# T-039 — Mobile: profile, influencer public map, settings screens

- **Phase:** M3 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-036, T-037, T-038, T-020
- **Target paths:** `apps/mobile/app/(tabs)/profile.tsx`, `apps/mobile/app/user/`, `apps/mobile/app/influencer/`, `apps/mobile/app/settings/`
- **Spec refs:** [../05-mobile-app.md#screen-inventory](../05-mobile-app.md#screen-inventory)

## Context
The backend social surface exists (public profiles T-036, follows T-037, claiming T-038, model list T-020); this task ships the Expo screens that use it: own profile, public user profile, influencer profile with embedded public map + follow button + claim flow, and the settings hub (AI model picker, account management, GDPR entry points). App code lives in the separate app repo created by T-001 (`apps/mobile`, Expo managed workflow + expo-router; stack rules in 05 §0 are fixed — TanStack Query for server state, zustand for UI state, generated types from `@reelmap/contracts` only).

## Implementation steps
1. **Routes (expo-router)** under `apps/mobile/app/` (target paths from tasks.json; each new segment gets a `_layout.tsx` stack):
   - `(tabs)/profile.tsx` — own profile tab (upgrade the M0 shell).
   - `user/[handle].tsx` — public user profile (05 screen #12; deep link target for `social.follow` push).
   - `influencer/[id].tsx` — influencer public profile; `influencer/[id]/claim.tsx` — claim flow modal.
   - `settings/index.tsx` (hub), `settings/ai-model.tsx` (05 screen #15), `settings/account.tsx` (edit profile fields), `settings/privacy.tsx` (GDPR entry points, stubbed per M3 scope), plus existing `settings/linked-accounts.tsx` from M1.
2. **API hooks** in `src/api/hooks/` with keys from the central `queryKeys` factory: `useUserProfile(handle)` → `GET /users/:handle`; `useUserMap(handle, bbox)`; `useInfluencer(id)`; `useInfluencerMap(id, bbox)`; `useFollow()`/`useUnfollow()` mutations (`POST /follows`, `DELETE /follows/:id`); `useMyFollows()`; `useClaimInfluencer()`; `useModels()` (`GET /analysis/models`); `useUpdateMe()` (`PATCH /me`). All typed from `@reelmap/contracts`.
3. **Own profile `(tabs)/profile.tsx`** (05 screen #13): header (avatar, name, @username, bio, followers/following counts from `GET /me`), tabs **Shares / Following** — Shares is a `FlashList` of my shares including `review`/`failed` (tap → `/shares/[id]/status` or `/review`); Following lists `GET /me/follows` with unfollow swipe/button. Settings gear → `/settings`. "Edit profile" → `settings/account.tsx`.
4. **Public user profile `user/[handle].tsx`** (05 screen #12): header with **follow button**, embedded public map, shares grid. Handle 404 (private/deleted) with a friendly "This profile isn't available" state. If `handle === session.user.username`, redirect to `(tabs)/profile`.
5. **Follow button component** (`src/features/social/FollowButton.tsx`): shared by user + influencer profiles. Optimistic update via TanStack Query (`onMutate` toggles state + counter, `onError` rolls back, `onSettled` invalidates the profile query). Treat 409 on follow as success (already following). Guests → redirect to login with return path.
6. **Influencer profile `influencer/[id].tsx`**: header (avatar, display name, platform badge + @handle with `Linking.openURL` to the original platform profile, cached follower count, "on Reelmap" place count), FollowButton, **embedded public map** of their promoted places — reuse the M2 map feature components (`MapView` + supercluster wrapper, `tracksViewChanges={false}`, viewport-driven fetch per 05 §4 but pointed at `GET /influencers/:id/map`), and a shares/places list below. Unclaimed influencer + viewer has a linked account or wants to claim → "Is this you? Claim this profile" banner → `influencer/[id]/claim`.
7. **Claim flow UI `influencer/[id]/claim.tsx`** (both T-038 methods):
   - Step 1: method chooser — "Verify with linked account" (enabled when `GET /platform-accounts` shows a matching platform link; deep-links to `settings/linked-accounts` otherwise) and "Verify with a code in your bio".
   - OAuth path: single `useClaimInfluencer` mutation `{method:'oauth'}` → success screen or inline 422 error with fallback prompt.
   - Bio-code path: request token → show copyable code with instructions + "I've added it — Verify" button → verify mutation; handle `token_not_found` (retry hint, bios cache slowly) and `token_expired` (regenerate). On success: invalidate `['me']` and the influencer query — `is_influencer` now gates the Wallet tab (05 §1.2), which appears without app restart.
8. **Settings hub `settings/index.tsx`** (05 screen #14): rows for Account, Linked accounts, AI model, Notifications toggle, Privacy, Log out (`POST /auth/logout` + SecureStore clear).
9. **AI model picker `settings/ai-model.tsx`** (05 screen #15): radio list from `GET /analysis/models` (grouped by `source` local/openrouter, showing `cost_class` and `default` flag, with an `auto` option), persists via `PUT /me/analysis-preference`; optimistic selection, invalidates `['me']`, `['models']`.
10. **Account management `settings/account.tsx`**: edit name, username, bio, avatar (via `expo-image-picker` + upload), and the **map visibility toggle** (`is_public`) → `PATCH /me`; 422 errors mapped to fields by the axios interceptor convention (05 §1.4).
11. **Privacy `settings/privacy.tsx`** (GDPR entry points only in M3 — full flows are M5, screen #16): "Export my data" and "Delete my account" rows rendering explanatory copy; wire the buttons to `POST /me/export` / `DELETE /me` when available, otherwise disabled with "Coming soon" state behind a single feature flag so M5 only flips it.
12. **Tests (jest-expo + RNTL + msw)**: FollowButton optimistic toggle + rollback on error; claim flow renders both methods and handles `token_not_found`; model picker posts the selected id; private profile 404 state; own-handle redirect.

## Acceptance criteria
- [ ] Own profile shows my shares (all statuses), following list, social counters, edit-profile entry; edit saves via `PATCH /me` with field-level 422 errors.
- [ ] `user/[handle]` shows a public user's profile, published shares, embedded public map, and a working follow/unfollow button with optimistic updates; private/deleted handles render a non-error empty state.
- [ ] `influencer/[id]` shows influencer identity (platform handle links out), their promoted-places map fed by `GET /influencers/:id/map` using the M2 map components, their shares, and a follow button.
- [ ] Claim flow UI supports both verification methods end-to-end: OAuth one-tap when a matching linked account exists, and bio-code issue → copy → verify with retry/expiry states; success flips `is_influencer` and reveals the Wallet tab without restart.
- [ ] Settings hub reaches Account, Linked accounts, AI model, Privacy, Logout; AI model picker lists `GET /analysis/models` (local + OpenRouter + auto) and persists the preference.
- [ ] Privacy screen exposes GDPR export/delete entry points (flag-gated stubs acceptable for M3).
- [ ] `tsc --noEmit`, eslint, and jest are green; all new API calls typed from `@reelmap/contracts` (no hand-written API types).

## Verification
```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npx jest
npx expo start --dev-client   # against local API with seeded users/influencers
```
Manual on simulator/device (dev client):
1. Profile tab → see own shares incl. a `review` one; tap → review screen opens.
2. Open a seeded public user via search or `reelmap://user/marcelo` deep link → follow → button flips instantly; kill network → follow → button rolls back with toast.
3. Open `reelmap://influencer/<id>` → map shows that influencer's pins only; follow, then Map tab → filter "Following" shows those pins (T-037 filter).
4. Claim: user with linked Instagram matching the handle → "Verify with linked account" → success → Wallet tab appears. Second influencer → bio-code path → verify fails with `token_not_found` copy, then succeeds against fixture bio.
5. Settings → AI model → pick an OpenRouter model → re-open screen → selection persisted (`GET /me` reflects it).

## Gotchas
- **Route naming drift:** 05 §1.2 places public profiles at `users/[handle]` and settings under root `settings/`; tasks.json pins `app/user/`, `app/influencer/`, `app/settings/`. Follow the tasks.json paths, but make sure push deep links (`social.follow` → `/users/:handle` per 05 §5.2) resolve — add the notification `url` mapping or a redirect route so taps don't 404.
- **Embedded map perf:** the influencer map is a second `MapView` instance — apply all 05 §4.4 rules (memoized markers, `tracksViewChanges={false}`, no region in state) or profile scrolling jank on Android is guaranteed; consider a static preview image + "open full map" if pin counts are large.
- **Wallet tab gating:** the tab's `href` is derived from `session.user.is_influencer` — after a claim you must refetch `/me` and update the zustand session store, or the tab won't appear until relaunch.
- **Optimistic follow + counter cache:** the server counter (T-037) may lag your optimistic value; always reconcile with `onSettled` invalidation rather than trusting the local math.
- **GDPR touchpoints:** the privacy screen ships in M3 as entry points but App Store review requires a working delete flow at first public release (05 screen #16) — keep the flag wiring real, not decorative, so M5 is a flip.
- **Guest access:** public profiles are unauthenticated API routes; screens must render for guests (browse-first), gating only follow/claim behind login with the pending-action resume pattern from 05 §2.3.
- **Bio-code UX honesty:** platform bio caches can take minutes — copy must set that expectation ("this can take a few minutes") or users will hammer verify into the rate limit.
