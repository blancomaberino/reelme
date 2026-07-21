# T-025 — Mobile: share-intent receiver + ShareIngest screen

- **Phase:** M1 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-010, T-016
- **Target paths:** `apps/mobile/app/share/`, `apps/mobile/app.config.ts`
- **Spec refs:** [05-mobile-app.md#share-sheet-ingestion-flow](../05-mobile-app.md#2-share-sheet-ingestion-flow-the-core-ux)

## Context

The mobile app has auth + the axios/TanStack Query layer (T-010) and the API accepts `POST /shares` with the status machine (T-016). This task wires Reelmap's signature interaction: appearing in the iOS/Android share sheet, catching the shared URL (including cold start), and the ShareIngest screen that submits it — plus the duplicate, private-post, and manual-fallback flows. It unlocks T-026 (status/review screens). App code lives in the separate app repo created by T-001 (`apps/mobile`), NOT this plans folder.

## Implementation steps

1. **Install `expo-share-intent`** (latest, matched to the installed Expo SDK) and add the config plugin to `app.config.ts` exactly per spec §1.5/§2.1:
   ```ts
   plugins: [
     // ...existing
     ['expo-share-intent', {
       iosActivationRules: {
         NSExtensionActivationSupportsWebURLWithMaxCount: 1,
         NSExtensionActivationSupportsText: true,
       },
       androidIntentFilters: ['text/*'],
     }],
   ]
   ```
   Do **not** enable image/video activation rules in M1 (screen recordings go through an in-app picker). **This requires a new dev-client build** — `eas build --profile development --platform ios|android` — because it changes native config; document this in `apps/mobile/README` (share sheet never works in Expo Go, and iOS share sheet must be tested on a physical device).
2. **Root-layout listener** (`app/_layout.tsx`): `useShareIntent()` from expo-share-intent. On `hasShareIntent`: `extractUrl(shareIntent.webUrl ?? shareIntent.text)` (first URL regex — Instagram often shares `"Check this out! https://www.instagram.com/reel/…"` as plain text) → stage in `useUiStore.setState({ pendingShareUrl })` → `router.push('/share-ingest')` → `resetShareIntent()`.
3. **Cold start ordering** (§2.3, critical): process the intent only **after** the auth check resolves. Sequence: keep `expo-splash-screen` visible → read token from SecureStore + `GET /me` → then read share intent → route. Never navigate before providers mount, and never lose the intent to the auth redirect: `pendingShareUrl` is staged in `useUiStore` *before* any redirect; if unauthenticated, `(auth)/login` shows a "Sign in to add this place" banner and post-login the root layout sees `pendingShareUrl` and pushes `/share-ingest`.
4. **ShareIngest screen** (`app/share-ingest.tsx`, modal presentation; the `app/share/` dir holds the ingest feature components + the manual-fallback screen and the "+" tab landing `(main)/share/index.tsx` with a paste field for the manual-entry path):
   - Read-only URL field prefilled from `pendingShareUrl` (editable/pasteable when opened from the + tab), platform badge parsed client-side from hostname (instagram.com / tiktok.com / x.com|twitter.com / youtube.com|youtu.be).
   - Primary button **"Analyze"** → TanStack Query mutation `POST /shares { url, shared_text, source_hint }` (types from `@reelmap/contracts`) → on `201/202` `router.replace('/shares/[id]/status')` and clear `pendingShareUrl`.
5. **Duplicate flow** (§2.4): mutation error `409` with `error.code: "duplicate_share"` → non-error UI "You already added this one." with **View place** (`/places/:place_id` if present) / **View status** (`/shares/:existing_share_id/status`). Never a red failure screen.
6. **Private-post flow entry** (§2.6): when a share later fails with `failure_reason: "private_post"`, the status screen (T-026) opens a two-option sheet — build the destinations now:
   - "Link your Instagram account" → `/settings/linked-accounts` (screen may be a stub if T-015's mobile side isn't done; wire the OAuth webview via `expo-web-browser` against `POST /platform-accounts/instagram/link` when available), then **Retry** (`POST /shares/:id/retry`).
   - "Add it manually" → manual-fallback screen (next step).
7. **Manual fallback screen** (`app/share/manual.tsx` or feature component): multiline "Paste the caption" field; **Upload a screen recording** via `expo-image-picker` (`mediaTypes: ['videos']`), client-side caps 60 s / 100 MB; upload through `POST /shares/:id/media` (presigned R2 URL flow, show upload progress); submit `POST /shares/:id/manual { caption?, media_id? }` → back to the status screen polling.
8. **Component tests** (jest-expo + RNTL + msw, per §7.1): `extractUrl` parses URL out of raw share text variants; unauthenticated gate stages URL and resumes post-login; Analyze fires the typed mutation and navigates; 409 renders the duplicate UI (not an error state); manual fallback submits caption/media payload shape.

## Acceptance criteria

- [ ] `expo-share-intent` config plugin configured with the exact iOS activation rules (WebURL max 1 + Text) and Android `text/*` intent filter; Reelmap appears in both share sheets in a dev-client build.
- [ ] A URL shared from Instagram/TikTok/X/YouTube opens ShareIngest prefilled with the URL and platform badge — including when the app was cold-started by the intent, and including the unauthenticated case (URL survives login).
- [ ] Analyze submits `POST /shares` and navigates to `/shares/[id]/status`; 409 `duplicate_share` shows the friendly duplicate UI with View place / View status.
- [ ] Private-post flow offers account-linking (→ retry) and manual fallback; manual fallback supports pasted caption + screen-recording upload (picker, progress, 60 s/100 MB caps) via `/shares/:id/media` + `/shares/:id/manual`.
- [ ] Dev-client requirement documented (Expo Go explicitly unsupported; rebuild needed after the plugin change; iOS share sheet verified on a physical device).
- [ ] Component tests cover URL extraction, auth-gate staging/resume, submit, and duplicate paths.

## Verification

```bash
cd apps/mobile
npx tsc --noEmit && npx eslint . && npm test
eas build --profile development --platform ios   # one-time rebuild for the plugin
npx expo start --dev-client
```
Manual device steps: install the dev build on a physical iPhone + Android device → open Instagram → any public reel → Share → **Reelmap** appears → tap it with Reelmap (a) foregrounded-then-killed (cold start) and (b) logged out → in all cases ShareIngest opens with the URL after auth → Analyze → lands on the status screen. Repeat sharing the same URL → duplicate UI. Maestro entry point for CI: deep link `reelmap://share-ingest?url=<fixture>` (the real share sheet is manual-only).

## Gotchas

- **iOS share-extension activation rules are exact-match predicates**: if Instagram shares plain text (it often does), only `NSExtensionActivationSupportsText` makes Reelmap show up — keep both rules, and don't add media rules or Reelmap appears in photo share sheets. Changes to these rules require a rebuild AND sometimes a device reboot/re-install for the share sheet cache to refresh.
- The iOS **simulator share sheet is unreliable** — test ingestion on a physical device only; treat simulator absence of Reelmap in the sheet as non-signal.
- Cold start races: reading the intent before the router/providers mount crashes; navigating before the auth gate resolves drops the intent. The staging-in-zustand-then-route pattern in §2.3 is the fix — don't "simplify" it.
- `useShareIntent` delivers the intent once; calling `resetShareIntent()` too early (before staging) loses it, too late causes re-fires on resume.
- Android 12+ requires the intent filter generated by the plugin — never hand-edit `AndroidManifest.xml` (managed workflow, config plugins only; no `expo eject`).
- Duplicate 409 must be branched on `error.code === 'duplicate_share'`, not status code alone — other 409s (invalid transition) exist.
- Instagram share text can contain trailing punctuation/newlines around the URL; the extractor should strip and canonicalization happens server-side (IngestShare) — don't over-normalize client-side.

## Log

- **2026-07-20 — in_progress (branch `feat/T-025-share-intent-receiver`).** The ingest **core already shipped** before this task ran (share-sheet registration via the `expo-share-intent` plugin, cold-start capture in `app/+native-intent.tsx`, `ShareIntentProvider`, and `app/(main)/share.tsx` doing submit → live-status polling → published/review/multi-place/T-076 auto-open + idempotent-replay handling). This pass closes the **implementable, API-backed gaps**, adapting to how the API actually behaves (see **ADR-087**):
  - **Auth-gate URL survival** — the shared payload is staged in `useUiStore.pendingShare` **before** the sign-in redirect (`app/_layout.tsx` `ShareIntentRedirect`); a logged-out share shows a "sign in to add this place" banner on `(auth)/login` and **resumes** on the ingest screen post-login. (The old `pendingShareUrl` store field was stubbed for exactly this but never wired — a guest share was silently dropped. Fixed.)
  - **Platform badge** on the ingest form (`platformFromUrl`), **`extractUrl`** pulls the link out of Instagram-style shared text, **"already added"** note driven by `meta.idempotent_replay`, and a **Retry** action on `Failed` shares (`POST /shares/:id/retry`). Added the Android `text/*` intent filter to the plugin config.
  - Tests: `src/api/__tests__/shares.test.ts` (extractUrl/platformFromUrl), extended `app/(main)/__tests__/share.test.tsx` (badge, replay note, retry, store-resume), extended `useCreateShare` test (replay flag + sharedVia), `app/(auth)/__tests__/login-share-resume.test.tsx` (banner + resume), `app/__tests__/share-intent-redirect.test.tsx` (guest→login staging, authed→ingest, URL-from-text). Full suite green (251 tests); `expo lint` + `tsc --noEmit` clean.
- **Deviations from the spec (ADR-087), NOT spec edits:**
  - **`409 duplicate_share` → idempotent replay.** The API never 409s a re-shared post; it replays the existing share (`202` + `meta.idempotent_replay`). The friendly "already added" UX is driven off that flag instead of a 409 branch. The acceptance criterion / gotcha about `error.code === 'duplicate_share'` is superseded.
  - **Manual-media fallback deferred (blocked on missing API).** There is no `POST /shares/:id/media` or `POST /shares/:id/manual`; manual entry is `POST /shares { caption, shared_via:'manual' }` (works today via the caption field). Uploading a screen recording to an existing private-post share, and retry-after-account-link for `fetch_auth_required` (not retryable per `ShareController::retry`), need new API endpoints. The private-post message the API already returns is surfaced; the interactive "link account" screen ships with **T-015**'s mobile follow-up.

- **2026-07-21 — DONE / MERGED to main** (squash `271b58c`, PR #117; user said "You can merge"). Mobile CI green (API/Contracts skipped — mobile-only diff); branch deleted. tasks.json T-025→done.
