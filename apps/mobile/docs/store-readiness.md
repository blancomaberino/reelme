# App Store & Play Store readiness

> 📋 **Going to production?** Start from
> [`docs/deployment-to-prod.md`](../../../docs/deployment-to-prod.md) — the single ordered checklist
> across the stores, the servers and the data. This document is the detail
> behind one of its phases.


The pre-submission checklist for T-054 (IR-6, risk R-02), split honestly into
**what the code already satisfies** and **what needs a human with credentials**.

Nothing below is aspirational: every ✅ names the file or endpoint that makes it
true, so a reviewer's question can be answered by opening something rather than
by remembering.

---

## 1. Apple Guideline 1.2 — user-generated content

Apple rejects UGC apps that lack these. **Four of the five are shipped in code.**
The fifth, the moderation contact, is written and asserted but its value comes
from the environment and is not set yet (§5) — a checklist that reads "all five
✅" is exactly how that gets missed and becomes a rejection.

| Requirement | Status | Where |
|---|---|---|
| **A method for filtering objectionable content** | ✅ | Moderation queue in Filament (T-049); places gate on `PlaceStatus`, shares on `ShareStatus`. |
| **A mechanism to report offensive content** | ✅ | `POST /api/v1/reports` (T-049). In-app: on a profile (`profile-report`), on a place (`place-report`), and on each native review (its own endpoint + reason set). |
| **The ability to block abusive users** | ✅ | `POST/DELETE /api/v1/me/blocks/{username}` (T-054). In-app: **Block** on any other profile, undone from **Settings → Blocked accounts**. Effects are mutual and sever follows in both directions. Reachable from a place: the sharer's `@handle` on a source card taps through to their profile. |
| **Published contact information for a moderation response** | ⚠️ **needs `LEGAL_CONTACT_EMAIL` set** | The address is published in both documents in both languages, and asserted by `LegalDocumentTest` on all four pages so it cannot quietly disappear — but it comes from the environment (§5) and there is no default. Unset ⇒ the pages 503. |
| **A EULA with zero tolerance for objectionable content and abusive users** | ✅ | Terms §6, both languages, with the 24-hour commitment to act on reports. Users agree to it at the moment of registration — the consent line under **Create account** links both documents. |

> Blocking is deliberately **not** the same as reporting, and the copy says so:
> a report is a request to a moderator, a block takes effect immediately. An app
> that offers only reporting leaves a person being harassed waiting on a queue.

## 2. Guideline 5.1.1(v) — account deletion in-app

✅ **Settings → Privacy & data → Delete my account** (T-050). Not a link to a web
form and not an email request: `DELETE /api/v1/me` soft-deletes and revokes every
token immediately, and `PurgeUserData` erases after a 14-day grace period.
Signing back in inside that window cancels it.

A typed-word confirmation guards it, and the copy discloses what survives —
payment and redemption records are legally retained (ADR-009).

## 3. Data collection — the privacy questionnaire answers

Audited from the schema, not recalled. Use these for **both** stores' forms.

| Data | Collected? | Linked to identity? | Used for tracking? | Purpose |
|---|---|---|---|---|
| Email address | Yes | Yes | **No** | Account, sign-in, transactional mail |
| Name / username / bio / avatar | Yes | Yes | **No** | The user's public profile |
| Date of birth, country, favourite topics / foods | Yes | Yes | **No** | **Optional profile personalization the user edits themselves** (`users.birthdate`, `country_code`, `favorite_topics`, `favorite_foods`) |
| Precise location | Yes | **No** | **No** | Centring the map on first launch, "locate me". Foreground only (`WHEN_IN_USE`); never stored against the account |
| Photos / camera | Yes | No | **No** | Scanning a redemption QR at the till only. No photo-library access is requested |
| User content (shared links, captions, reviews, lists) | Yes | Yes | **No** | The product |
| Purchase history | Yes | Yes | **No** | Redemptions and payouts (Stripe Connect) |
| Identifiers (push token, device name, app version) | Yes | Yes | **No** | Push notifications |
| Diagnostics / crash data | Yes | **No** | **No** | Sentry — **PII deliberately excluded**, see below |
| Contacts, browsing history, health, financial account numbers | **No** | — | — | Never requested |

**"Used for tracking" is No everywhere**, which is the answer that decides whether
App Tracking Transparency applies: there is no advertising SDK, no third-party
analytics, and no data shared with data brokers.

