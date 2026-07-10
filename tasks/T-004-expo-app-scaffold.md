# T-004 — Expo app scaffold (latest SDK, expo-router, TypeScript)

- **Phase:** M0 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-001
- **Target paths:** `apps/mobile/`
- **Spec refs:** [05-mobile-app.md#app-architecture](../05-mobile-app.md#app-architecture)

## Context
T-001 created the monorepo (separate app repo — not this plans folder) with an empty `apps/mobile/` placeholder. This task scaffolds the Expo React Native app: latest SDK, managed workflow, expo-router navigation shell with the four M0 tabs, strict TypeScript, lint/test tooling, and EAS build profiles. It unlocks T-010 (auth flow) and the mobile CI job (T-006). Stack choices are fixed by 05-mobile-app §0 — do not substitute.

## Implementation steps
1. Scaffold with the latest stable SDK (resolve at install time):
   ```bash
   cd apps && npx create-expo-app@latest mobile-tmp --template default \
     && rsync -a mobile-tmp/ mobile/ && rm -rf mobile-tmp
   ```
   The default template already includes expo-router + TypeScript. Set package name `@reelmap/mobile` and verify the root workspace picks it up (`npm ls --workspaces` from repo root).
2. Replace template routes with the project tree from 05-mobile-app §1.2 (M0 subset):
   - `app/_layout.tsx` — root layout: providers placeholder (theme; QueryClient arrives in T-010), renders `<Stack>` with `(auth)` and `(main)` groups. Add a TODO for the auth gate + share-intent staging (T-010/T-025).
   - `app/(auth)/_layout.tsx` (headerless Stack), `app/(auth)/welcome.tsx`, `login.tsx`, `register.tsx` — static placeholder screens (real forms in T-010).
   - `app/(main)/_layout.tsx` — `<Tabs>` with four tabs: `map/index.tsx` (default), `feed/index.tsx`, `share/index.tsx` ("+" center tab), `profile/index.tsx`. Each a placeholder screen with the tab name. (Wallet tab is M4 — omit.)
   - `app/settings/index.tsx` — settings hub shell (M0 per screen inventory #14).
3. `src/` skeleton per §1.1: `src/api/`, `src/components/`, `src/features/`, `src/stores/`, `src/lib/`, `src/notifications/` — each with an `index.ts` or `.gitkeep`. Configure path alias `@/*` → `src/*` in `tsconfig.json` + `babel.config.js` if not already present.
4. TypeScript strict: `tsconfig.json` `"strict": true` (extend `expo/tsconfig.base`). Add npm script `"typecheck": "tsc --noEmit"`.
5. ESLint: `npx expo lint` to install the `eslint-config-expo` flat config; script `"lint": "expo lint"`. Fix all findings.
6. Jest: install `jest-expo jest @testing-library/react-native @types/jest` (latest stable at install time). `jest.config.js` with `preset: 'jest-expo'`, `transformIgnorePatterns` per Expo docs. Script `"test": "jest"`. Add one smoke test rendering the welcome screen (`app/(auth)/welcome.tsx`) via RNTL asserting its title text.
7. `app.config.ts` (delete `app.json`, move config) per 05-mobile-app §1.5: `name` "Reelmap (Dev)"/"Reelmap" by `IS_DEV` (from `process.env.APP_VARIANT`), `slug: 'reelmap'`, `scheme: 'reelmap'`, iOS `bundleIdentifier` `pet.one.reelmap.dev`/`pet.one.reelmap`, Android `package` likewise, `extra.apiUrl: process.env.EXPO_PUBLIC_API_URL`, plugins: `expo-router`, `expo-secure-store` (install the package now — T-010 needs it). Do NOT add `expo-share-intent` or `react-native-maps` yet (T-025/T-032).
8. EAS setup: `npm i -g eas-cli` (or npx), `eas init` (creates the EAS project; record `projectId` in `app.config.ts` `extra.eas.projectId`). Create `eas.json` exactly per 05-mobile-app §6.2: `development` (developmentClient true, internal, `EXPO_PUBLIC_API_URL` = LAN IP of dev API, ios.simulator true), `preview` (internal, staging URL, channel `preview`), `production` (autoIncrement, prod URL, channel `production`).
9. Build and run a development client on both platforms: `eas build --profile development --platform ios` (simulator build) and `--platform android`; install; run `npx expo start --dev-client` and confirm the tab shell renders on the iOS simulator and Android emulator. (If EAS credentials/accounts block agent execution, document the exact commands + expected result in `apps/mobile/README.md` and verify locally via `npx expo prebuild --no-install` succeeding.)
10. `apps/mobile/README.md`: dev-client-not-Expo-Go warning (§6.1), start commands, env var table (`EXPO_PUBLIC_API_URL`), when a dev-client rebuild is required.

## Acceptance criteria
- [ ] Latest stable Expo SDK pinned in `package.json`; managed workflow (no committed `ios/`/`android/` dirs).
- [ ] expo-router tree matches 05-mobile-app §1.2 M0 subset: `(auth)` group (welcome/login/register) + `(main)` Tabs with Map, Feed, Share (+), Profile; Map is the initial tab.
- [ ] `tsconfig.json` has `"strict": true` and `npm run typecheck` passes.
- [ ] `npm run lint` (eslint-config-expo) passes with zero errors.
- [ ] Jest configured with `jest-expo`; `npm run test` green including at least one RNTL screen render test.
- [ ] App boots on iOS simulator AND Android emulator via a development client (or, if builds can't run in this environment, `expo prebuild --no-install` passes and README documents the build steps).
- [ ] EAS project initialized (`projectId` in config) and `eas.json` defines `development`, `preview`, `production` profiles with per-profile `EXPO_PUBLIC_API_URL`.

## Verification
```bash
cd apps/mobile
npm install            # from repo root: npm install (workspaces)
npm run typecheck && npm run lint && npm run test
npx expo prebuild --no-install   # config-plugin sanity, must exit 0 (then delete ios/ android/)
npx expo start --dev-client      # with a dev client installed: tabs Map/Feed/Share/Profile render
```

## Gotchas
- **Expo Go will not work later** (share-intent, maps are custom native modules); establish the dev-client habit now — README must say it in bold.
- npm workspaces hoist `node_modules` to the repo root; Metro handles this, but if module resolution breaks, add `metro.config.js` with `watchFolders: [workspaceRoot]` and `nodeModulesPaths` per Expo monorepo docs.
- `app.json` and `app.config.ts` must not coexist (config.ts wins but confuses tooling) — delete `app.json`.
- `jest-expo` needs `transformIgnorePatterns` covering `expo|@expo|react-native|@react-native|expo-router` or tests fail with "Cannot use import statement outside a module".
- Separate dev/prod bundle IDs (`.dev` suffix) let both installs coexist — set this now; changing bundle IDs after the first EAS build creates credential churn.
- `EXPO_PUBLIC_*` env vars are inlined at build time; a physical device cannot reach `localhost` — the development profile must use the machine's LAN IP.
- Run `npx expo prebuild` only as a check; never commit the generated `ios/`/`android/` folders (gitignored in T-001).

## Log
- **2026-07-10** — Done (code complete). **PR #9** (`feat/t004-expo-scaffold` → `main`, independent of the backend stack). **Expo SDK 57**, React 19.2, RN 0.86. Gates green: `typecheck` (tsc strict), `lint` (eslint-config-expo), `test` (jest-expo + RNTL welcome-screen render). `expo prebuild --no-install` passes (config plugins valid) — the brief's documented fallback.
- **Structure**: routes at root `app/` (not SDK 57's default `src/app`) to match the spec + downstream task paths; `(auth)` + `(main)` tabs (Map default / Feed / Share+ / Profile) + settings; shared `PlaceholderScreen`. `src/` skeleton with `@/*` alias. `app.config.ts` (deleted `app.json`), `eas.json` 3 profiles.
- **Toolchain notes / deviations**:
  - **jest**: `jest-expo@57` needs **jest 29** (not 30) + the separate `@react-native/jest-preset@0.86`; `react-test-renderer` pinned to `19.2.3` to match React; installed with `--legacy-peer-deps`.
  - Workspace install uses `npm install -w @reelmap/mobile` because `packages/contracts` has no package.json on `main` (T-005 adds it) — a bare root `npm install` chokes until T-005 merges.
  - **/simplify** applied (removed template residue); **/security-review** clean (no secrets/typosquats).
- **iOS simulator boot — VERIFIED WORKING** (2026-07-10): after the user installed the iOS 26.5 platform, `expo run:ios` **Build Succeeded** and the app booted on **iPhone 17 Pro (iOS 26.5)**; the tab shell (Map default / Feed / Share+ / Profile) renders. Required a route-flatten fix: `app/(main)/map/index.tsx`→`map.tsx` etc., because a directory route registers as `map/index`, so `Tabs initialRouteName="map"` threw "Couldn't find a screen named map". Single-file routes register as `map`. (Earlier blocker was Xcode 26.6 building against the not-yet-installed iOS 26.5 platform.)
- **EAS project configured** (2026-07-10): `eas init` created **`@mindastic/reelmap`** (projectId `4d05e4d7-cfac-45d0-afbd-22ae34f69e32`, owner `mindastic`). `eas-cli 20.5` couldn't write the projectId into the TS config (its config writer fails under **TypeScript 6.0** — reads fine, so `eas project:info` resolves), so `extra.eas.projectId` + `updates.url` were set manually. All acceptance criteria now met.
- **Android**: SDK present but `emulator`/`adb` not on PATH (iOS-first).
- **Android**: SDK present but `emulator`/`adb` not on PATH; iOS-first per earlier note.
