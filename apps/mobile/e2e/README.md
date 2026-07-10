# Mobile E2E

Two layers of end-to-end coverage for the T-010 auth flow.

## 1. Live API contract E2E (`live-auth-e2e.sh`) — runnable now

Drives the real T-003 auth endpoints against a running Laravel API and asserts the
exact response envelope the mobile axios client parses (`data.token`,
`data.user`, the `error.details` 422 shape → per-field form errors, 401 on a
revoked token → client clear+redirect). This is the automated proof that the
client's request shape (including `device_name`) and response parsing match the
server.

```bash
# API up via Sail on :8080 (see apps/api)
apps/mobile/e2e/live-auth-e2e.sh
# against a LAN host / staging:
API_URL=http://192.168.1.10:8080 apps/mobile/e2e/live-auth-e2e.sh
```

Covers: register→token, `GET /me` with bearer, unauth `/me`→401, login,
wrong-password→422+`error.details`, logout revocation, duplicate-email→422.

> Note: the auth endpoints are rate-limited; back-to-back runs may 429. Wait ~60s
> between runs.

## 2. On-device UI flow (`auth-flow.yaml`) — Maestro

A [Maestro](https://maestro.mobile.dev) flow that drives the **actual app UI** on
an iOS simulator through welcome → register → authenticated tabs → Profile →
logout → welcome against the live API. **Verified green** on iPhone 17 Pro /
iOS 26.5 (Maestro 2.6). The `assertVisible: "Map"` after submit only passes once
the live `POST /auth/register` succeeds and the session is authed, so a green run
proves the full on-device round-trip.

Install the CLI (`brew install maestro` gives the desktop app, not the runner —
use the official script) then:

```bash
# 1. Build+launch the app on a booted simulator pointed at the live API:
cd apps/mobile
EXPO_PUBLIC_API_URL=http://localhost:8080 npx expo run:ios --device "iPhone 17 Pro"
#   FULL build — do NOT pass --no-install, or the expo-device native module isn't
#   linked and the auth screens crash: "Cannot find native module 'ExpoDevice'".
#   (Alternatively keep `npx expo start` running so the installed build reloads.)

# 2. In another shell, run the flow with a unique per-run identity:
maestro --device <sim-udid> test e2e/auth-flow.yaml -e RUN=$(date +%s)
```

Notes baked into the flow (each cost a debugging round on first authoring):
- **`RUN` must come from `-e`** — a flow-level `env:` default shadows the CLI
  override in Maestro 2.6, so every run would reuse one identity and hit
  "already taken". No default is set on purpose.
- **Tab labels need regex** (`.*Profile.*`) — Maestro does a full-string match and
  the tab button's a11y label is composite (`"Profile, tab, 4 of 4"`).
- **The iOS "Save Password?" dialog** is dismissed (`Not Now`, optional) right
  after submit; it covers the screen and would fail the `Map` assertion.
- **A logout preamble** runs first — expo-secure-store's token lives in the
  keychain, which survives `clearState`, so a stale session is signed out to
  guarantee a clean welcome start. The trailing logout keeps re-runs clean.

Each run registers a fresh throwaway `e2e_<ts>@example.com` user in the dev DB.