> **Corrected 2026-08-13.** This table previously listed date of birth as
> collected for "age-appropriate content". It is not: `birthdate` is a nullable
> column the user fills in on their own profile, there is no age gate anywhere in
> the codebase, and `country_code`, `favorite_topics` and `favorite_foods` were
> missing from the table entirely. Answering a store questionnaire with a purpose
> the app does not implement is a false statement in a store listing, so the row
> now describes what the schema actually does.
>
> **Updated 2026-08-14 (T-113).** The note above used to end "the minimum age is
> stated in the terms, not enforced by a gate". **It is enforced now**: signup
> asks for a date of birth and refuses anyone below `config('legal.minimum_age')`.
>
> **This does not change any answer in the table.** The date is a *neutral age
> screen* — checked and discarded, never written to any column, asserted by a
> test that walks every field of the row. Apple's definition of "collect" is
> transmitting data off the device and retaining it beyond servicing the
> request, and nothing is retained but a timestamp saying a check happened. So
> date of birth stays what the row says it is: optional profile personalization
> the user fills in themselves.
>
> The privacy policy says this out loud in **Children** — that we ask, that we
> check, and that we do not keep it. A questionnaire and a policy that disagree
> about the same field is the exact failure this box was written to record.

**Crash data carries no PII by construction, not by policy.** `send_default_pii`,
`breadcrumbs.sql_bindings` and `tracing.sql_bindings` are hard-coded `false` in
`apps/api/config/sentry.php` — deliberately not env-backed, so an incident cannot
turn them on (T-052). What Sentry receives is a stack trace plus `share_id` /
`request_id` / `job` / `queue` tags.

### Permission purpose strings

Written per-permission in `app.config.ts`, in Spanish (the default locale), each
naming the actual use. A vague purpose string is what makes people decline, and
a declined camera here is recoverable — manual code entry always works — whereas
a rejected review is not.

## 4. Guideline 4.2 — minimum functionality

Not a concern: map, discovery, lists, offers, redemptions and social. Noted only
because R-02 flags it.

## 5. The legal documents — where they live

✅ Both documents are **published by the API**, in Spanish and English:

| URL | What |
|---|---|
| `/privacy` · `/privacy/es` · `/privacy/en` | Privacy policy |
| `/terms` · `/terms/es` · `/terms/en` | Terms of service (the EULA) |

The bare path negotiates the language from `Accept-Language` and falls back to
Spanish; the pinned paths are what you paste into a per-locale field in App Store
Connect, and what the app links to so the page opens in the language the user
chose in-app.

They are served from `apps/api` rather than a marketing site because there is no
`apps/web`, and both stores need a reachable URL before a build can be submitted.
Source: `resources/views/legal/{privacy,terms}/{es,en}.blade.php`, routed by
`LegalDocumentController`.

**In-app links** (Apple requires the policy reachable from inside the app):
**Settings → Legal**, deliberately outside the signed-in gate so a reviewer with
no account can open it, plus a consent line under **Create account** linking both.

Two things about these documents are load-bearing and tested rather than trusted:

- **The numbers are bound to config.** The policy states the 72h/168h media
  retention, the 14-day deletion grace, the 7-day export retention, the 24h link
  TTL and the 90-day payload window. `LegalDocumentTest` asserts each against
  `config()`, so changing an env default fails the suite instead of silently
  turning the published policy into a false statement.
- **No third-party requests.** The pages load no scripts, no webfonts, no remote
  images — asserted. A privacy policy that phones a CDN to render is both ironic
  and an extra disclosure.

**Who they name comes from the environment, not the repository.** The operator is
a private individual, so their name and domicile are personal data in their own
right and are not committed here. `config/legal.php` reads three variables:

```text
LEGAL_CONTROLLER_NAME       e.g. a person's full legal name, or a company name
LEGAL_CONTROLLER_DOMICILE   e.g. "Montevideo, Uruguay"
LEGAL_CONTACT_EMAIL         the published moderation / privacy address
```

There are **no defaults**, and with any of them unset — or blank, or whitespace —
both documents return **503** rather than publishing a contract with no party to
it. That is deliberate: the two silent alternatives are leaking a name that was
meant to be withheld, or serving a privacy policy naming no data controller,
which is not a rougher draft of one but an invalid one.

> ⚠️ **This fails loudly, and it fails in production if you forget.** A build
> submitted while these are unset points App Review at a 503. Set them on the
> host in the same pass as the rest of the T-055 environment.

