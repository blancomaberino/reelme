# T-121 — A session token that never expires, kept where an iCloud backup can carry it to another phone

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-003 (Sanctum auth API), T-010 (mobile auth flow + token storage)
- **Target paths:** `apps/api/config/sanctum.php`,
  `apps/api/app/Http/Controllers/Api/V1/Auth/`, `apps/api/routes/console.php`,
  `apps/api/routes/api.php`, `apps/mobile/src/api/token.ts`

Security review 2026-08-19, findings **SEC-5 (MEDIUM, CWE-613)** and
**SEC-11 (LOW/MEDIUM, CWE-522)**.

## Context

**SEC-5.** `config/sanctum.php:53` sets `'expiration' => null`, and all five mint
sites call `createToken($name)` with no abilities and no expiry:
`LoginController.php:73`, `RegisterController.php:56`,
`EmailVerificationController.php:42`, `TwoFactorChallengeController.php:74`,
`RefreshController.php:21`. A token recovered once — an unencrypted backup, a
proxy log, a phone never wiped — authenticates **forever**, and `/auth/refresh`
lets the holder mint fresh ones indefinitely. There is no active-sessions
endpoint, so the owner cannot see or revoke a session they do not know about.
`['*']` means the same token reaches `POST /wallet/payouts` and `DELETE /me`.

**SEC-11.** `apps/mobile/src/api/token.ts:21` calls
`SecureStore.setItemAsync(KEY, token)` with no options. `expo-secure-store`
defaults to `WHEN_UNLOCKED`, **not** `..._THIS_DEVICE_ONLY`, so the item rides
along in encrypted iCloud/iTunes backups and restores onto a different device.

### Why one task

Separately, each is a hardening chore. Together they are *"restore a backup, boot
straight into the victim's session, forever."* SEC-11's severity is entirely a
function of SEC-5, and fixing one without the other leaves the compound attack
intact — which is precisely the failure mode this audit wave is about.

### Already true, and not to be broken

Password reset revokes all tokens, logout revokes the current one, and a ban
revokes all (`UsersTable.php:86`). Those are the existing mitigations and the
regression risk of this change.

## Implementation

- **Bounded, env-backed `expiration`** plus a scheduled `sanctum:prune-expired`.
- **Ability scoping** is the real work and the reason this is M rather than S.
  Mint session tokens with a scope that does **not** reach payouts or account
  deletion, and enforce it per route so a later route inherits the decision
  consciously rather than by default.
- **An absolute lifetime on the refresh chain**, so `/auth/refresh` cannot
  extend a session indefinitely.
- **Mobile:** `{ keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY }`.
- **Active sessions:** decide, and record the decision. Even if the endpoint is
  deferred, "the owner cannot revoke a session they cannot see" should be a
  written trade-off rather than an omission.

## Acceptance criteria

- [ ] `sanctum.expiration` is bounded and env-backed; `sanctum:prune-expired` is
      scheduled; a token past the window is rejected — proven by travelling past
      it, not by reading config
- [ ] A plain session token is REFUSED by `POST /wallet/payouts` and
      `DELETE /me`, asserted per route
- [ ] `/auth/refresh` cannot extend a session past an absolute lifetime; the
      refresh chain is asserted to end
- [ ] The mobile token is written with `WHEN_UNLOCKED_THIS_DEVICE_ONLY`,
      asserted on the call
- [ ] Password reset / logout / ban revocations still work — asserted as
      regressions

## Gotchas

- **Expiry logs people out.** Agree the window with the owner, and make sure the
  client re-authenticates or refreshes cleanly *before* the window is shortened,
  or the first symptom will be silent 401s on the product's primary entry point.
- Changing the keychain accessibility class does **not** migrate an item already
  written under the old class. Decide what happens to existing installs — most
  likely a re-login, which is acceptable but must be deliberate.
- Ability scoping touches every mint site. A token minted in one place without
  the scope is a hole that no per-route test will notice unless the test drives
  *that* login path.
