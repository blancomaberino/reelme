# T-145 — Validation errors reach Spanish-speaking users in English

- **Phase:** M5 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-105 (ApiResponse envelope), T-010 (mobile auth flow)
- **Target paths:** `apps/api/lang/es/`, `apps/api/app/Http/Middleware/`,
  `apps/api/bootstrap/app.php`, `apps/api/tests/Feature/`,
  `apps/mobile/src/lib/form-errors.ts`

Mobile review 2026-08-19, finding **MOB-3** — and despite coming from a mobile
review it is **mostly an `apps/api` task**, which is exactly why it went
unnoticed.

## Context

`formErrors()` (`src/lib/form-errors.ts:15-16`) returns `error.fields` verbatim,
and **17 sites** render them raw into `<TextField error={…}>` — including the
entire signup path: `register.tsx:74,75,83,92`, `login.tsx:67,76`,
`verify-email.tsx:60`.

The client already sends `Accept-Language` (`client.ts:34`). The API never calls
`App::setLocale()`, and there is no `apps/api/lang/es/validation.php` (the `es`
directory holds only `notifications.php`), so Laravel serves vendor English.

**Failure:** a Uruguayan signing up with an already-registered email sees a
Spanish label, a Spanish button, and then *"The email has already been taken."*

`AgeRestrictedException.php:13` states the problem outright in a comment. It was
written down and never acted on.

### This is a cross-stack seam, and worth naming as one

The client does its half correctly and the server ignores it. A mobile-only
review sees a correct client. An API-only review sees an API nobody told to
speak Spanish. **Neither sees the bug.**

Same structural blind spot as CR-2 (T-128), where the API wrote one shape and
the app read another, and each side's tests agreed with itself.

## Implementation

- `apps/api/lang/es/validation.php`, covering the rules the API actually uses.
- Middleware that sets the app locale from the `Accept-Language` the client
  already sends, with a deterministic fallback for absent or unknown values.
- **Translate attribute names too.** Half-translating produces *"El campo email
  field is taken"*, which is worse than English.

**The enumerating test is the part that lasts.** A hand-written
`es/validation.php` is complete on the day it lands and silently incomplete a
month later. A test that reads the rules actually in use and fails on a missing
message is what keeps it true — same reasoning as T-115's export/purge
enumeration.

## Acceptance criteria

- [ ] A request with `Accept-Language: es` gets Spanish validation messages —
      asserted on the response body for the **signup path** specifically, since
      that is where a first-time user meets it
- [ ] `apps/api/lang/es/validation.php` exists and covers every rule the API
      uses; a test enumerates the rules in use and fails on one with no Spanish
      message
- [ ] The locale comes from the header the client already sends; an absent or
      unknown value falls back deterministically, and that fallback is asserted
- [ ] Attribute names are translated
- [ ] The mobile side is unchanged except where it must be

## Gotchas

- **Do not translate on the client.** Mapping server error strings to client
  copy would put the message in two places and guarantee drift — and the API
  already owns `AgeRestrictedException`'s prose.
- `Accept-Language` is attacker-supplied like any header. Whitelist the locales
  the app actually ships (`es`, `en`); do not pass it to `setLocale()` raw.
- Some API errors are already Spanish-only or English-only prose written by
  hand. Sweep for those while the reasoning is fresh — a mixed-language 422 is
  the same bug wearing a different hat.