These fill in **identity, not jurisdiction**. The documents are written for
Uruguayan law with GDPR terms retained for EU users; moving to another country,
or from a person to a company, is a rewrite of the prose rather than a change of
values.

> ⚠️ The other thing still missing is **hosting**: these URLs only exist once
> T-055 provisions the API. Until then there is nothing to paste into App Store
> Connect. See §6.1.

## 6. Human-only — nothing here can be done from the repo

These need accounts, credentials, or a decision. **Do not mark T-054 done until
they are complete.**

1. ⚠️ **Host the privacy policy and terms — STILL OPEN.** The documents are
   written and served by the app (see §5), so the authorship half is done, but
   **no public URL exists yet** and both stores require one before a build can
   be submitted. This item closes only when T-055 has provisioned
   `api.reelmap.app` and `https://api.reelmap.app/privacy/es` actually answers
   over the public internet. Then paste that and `/privacy/en` into App Store
   Connect and the Play Console. (If you later put up a marketing site, 301
   `reelmap.app/privacy` at these rather than forking a second copy of the text.)
2. ⚠️ **Set the legal identity, and publish a moderation contact — STILL OPEN.**
   `LEGAL_CONTROLLER_NAME`, `LEGAL_CONTROLLER_DOMICILE` and
   `LEGAL_CONTACT_EMAIL` (see §5). The documents carry the plumbing but no
   values, and return 503 until they have them. **The mailbox must actually
   exist and be monitored**: Apple checks that a report has somewhere to go, and
   the address becomes a published commitment, including the 24-hour response
   window the terms state.
3. **Read the two documents, and have them reviewed.** They were drafted against
   what the code actually does — every retention window, third-party recipient
   and deletion behaviour in them was read out of the schema and config, not
   recalled — but *accurate* is not the same as *legally sufficient*. They name
   you personally as data controller under Uruguayan law and assert a governing
   jurisdiction, a liability limitation and a consumer-rights carve-out. Neither
   has been seen by a lawyer. Get that done before submission, and bump the
   `UPDATED` dates in `LegalDocumentController` in the same commit as any wording
   change.
4. **Fill the privacy questionnaires** in App Store Connect and the Play Console
   Data Safety form, using §3 — noting the correction box under that table.
5. **Apple credentials:** Apple Developer Program membership, App ID, App Store
   Connect app record, and either an App Store Connect API key or an app-specific
   password for `eas submit`. Fill `submit.production.ios` in `eas.json`.
6. **Google credentials:** Play Console app record, a service-account JSON key
   with release permission. Fill `submit.production.android` in `eas.json`.
7. **Sentry project + EAS secrets:** `SENTRY_ORG`, `SENTRY_PROJECT`,
   `SENTRY_AUTH_TOKEN` (as an EAS secret, never committed) and
   `EXPO_PUBLIC_SENTRY_DSN`. Without the org/project the config plugin is simply
   not registered and the build still succeeds — so a missing key here is silent.
8. **`GOOGLE_MAPS_ANDROID_KEY`** — Android renders Google Maps and shows a blank
   map without it. iOS uses Apple Maps and needs no key, so this gap is invisible
   on the platform most likely to be tested first.
9. **Run the production builds and submit:**
   ```bash
   eas build --profile production --platform all
   eas submit --profile production --platform ios      # → TestFlight
   eas submit --profile production --platform android  # → internal track
   ```
10. **Point `EXPO_PUBLIC_API_URL` at a real API.** `eas.json`'s production profile
   names `https://api.reelmap.app`, which does not exist until T-055 provisions
   it. A production build submitted before then installs and cannot sign in.

## 7. Pre-submission smoke test (once §6 is done)

Walk these on a **TestFlight build**, not a dev client — the difference is where
release-only problems live:

- Sign up → verify email → share a link → see the place appear on the map.
- From a **place**, tap the sharer's `@handle` → their profile → **Block**.
  Confirm the profile 404s afterwards, then unblock from Settings. (That path
  is the one a reviewer will try: it is where you actually meet someone's
  content.)
- Report a **review** on a place, and confirm the reasons offered are the
  review set (spam / offensive / off-topic / other), not the place set.
- Delete the account, then sign back in inside the grace window and confirm the
  deletion is cancelled.
- Decline the location permission and confirm the map still works (it falls back
  to a default region) — a reviewer *will* decline it.
- Airplane mode: confirm the offline banner appears rather than a stack of
  failed requests.
