# apps/mobile — Reelmap

Expo React Native app (SDK 57, TypeScript, expo-router). Scaffolded in **T-004**.

> ## ⚠️ You must use a **custom dev client**, not Expo Go
> Reelmap depends on custom native modules (share intent + maps, added in T-025/T-032).
> **Expo Go will not run this app.** Build and install a development client (below).

## Structure

- `app/` — expo-router routes. `(auth)` (welcome/login/register) + `(main)` bottom tabs (**Map** [default], Feed, Share `+`, Profile) + `settings/`. Every route is also a `reelmap://` deep link.
- `src/` — non-route code: `api/`, `components/`, `features/`, `stores/`, `lib/`, `notifications/`. Import via the `@/*` alias.
- `app.config.ts` — dynamic config (dev/prod variants via `APP_VARIANT`). There is no `app.json`.
- `eas.json` — build profiles (§ below).

## Develop

```bash
# from the repo root (workspaces): install deps
npm install -w @reelmap/mobile --legacy-peer-deps

cd apps/mobile
npm run typecheck   # tsc --noEmit (strict)
npm run lint        # eslint-config-expo
npm run test        # jest-expo + @testing-library/react-native

# Run a dev client on the iOS simulator (builds locally via Xcode — no EAS needed):
npm run ios         # expo run:ios
# then, for subsequent JS-only changes, just start the bundler:
npm start           # expo start --dev-client
```

**When must you rebuild the dev client?** Only on native changes: adding/removing a
library with native code, editing plugins in `app.config.ts`, or upgrading the SDK.
Pure TS/JS/screen edits never need a rebuild — `npm start` picks them up.

> The managed workflow regenerates `ios/`/`android/` at build time; they are
> **gitignored** — never commit them. `npx expo prebuild --no-install` is a config sanity check only.

## Environment

`EXPO_PUBLIC_*` vars are inlined at build time. Per-profile values live in `eas.json`.

| Variable | Purpose |
|----------|---------|
| `EXPO_PUBLIC_API_URL` | Base URL of the Laravel API (the client appends `/api/v1`). The `development` profile defaults to `http://localhost:8080` (works for the iOS simulator, which shares the host network). **A physical device or Android emulator cannot reach `localhost`** — override with the dev machine's LAN IP (e.g. `http://192.168.1.16:8080`; Android emulator uses `http://10.0.2.2:8080`) in your local `eas.json` or via `EXPO_PUBLIC_API_URL` when building. |
| `APP_VARIANT` | `development` selects the dev app name + `.dev` bundle IDs (side-by-side install). |
| `EAS_PROJECT_ID` | Injected into `extra.eas.projectId`; set once `eas init` has run (see below). |

## EAS

`eas.json` defines three profiles per `05-mobile-app §6.2`:
- **development** — `developmentClient`, internal, iOS simulator, `EXPO_PUBLIC_API_URL` = `http://localhost:8080` (override with a LAN IP for physical devices — see Environment above).
- **preview** — internal QA (TestFlight / APK), channel `preview`, staging URL.
- **production** — `autoIncrement`, channel `production`, prod URL.

**One-time setup (needs an Expo account — interactive):**

```bash
npx eas-cli login
npx eas-cli init          # creates the EAS project, writes projectId (set EAS_PROJECT_ID or paste into app.config.ts)
# cloud builds:
npx eas-cli build --profile development --platform ios      # simulator/device dev client
npx eas-cli build --profile development --platform android
```

> The share sheet **cannot be tested on the iOS simulator** — use a physical
> device dev build + the real Instagram app (T-025).

## Share-intent ingest flow (T-025)

Sharing a link/text into Reelmap from another app (Instagram, Safari…) is the
core add-a-place gesture:

- **Registration** — the `expo-share-intent` plugin in `app.config.ts` declares
  the iOS activation rules (WebURL + Text, **no media rules** so Reelmap never
  shows up in photo share sheets) and the Android `text/*` intent filter.
  Changing these rules requires a **native rebuild** (and sometimes a device
  reinstall for iOS to refresh the share-sheet cache).
- **Capture** — `app/_layout.tsx`'s `ShareIntentRedirect` reads the payload
  (extracting the first URL out of raw text via `extractUrl`), stages it in
  `useUiStore.pendingShare` **before** any auth redirect, then routes to the
  ingest screen (`app/(main)/share.tsx`). Cold start is handled by
  `app/+native-intent.tsx`.
- **Auth gate** — a share started while logged out is not lost: the guest lands
  on sign-in with a banner, and the staged share resumes on the ingest screen
  after login.
- **Deep-link/CI entry** — the ingest screen also reads `sharedUrl`/`sharedText`
  route params, so Maestro/CI can drive it via `reelmap://…` without the OS
  share sheet.

Duplicates are handled server-side as an **idempotent replay** (not a 409):
re-sharing a post returns the existing share, surfaced as a friendly "already
added" note. Uploading a screen recording to a private-post share is **not yet
wired** — it needs API endpoints that don't exist yet (see ADR-087).
