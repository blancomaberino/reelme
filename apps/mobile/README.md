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
| `EXPO_PUBLIC_API_URL` | Base URL of the Laravel API (the client appends `/api/v1`). **A device/simulator cannot reach `localhost`** — use the machine's LAN IP for the `development` profile (e.g. `http://192.168.1.16:8080`). |
| `APP_VARIANT` | `development` selects the dev app name + `.dev` bundle IDs (side-by-side install). |
| `EAS_PROJECT_ID` | Injected into `extra.eas.projectId`; set once `eas init` has run (see below). |

## EAS

`eas.json` defines three profiles per `05-mobile-app §6.2`:
- **development** — `developmentClient`, internal, iOS simulator, `EXPO_PUBLIC_API_URL` = LAN IP of the dev API.
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
