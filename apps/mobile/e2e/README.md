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

## 2. On-device UI flow (`auth-flow.yaml`) — requires Maestro

A [Maestro](https://maestro.mobile.dev) flow that drives the actual app UI
through welcome → register → authenticated tabs → logout → welcome against the
live API. Not run in CI yet because Maestro is not installed in this environment
(`curl | bash` installer is blocked; install it deliberately with
`brew install maestro` or the official script).

```bash
# 1. Build+launch the app on a simulator pointed at the live API:
cd apps/mobile
EXPO_PUBLIC_API_URL=http://localhost:8080 npx expo run:ios --device "iPhone 17 Pro"
#   (a FULL build — do NOT pass --no-install, or the expo-device native module
#    won't be linked and the auth screens crash with "Cannot find native module
#    'ExpoDevice'".)

# 2. In another shell, run the flow:
maestro test apps/mobile/e2e/auth-flow.yaml
```

Selectors target the visible copy and the inputs' `accessibilityLabel`s (set in
`src/components/text-field.tsx`). Validate/adjust them on the first Maestro run.
