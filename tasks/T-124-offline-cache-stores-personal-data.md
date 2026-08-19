# T-124 — The offline cache keeps email, birthdate and the block list in clear text on the device

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-103 (offline resilience: NetInfo + query-cache persistence)
- **Target paths:** `apps/mobile/src/lib/query-persist.ts`,
  `apps/mobile/src/lib/__tests__/`

Security review 2026-08-19, finding **SEC-7 (MEDIUM, CWE-312)**.

## Context

`query-persist.ts:98`:

```ts
if (head === 'me') return second !== 'quotas';
```

That predicate admits **every** `['me', ...]` key to the on-disk cache:

- the full `Me` object — email, birthdate, derived age, country;
- `['me','blocks']` — who the user has blocked, a sensitive social signal in its
  own right;
- saved-place coordinates.

`AsyncStorage` is a plain file on iOS and an unencrypted SQLite DB on Android, so
an unencrypted Finder/ADB backup or a rooted device yields all of it.

### What is already right

The bearer token is correctly **excluded**, and the cache is wiped on sign-out
and on 401. This is a scoping mistake in one predicate, not a broken design.

## Implementation

Two honest options.

- **Exclude `['me']` and `['me','blocks']`.** A couple of lines. Costs a
  cold-start round trip on those two queries — probably invisible, since neither
  is what the user is looking at when the app opens. T-103's brief was the map
  and places, not the profile.
- **Encrypt under a SecureStore-held key.** Keeps the offline behaviour, adds a
  key lifecycle: rotation, a wiped key leaving an unreadable blob, and a boot
  path that must survive that blob without crashing.

Prefer the cheap one unless the offline requirement genuinely needs those two
keys.

## Acceptance criteria

- [ ] `['me']` and `['me','blocks']` no longer reach AsyncStorage in clear text
      — asserted by **reading back what the persister wrote**, not by asserting
      on the allowlist predicate
- [ ] T-103's offline behaviour still holds for the keys that remain (map, my
      places), asserted by T-103's own offline test
- [ ] Sign-out and 401 still wipe the cache
- [ ] If encryption is chosen: the key lives in SecureStore, and a wiped key
      makes the cache unreadable rather than crashing the app on boot

## Gotchas

- **Assert on the bytes, not the predicate.** The predicate is the thing that was
  wrong; a test that reads it will agree with it.
- Check for other `['me', ...]` consumers before excluding — a screen that
  currently renders instantly from cache will start with a spinner, and that is a
  visible change worth knowing about rather than discovering.
