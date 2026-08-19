# T-146 — Two OS permission dialogs that appear when the user tapped nothing

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-027 (devices + push notifications), T-100 (map: user
  location, permissions, persisted viewport)
- **Target paths:** `apps/mobile/src/notifications/push.ts`,
  `apps/mobile/src/notifications/use-push-notifications.ts`,
  `apps/mobile/app/offers/index.tsx`, `apps/mobile/src/lib/network.ts`,
  `apps/mobile/src/i18n/`

Mobile review 2026-08-19, findings **MOB-7** and **MOB-14**. One task: two
different subsystems, one behaviour — an OS permission dialog appearing because
something re-fired on resume or cold start, in both cases under a comment or an
intent that says otherwise.

## Context

### MOB-7 — the push soft-prompt re-asks forever, in hardcoded Spanish

The push effect (`use-push-notifications.ts:96-100`) is keyed on `[status]` and
calls `registerForPush()` on every `loading -> authed` transition — i.e. every
cold start. `registerForPush` (`push.ts:70-79`) shows `defaultConfirm()`'s Alert
whenever permission is not granted and `canAskAgain` is true.

Declining the **soft** prompt persists nothing and leaves the OS permission
untouched, so `canAskAgain` stays true **forever**. A user who taps "Ahora no"
is shown the same modal on every single cold start, indefinitely.

That Alert's copy is also two hardcoded Spanish strings — per the reviewer, the
**only** ones in the app that bypass `t()` — so an English-locale user is shown
it in Spanish.

### MOB-14 — the offers screen re-requests a location fix on resume

The device-fix query (`offers/index.tsx:63-68`) inherits
`refetchOnWindowFocus: true` (nothing overrides it in `query-client.ts`) and
`focusManager` is wired to AppState (`network.ts:55-60`), so `locateUser`
re-runs on app resume past its 5-minute `staleTime` — re-calling
`requestForegroundPermissionsAsync()` and a fresh 5s `watchPositionAsync`.

On Android, where `canAskAgain` stays true after a denial, **the OS location
dialog appears on app resume with the user having tapped nothing.**

The comment at `:57-62` asserts the opposite: *"a fix is not re-requested every
time this screen is revisited."*

## Implementation

Both are one-line-ish fixes with a shared discipline: **a permission prompt is a
user-initiated event, and anything that can re-fire it on a lifecycle transition
needs a persisted answer.** Filing them together makes that the reviewable
claim rather than two unrelated small diffs.

- Persist the push decline (next to the locale) and skip the confirm path when
  set; route the copy through `t()`.
- `refetchOnWindowFocus: false` on the device-fix query.

## Acceptance criteria

- [ ] Declining the push soft-prompt is persisted and the prompt does not
      reappear on the next cold start — asserted across a simulated relaunch,
      not within one session
- [ ] The soft-prompt copy comes from `t()` in both dictionaries; the two
      hardcoded Spanish strings are gone, asserted by the same check that
      catches i18n drift
- [ ] The offers screen does not re-request a location fix on app resume —
      asserted by firing a focus event and observing no refetch
- [ ] The comment at `offers/index.tsx:57-62` is true of the code, or corrected
- [ ] A user who declined can still turn either back on

## Gotchas

- **A persisted decline must not become a dead end.** Store it where the locale
  lives, and make sure Settings can still enable notifications — otherwise this
  trades a nagging modal for an unreachable feature.
- Do not "fix" MOB-7 by removing the soft prompt. A soft prompt before the OS
  one is the right pattern; the bug is that it forgets.
- `refetchOnWindowFocus` is global in `query-client.ts`. Override it on **this
  query**, not globally — other screens legitimately want fresh data on resume.
