# T-142 — No auth guard on the `(main)` route group

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-010 (mobile auth flow: screens, token storage, API client)
- **Target paths:** `apps/mobile/app/(main)/_layout.tsx`,
  `apps/mobile/app/(main)/profile.tsx`, `apps/mobile/src/api/client.ts`,
  `apps/mobile/app/(main)/__tests__/`

Mobile review 2026-08-19, finding **MOB-4**.

## Context

Only `app/index.tsx` checks the session, and it guards the `/` entry alone. The
`(main)` group has **no redirect at all** — verified: `app/(main)/_layout.tsx`
contains no `Redirect` and no status check.

In `profile.tsx`, `authed` gates **only** the notification bell (`:55`). "Mis
compartidos", "Editar perfil", "Mis listas", "Invitar amigos", "Ajustes" and Log
out all render unconditionally off `user?…`.

**Failure:** a guest deep-linked to `reelmap://profile` — and per T-137, **any
installed app can send that** — gets a convincing signed-in profile with a blank
name, and every row then 401s. It is worse during bootstrap:
`client.ts:74-79` intentionally skips the redirect while `status === 'loading'`,
so the screen just sits there broken.

### The second deep-link reachability finding of this wave

T-137 is the first. Both are a route that assumes it was arrived at
legitimately. Worth reading T-137 first, even though neither depends on the
other.

## Implementation

The one-line version is

```tsx
if (status === 'guest') return <Redirect href="/(auth)/welcome" />;
```

in the `(main)` layout. **The task is bigger than the line:**

- **The loading window has to be decided.** A guard that redirects during
  bootstrap logs the user out on every cold start; one that renders through it
  is the bug. `client.ts:74-79` already made a deliberate choice here — the
  layout has to agree with it, not contradict it.
- **`profile.tsx`'s row-level gating has to be reconciled with it**, so the two
  cannot disagree later.
- **`app/index.tsx`'s existing check must not survive as a second, separate
  rule.** That is this wave's root cause, one layer up.

## Acceptance criteria

- [ ] A guest opening any `(main)` route — including by deep link — lands on the
      auth flow, asserted per route rather than only for `/`
- [ ] The bootstrap window is handled explicitly: while `status === 'loading'`
      the screen shows a loading state, never a broken authed one
- [ ] `profile.tsx` renders no authed-only row for a guest — all six gated,
      asserted by name
- [ ] The guard lives in ONE place; `app/index.tsx` is reconciled with it

## Gotchas

- **CLAUDE.md reachability applies:** the test must *arrive* at the route as a
  guest. `render(<Profile/>)` bypasses the layout entirely and proves nothing
  about a guard that lives in the layout.
- A redirect loop is the obvious failure mode — `(auth)` must not bounce back
  into `(main)` while `status` is still settling.
- Some `(main)` routes are legitimately public (the map, search). Decide which,
  explicitly, rather than guarding the group and then poking holes in it later.
