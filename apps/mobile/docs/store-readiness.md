# App Store & Play Store readiness

The pre-submission checklist for T-054 (IR-6, risk R-02), split honestly into
**what the code already satisfies** and **what needs a human with credentials**.

Nothing below is aspirational: every ✅ names the file or endpoint that makes it
true, so a reviewer's question can be answered by opening something rather than
by remembering.

---

## 1. Apple Guideline 1.2 — user-generated content

Apple rejects UGC apps that lack these. **Three of the four are shipped; the
moderation contact is not** — it needs a real monitored address (§5.2), and a
checklist that reads "all four ✅" is exactly how that gets missed and becomes a
rejection.

| Requirement | Status | Where |
|---|---|---|
| **A method for filtering objectionable content** | ✅ | Moderation queue in Filament (T-049); places gate on `PlaceStatus`, shares on `ShareStatus`. |
| **A mechanism to report offensive content** | ✅ | `POST /api/v1/reports` (T-049). In-app: the report control on a profile (`profile-report`), on a place, and on a review. |
| **The ability to block abusive users** | ✅ | `POST/DELETE /api/v1/me/blocks/{username}` (T-054). In-app: **Block** on any other profile, undone from **Settings → Blocked accounts**. Effects are mutual and sever follows in both directions. |
| **Published contact information for a moderation response** | ⚠️ **needs a real address** | Copy is in place; the address itself is a decision — see §5. |

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
| Date of birth | Yes | Yes | **No** | Age-appropriate content |
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

## 5. Human-only — nothing here can be done from the repo

These need accounts, credentials, or a decision. **Do not mark T-054 done until
they are complete.**

1. **Host the privacy policy and terms.** There is no `apps/web` in this
   monorepo, so the documents need somewhere to live (`reelmap.app/privacy`,
   `/terms`). Both stores require reachable public URLs, and Apple additionally
   wants the policy linked from inside the app.
2. **Publish a moderation contact address.** A monitored inbox
   (`moderation@…` / `support@…`), quoted in the App Store listing and the
   privacy policy. Apple checks that a report has somewhere to go.
3. **Fill the privacy questionnaires** in App Store Connect and the Play Console
   Data Safety form, using §3.
4. **Apple credentials:** Apple Developer Program membership, App ID, App Store
   Connect app record, and either an App Store Connect API key or an app-specific
   password for `eas submit`. Fill `submit.production.ios` in `eas.json`.
5. **Google credentials:** Play Console app record, a service-account JSON key
   with release permission. Fill `submit.production.android` in `eas.json`.
6. **Sentry project + EAS secrets:** `SENTRY_ORG`, `SENTRY_PROJECT`,
   `SENTRY_AUTH_TOKEN` (as an EAS secret, never committed) and
   `EXPO_PUBLIC_SENTRY_DSN`. Without the org/project the config plugin is simply
   not registered and the build still succeeds — so a missing key here is silent.
7. **`GOOGLE_MAPS_ANDROID_KEY`** — Android renders Google Maps and shows a blank
   map without it. iOS uses Apple Maps and needs no key, so this gap is invisible
   on the platform most likely to be tested first.
8. **Run the production builds and submit:**
   ```bash
   eas build --profile production --platform all
   eas submit --profile production --platform ios      # → TestFlight
   eas submit --profile production --platform android  # → internal track
   ```
9. **Point `EXPO_PUBLIC_API_URL` at a real API.** `eas.json`'s production profile
   names `https://api.reelmap.app`, which does not exist until T-055 provisions
   it. A production build submitted before then installs and cannot sign in.

## 6. Pre-submission smoke test (once §5 is done)

Walk these on a **TestFlight build**, not a dev client — the difference is where
release-only problems live:

- Sign up → verify email → share a link → see the place appear on the map.
- Report a profile, then block it. Confirm it disappears from the feed and its
  profile 404s, then unblock from Settings.
- Delete the account, then sign back in inside the grace window and confirm the
  deletion is cancelled.
- Decline the location permission and confirm the map still works (it falls back
  to a default region) — a reviewer *will* decline it.
- Airplane mode: confirm the offline banner appears rather than a stack of
  failed requests.
