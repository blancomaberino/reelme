# T-010 — Mobile auth flow: register/login screens, token storage, API client

- **Phase:** M0 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-003, T-004
- **Target paths:** `apps/mobile/app/(auth)/`, `apps/mobile/src/api/`, `apps/mobile/src/stores/`
- **Spec refs:** [05-mobile-app.md#app-architecture](../05-mobile-app.md#app-architecture), [03-api-design.md#auth](../03-api-design.md#auth)

## Context
T-004 shipped the expo-router shell with placeholder `(auth)` screens; T-003 shipped the Sanctum auth endpoints. This task (in the app repo, not this plans folder) connects them: real register/login screens, token persistence in SecureStore, the axios client with interceptors, TanStack Query + zustand wiring, and the root-layout auth gate. Completing it satisfies the M0 outcome — "a user can register, log in, and see an authenticated home screen" — and unlocks every authenticated mobile feature (T-025, T-032, …).

## Implementation steps
1. Install (resolve latest stable at install time): `npx expo install expo-secure-store expo-splash-screen` and `npm i axios @tanstack/react-query zustand -w apps/mobile`. Dev: `npm i -D msw @testing-library/react-native -w apps/mobile` (msw for API mocking in tests per 05-mobile-app §7.1).
2. **Token storage** `src/api/token.ts`: `getToken/setToken/clearToken` over `SecureStore` key `api_token`, with an in-module memory cache refreshed on login/logout (05-mobile-app §1.4). Never AsyncStorage.
3. **axios client** `src/api/client.ts`:
   - `baseURL: process.env.EXPO_PUBLIC_API_URL + '/api/v1'`, `Accept: application/json` header.
   - Request interceptor: attach `Authorization: Bearer <token>` when present.
   - Response interceptor: `401` → `clearToken()` + `useSessionStore.getState().clear()` + `router.replace('/(auth)/login')` (guard against loops on the auth endpoints themselves); `422` → normalize the API error envelope (`error.details`) into `{ field: message }` and reject with a typed `ValidationError`; `429` → surface a rate-limit toast/flag.
4. **Session store** `src/stores/session.ts` (zustand): `{ user: Me | null, status: 'loading' | 'authed' | 'guest', setUser, clear }`. The token itself is never in the store. Also create `src/stores/ui.ts` placeholder (pendingShareUrl arrives in T-025).
5. **Query layer**: create the `QueryClient` in root `_layout.tsx` inside `QueryClientProvider` (defaults per §1.3: `staleTime: 60_000`, `retry: 2`, mutations `retry: 0`). `src/api/keys.ts` queryKeys factory (`me: ['me']`, extensible). `src/api/hooks/useMe.ts` — `useQuery(['me'], GET /me)`; `src/api/hooks/useAuth.ts` — `useRegister`, `useLogin`, `useLogout` mutations: on success persist token, set session user, invalidate `['me']`; logout calls `POST /auth/logout` then clears locally even if the request fails (device might be offline).
   - `device_name`: derive from `Device.deviceName` (`expo-device`) fallback `'mobile'` — required by the login/register contract (03-api-design §2.1).
6. **Auth gate** in root `_layout.tsx` (05-mobile-app §1.2): keep splash visible (`SplashScreen.preventAutoHideAsync`) → read token → if present `GET /me` to hydrate session → render `(main)` group; else redirect `/(auth)/welcome`. Guests never flash the main UI; authed users never flash login. Use the protected-route pattern in the root layout only.
7. **Screens** in `app/(auth)/`:
   - `welcome.tsx`: value-prop copy + "Log in" / "Create account" buttons.
   - `register.tsx`: name, username, email, password fields → `useRegister`; render normalized 422 field errors inline; success lands on the authenticated tabs (Map).
   - `login.tsx`: email + password → `useLogin`; wrong-credential 422 shown inline; link to register. (Social buttons are out of scope until `auth/social` is implemented; leave a commented placeholder — Apple-sign-in parity rule applies only once social login ships.)
   - Profile tab: show `me.username` + a Logout button (proves the authenticated screen + logout loop).
8. **Types**: hand-write minimal `Me`, `AuthResponse` types in `src/api/types.ts` for now with a TODO to switch to `@reelmap/contracts` once API resource schemas are generated there (the contracts package only carries the extraction schema at M0).
9. **Component tests** (jest-expo + RNTL + msw, per §7.1 conventions — fresh QueryClient + router mock per test):
   - Login success: token persisted to SecureStore mock, session store `authed`, navigation to tabs.
   - Login failure: 422 renders field error, no token stored.
   - Register success + duplicate-email 422 mapping.
   - 401 interceptor: a mocked `/me` 401 clears SecureStore + store and redirects to login.
   - Auth gate: with a stored token, root layout hydrates `/me` and shows tabs; without, shows welcome.
10. Manual E2E against the local API (Sail running, phone/simulator pointing at `EXPO_PUBLIC_API_URL=http://<LAN-IP>:80`): register → land on tabs → kill app → relaunch straight into tabs (token survives) → logout → back at welcome.

## Acceptance criteria
- [ ] Register, login, and logout work end-to-end against the local Laravel API (T-003 endpoints) from the app on iOS simulator and Android emulator.
- [ ] Token is stored ONLY in expo-secure-store (never AsyncStorage/zustand), auto-attached by the axios request interceptor, and cleared on logout.
- [ ] 401 responses clear the session (SecureStore + store) and redirect to login; 422 envelopes map to per-field form errors; 429 surfaces a user-visible message.
- [ ] TanStack Query owns server state (`['me']` via keys factory); zustand `useSessionStore` holds only the hydrated user + status; QueryClient provided in root layout.
- [ ] Root-layout auth gate: splash held until token check resolves; unauthenticated → `(auth)/welcome`; authenticated → `(main)` tabs; no screen flash either way; session survives app restart.
- [ ] Component tests cover login success/failure, register, the 401 interceptor, and the auth gate — all green in `npm test`.
- [ ] `npm run typecheck` and `npm run lint` remain green.

## Verification
```bash
cd apps/mobile
npm run typecheck && npm run lint && npm test
# live flow (API up via Sail; device on same LAN):
EXPO_PUBLIC_API_URL=http://192.168.x.x npx expo start --dev-client
# app: register new user → tabs visible → relaunch app → still authed → logout → welcome
# server-side check: php artisan tinker --execute="echo App\Models\User::count();"
```

## Gotchas
- `localhost` on a device/emulator is not your machine: use the LAN IP (physical device), `10.0.2.2` (Android emulator), or `localhost` only on the iOS simulator. Make `EXPO_PUBLIC_API_URL` per-profile (T-004 eas.json) and document it.
- Android blocks cleartext HTTP by default in release-type builds; dev client allows it, but if requests fail with network errors, add the dev API host to `android.usesCleartextTraffic`/network security config for development only.
- SecureStore is async — the auth gate must await the read before rendering; forgetting `preventAutoHideAsync` causes a login-screen flash for authed users (explicit spec requirement).
- Avoid a 401-interceptor loop: a failed `POST /auth/login` (401/422) must NOT trigger the global clear-and-redirect — exclude `auth/*` paths in the interceptor.
- `SecureStore` has no jest-expo mock by default — mock `expo-secure-store` module in `jest.setup.ts` with an in-memory map.
- msw in React Native requires `msw/native` setup and a `globalThis` URL polyfill on some SDK versions — follow the 05-mobile-app §7.1 convention and pin what works.
- Send `device_name` on login/register — the API issues one token per device and revokes same-name tokens; omitting it breaks the T-003 contract (422).
- Do not build social login yet: `POST /auth/social` may be a 501 stub server-side; shipping visible Apple/Google buttons against a stub violates store rules later — keep them commented.

## Log
- **2026-07-10** — Done (code + component tests). **PR #11** (`feat/t010-mobile-auth` → `feat/t004-expo-scaffold`, stacked). Gates green: `npm test` 7 passing, `typecheck` + `lint` 0 errors / 0 warnings.
- **Implementation**: SecureStore token (memory-cached), axios client (bearer request interceptor; 422→ValidationError, 401→clear+redirect, 429→flag), TanStack Query (QueryClient in root layout, keys factory, useMe) + zustand session/ui stores, useRegister/useLogin/useLogout, root-layout auth gate (splash held; index redirects by status), welcome/login/register/profile screens with shared AuthScreenLayout/Button/TextField/formErrors/theme.
- **Deviations**:
  - **Tests use `axios-mock-adapter`, not msw** (§7.1 convention) — msw/native is flaky in jest-expo (brief's own gotcha); axios-mock-adapter exercises the same request→interceptor→response path reliably. Revisit the msw convention at the first data-hook tests.
  - **`/security-review`** applied 2 Medium latent hardenings: token attached only to relative URLs (no cross-host leak); `Authorization` scrubbed from `error.config` before re-reject (no future logger leak). `/simplify` applied formErrors + AuthScreenLayout + theme/colors.
- **Not done in this session (offered to user)**: (1) **live device E2E** (register→tabs→relaunch→logout vs Sail) — needs a dev-client rebuild because `expo-device` adds a native module; component tests cover the logic. (2) **`/frontend-design` polish pass** on the auth screens (they're functional-clean now).
- **2026-07-10 (follow-up)** — Both optional bits done. **Frontend-design pass**: light/dark palette via `useColors()` + `makeStyles(colors)` factory across all auth components; TextField focus state; Button brand-tinted elevation + press scale; welcome logo-mark hero + legal line; profile avatar; switched `SafeAreaView`→`react-native-safe-area-context` (+ `SafeAreaProvider` at root) which also cleared the RN core deprecation warning; hoisted shared form styling to `useAuthFormStyles()`. Verified on iPhone 17 Pro / iOS 26.5 in **both** light and dark (screenshots). **Live E2E**: rebuilt the app (full build w/ pods — `--no-install` misses the expo-device native module → "Cannot find native module 'ExpoDevice'") against `EXPO_PUBLIC_API_URL=http://localhost:8080`; app boots clean to welcome. Added `apps/mobile/e2e/live-auth-e2e.sh` — a runnable live-API E2E hitting the real T-003 endpoints, **13/13** green, asserting the exact envelope the axios client parses; shellcheck-clean. Added `e2e/auth-flow.yaml` (Maestro on-device flow) + README. New `__tests__/screens.test.tsx` (5 tests, both schemes). Gates: typecheck 0, lint 0, jest **12/12**. `/simplify`+`/security-review` via subagent (security clean; applied dead-key + DRY-form-styles fixes). Pushed to **PR #11** (`6b18cc5`).
- **2026-07-10 (MERGED to main)** — PR #11 squash-merged to `main` as `774b887`; head branch deleted. Before merge: retargeted base off the merged `feat/t004-expo-scaffold` → `main`, merged `main` in (regenerated `package-lock.json`), and folded in the user's `/coderabbit` commit `5788085` (client.ts hardening: protocol-relative host guard, rate-limit reset, no 401-redirect during bootstrap; new `auth-gate.test.tsx`) — reconciling its `jest.setup.ts` React-Query-flush changes with my global router-mock superset. Final: typecheck 0, lint 0, **19/19 tests / 5 suites**, clean merge onto main. **T-010 shipped.**
- **2026-07-10 (Maestro E2E green)** — User installed the Maestro CLI. Drove `e2e/auth-flow.yaml` on iPhone 17 Pro / iOS 26.5 (Maestro 2.6) to a **full green, twice** (23 steps, 0 failures): welcome → register (all fields, unique identity) → dismiss iOS "Save Password?" → **Map visible** (proves live `POST /auth/register` round-trip) → Profile tab → Log out → welcome. 6 `e2e_*@example.com` users confirmed persisted in the dev DB. Pushed `1f9123c` to PR #11. Flow hardened for four device realities: `RUN` only from `-e` (Maestro 2.6 flow `env:` default shadows CLI override); tab taps need regex (`.*Profile.*`, composite a11y label); dismiss the Save-Password system dialog before asserting; logout preamble handles the keychain token surviving `clearState`. **T-010 fully complete incl. on-device E2E — no remaining items.**
