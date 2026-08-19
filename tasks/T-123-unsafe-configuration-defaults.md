# T-123 — Four configuration defaults that fail silently, and leak when they do

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** nothing (deliberately — see "Coordination" below)
- **Target paths:** `apps/api/app/Exceptions/ApiExceptionRenderer.php`,
  `apps/api/.env.example`, `apps/api/compose.yaml`, `apps/api/config/session.php`,
  `apps/api/config/database.php`, `apps/api/docs/runbooks/provisioning.md`

Security review 2026-08-19, findings **SEC-6, SEC-8, SEC-10, SEC-12**. Filed
together because they are one shape: *a default that is insecure **and** silent,
so nothing ever surfaces it.*

## Context

### SEC-8 — APP_DEBUG returns the raw exception message (MEDIUM, CWE-209)

`ApiExceptionRenderer.php:103`:

```php
config('app.debug') && $e->getMessage() !== '' ? $e->getMessage() : 'Server error.'
```

A `QueryException` message embeds the full SQL **and its bindings** — emails,
usernames, coordinates. `.env.example:5` ships `APP_DEBUG=true`, so a copied env
turns any 500 into an unauthenticated PII oracle. `config/app.php:42` correctly
defaults to `false` and no stack trace is emitted; the fix is to gate on
`app()->environment('local')` rather than on `app.debug`, which is a flag an
operator sets for other reasons entirely.

### SEC-6 — the dev stack binds four services to 0.0.0.0 (MEDIUM, CWE-306/1392)

`compose.yaml:43, 73, 88, 107-108` use Docker's short `ports:` syntax, which
binds **all interfaces**. Redis has no `requirepass`, and the repo root is
mounted at `.:/var/www/html` (`:27`) — on shared Wi-Fi that is `CONFIG SET dir` +
`SET dbfilename` writing a PHP webshell into the app root, and Horizon
deserializes queue payloads from that same Redis. Mailpit's dashboard on `:8025`
exposes every password-reset link. Postgres defaults to `DB_PASSWORD:-secret`.

Dev-workstation only — but a *full* compromise of it, and the fix is a
`127.0.0.1:` prefix.

### SEC-10 and SEC-12 — two lines missing from the same runbook env block

- `config/session.php:172`: `env('SESSION_SECURE_COOKIE')` resolves to `null`
  when unset, so Laravel omits the `Secure` flag. The Filament admin panel is
  cookie-session authenticated, so a single plaintext hit to the apex leaks the
  admin session cookie to a passive observer. (CWE-614)
- `config/database.php:99`: `env('DB_SSLMODE', 'prefer')` attempts TLS and
  **silently falls back to cleartext** with no error. The runbook assumes managed
  Postgres — a network hop — and never sets it. (CWE-319)

The runbook is otherwise meticulous about exactly this class of variable, which
is why their absence reads as an oversight rather than a decision.

## Coordination — not a dependency

`apps/api/docs/runbooks/provisioning.md` is T-055's artifact and **T-055 is
in_progress**. The file already exists ("Status: written, not exercised"), so
this task is workable now and deliberately does **not** depend on T-055 — a
dependency would park four security fixes behind a task gated on human deploy
steps. Tell T-055's branch it is coming: both edit that env block.

## Acceptance criteria

- [ ] A 500 raised with `APP_DEBUG=true` outside `local` returns
      `'Server error.'` — asserted with a `QueryException` whose message contains
      a bound email address; and asserted the other way in `local`, so the
      developer affordance survives
- [ ] `compose.yaml` publishes every port on `127.0.0.1` and Meilisearch requires
      a master key; `docker compose config` shows no `0.0.0.0` binding
- [ ] The provisioning env block sets `SESSION_SECURE_COOKIE=true` and
      `DB_SSLMODE=verify-full`, and `.env.example` carries both with a comment
      saying **what their silence looks like**
- [ ] A check (test, or a documented step in the runbook's own checklist) proves
      both vars are present, so a later edit cannot quietly drop them

## Gotchas

- `.env.example` shipping `APP_DEBUG=true` is *convenient* and is half the
  finding. If it stays true, the renderer must not trust it — which is the fix,
  so both can be true at once. Say so in the comment.
- `verify-full` requires a CA the server trusts. If the managed provider needs a
  bundle, the runbook step is "install the bundle", not "lower it to `require`".
- Binding to `127.0.0.1` breaks access from another machine on the LAN (phones
  hitting the API by IP). Check whether anyone's device workflow relies on that
  before it becomes a surprise, and document the override if so.
