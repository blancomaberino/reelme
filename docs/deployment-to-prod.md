# Deployment to production

Every outstanding thing between the current repository and Reelmap being live in
both app stores, in the order it has to happen.

**Why this document exists.** The work was tracked in three places that each
looked complete on their own — `apps/mobile/docs/store-readiness.md` for the
stores, `apps/api/docs/runbooks/provisioning.md` for the servers,
`apps/api/docs/runbooks/backup-restore.md` for the data — and nothing said which
had to come first, or that half the store list is blocked on the server list. So
this is the single ordered checklist. It **links to** those runbooks for the
detail rather than restating them: a second copy of a command is a copy that
goes stale.

**Everything here needs a human.** None of it can be done from the repository —
it needs accounts, credentials, money, or a decision. The code side of T-054 and
T-055 has shipped.

> ⚠️ **The three-line summary.** Nothing can be submitted to a store until the
> API is provisioned (Phase 1), because the privacy policy URL has to answer
> publicly. The legal identity variables (Phase 2) are not optional — without
> them those pages return **503**. And the app must not be built pointing at an
> API that does not exist yet (Phase 5).

---

## Status at a glance

| Phase | What | Blocked by |
|---|---|---|
| 1 | Provision staging + production | — |
| 2 | Legal identity + contact mailbox | Phase 1 |
| 3 | Verify the public endpoints | Phases 1–2 |
| 4 | Legal review of the documents | Phase 3 (needs the live text) |
| 5 | Store credentials, secrets and keys | Phase 3 |
| 6 | Build, submit, smoke-test | Phase 5 |
| 7 | Backup + restore drill | Phase 1 |

Phases 4 and 5 can run in parallel. Phase 7 should happen before real users
exist, not after.

---

## Phase 1 — Provision the infrastructure (T-055)

**Full detail: [`apps/api/docs/runbooks/provisioning.md`](../apps/api/docs/runbooks/provisioning.md).**
Nothing in that runbook has ever been run against real infrastructure, and it
says so at the top. Treat the first run as the test of the runbook as much as of
the servers.

- [ ] **App server** (Forge or equivalent), staging **and** production.
- [ ] **Postgres 16+ with PostGIS available.** Not optional and not
      retrofittable: the first migration runs `CREATE EXTENSION postgis`, so a
      plain Postgres fails on migration 1.
- [ ] **Redis** — queue, cache and sessions.
- [ ] **Meilisearch** — search and people-search.
- [ ] **Horizon daemon** + **scheduler cron**.
- [ ] **`ffmpeg` and `yt-dlp` installed ON THE WORKER**, not just the web box.
      The pipeline shells out to them from queued jobs.
- [ ] **Ollama host**, reachable *from the worker*.
- [ ] **Two private buckets** with the `originals/` lifecycle rule at the
      **168h ceiling** — not the 72h window. The 72h is the app's own deletion
      pass; the lifecycle rule is the backstop for an object the app never got
      to, and setting it to 72h would delete originals out from under an
      in-flight retry.
- [ ] **`APP_KEY`** generated once (`php artisan key:generate --force`) and
      **stored somewhere you can get it back**. See Phase 7.
- [ ] **`DEPLOY_SECRET`** — `openssl rand -hex 16`, different per environment.
- [ ] **Run the deploy for real** (`scripts/deploy.sh`).
- [ ] **Run the post-provision verification** — provisioning.md §7 is a block of
      commands that check the things whose absence is invisible.

## Phase 2 — Legal identity and the contact mailbox (T-054)

The documents are written and served. They name nobody until you tell them who.

- [ ] Set on the production host:
      ```dotenv
      LEGAL_CONTROLLER_NAME=        # full legal name, person or company
      LEGAL_CONTROLLER_DOMICILE=    # e.g. "Montevideo, Uruguay"
      LEGAL_CONTACT_EMAIL=          # the published moderation / privacy address
      ```
      **There are no defaults.** Any one unset, blank, or whitespace-only and
      both `/privacy` and `/terms` return **503**. That is deliberate: the two
      silent alternatives were publishing a name meant to be withheld, or
      publishing a privacy policy naming no data controller — which is not a
      rougher draft of one, it is an invalid one.
- [ ] **Make that mailbox real and monitor it.** It is a published commitment,
      and the terms state a **24-hour** response window for reports. Apple
      checks that a report has somewhere to go.

## Phase 3 — Verify the public endpoints

The store forms need URLs that answer from the public internet, not from your
laptop.

- [ ] `https://api.reelmap.app/privacy/es` → 200, and names the party from Phase 2
- [ ] `https://api.reelmap.app/privacy/en` → 200
- [ ] `https://api.reelmap.app/terms/es` → 200
- [ ] `https://api.reelmap.app/terms/en` → 200

```bash
for u in /privacy/es /privacy/en /terms/es /terms/en; do
  printf '%-14s %s\n' "$u" "$(curl -s -o /dev/null -w '%{http_code}' https://api.reelmap.app$u)"
done
```

A **503** here means Phase 2 is incomplete. A **500** means a locale is routable
without a document behind it — see `LegalDocumentTest`, which is supposed to make
that impossible.

## Phase 4 — Legal review

- [ ] **Have a lawyer read `/privacy` and `/terms`.**

They are accurate to the code — every retention window, third-party recipient and
deletion behaviour was read out of the schema and config, and the numbers are
bound to that config by test. **Accurate is not the same as legally sufficient.**
They name a data controller personally, assert Uruguayan governing law and the
courts of Montevideo, carry a liability limitation and a consumer-rights
carve-out, and commit to a 24-hour moderation response. None of that has been
reviewed by anyone qualified.

If the wording changes, bump the `UPDATED` dates in `LegalDocumentController` in
the same commit.

## Phase 5 — Store credentials, secrets and keys (T-054)

Detail and questionnaire answers: [`apps/mobile/docs/store-readiness.md`](../apps/mobile/docs/store-readiness.md).

- [ ] **Apple** — Developer Program membership, App ID, App Store Connect app
      record, and an App Store Connect API key (or app-specific password). Fill
      `submit.production.ios` in `eas.json`, which is currently `{}`.
- [ ] **Google** — Play Console app record, service-account JSON key with release
      permission. Fill `submit.production.android`.
- [ ] **Sentry** — `SENTRY_ORG`, `SENTRY_PROJECT`, `SENTRY_AUTH_TOKEN` as EAS
      secrets, plus `EXPO_PUBLIC_SENTRY_DSN`.
- [ ] **`GOOGLE_MAPS_ANDROID_KEY`.**
- [ ] **Fill both privacy questionnaires** — App Store Connect and the Play
      Data Safety form — from store-readiness.md §3. Read the correction
      notes under that table before answering — they exist because that table
      was wrong once.
- [ ] **Confirm `EXPO_PUBLIC_API_URL`** in `eas.json`'s production profile points
      at the API that now exists.

## Phase 6 — Build, submit, smoke-test

- [ ] Build and submit:

  ```bash
  eas build --profile production --platform all
  eas submit --profile production --platform ios      # → TestFlight
  eas submit --profile production --platform android  # → internal track
  ```

- [ ] **Walk the smoke test in store-readiness.md §7 on a TestFlight build**, not
      a dev client. Release-only problems live exactly in that gap.

## Phase 7 — Backup and restore drill (T-055)

**Full detail: [`apps/api/docs/runbooks/backup-restore.md`](../apps/api/docs/runbooks/backup-restore.md).**

- [ ] **Automated backups** running and verified.
- [ ] **`APP_KEY` stored separately from the database backup.** It encrypts
      `two_factor_secret` and the recovery codes — a database restored without
      the matching key locks every 2FA user out permanently, with no recovery
      path.
- [ ] **Run the restore drill once**, into a database where you have created the
      extensions by hand. A `pg_dump` does not reliably carry extensions.
- [ ] **Verify the spatial layer specifically**, not just row counts. A restore
      that lost `places_location_gist` returns correct results and seq-scans
      forever.
- [ ] **Record the drill in backup-restore.md §6.** That table has no rows, and
      T-055's acceptance criterion "backup + restore runbook tested once" is not
      met until it does.

---

## The silent failures

Everything on this page fails loudly except these. Each one leaves a green
deploy and a broken product, so check them explicitly rather than waiting to
notice.

| If this is missing | What happens | How you would find out |
|---|---|---|
| **Scheduler cron** | Retention **never runs**. The ADR-010 72h window becomes infinite and originals accumulate — the copyright exposure R-07 exists to bound | Nothing. No error anywhere |
| **`OLLAMA_URL` unreachable** | Every analysis silently uses the **paid** engine | The fallback rate on `/admin`, or the bill |
| **`SENTRY_LARAVEL_DSN`** | Error reporting falls back to `log` — not to silence, but not to anyone either | Nothing, until you need an incident you cannot reconstruct |
| **`HORIZON_ALERT_EMAIL`** | The tuned long-wait thresholds alert nobody | Nothing |
| **`SENTRY_ORG` / `SENTRY_PROJECT`** | The config plugin is not registered and **the build still succeeds** | Nothing — no crash reports ever arrive |
| **`GOOGLE_MAPS_ANDROID_KEY`** | Android renders a blank map. iOS uses Apple Maps and is unaffected | Only on Android — invisible on the platform you will test first |
| **`CACHE_STORE=file` on >1 app server** | Every `onOneServer()` command runs once **per server**, the monthly payout run included | Duplicate payouts |
| **PostGIS missing after a restore** | Correct results, seq-scanning forever | Latency, eventually |
| **Legal identity vars unset** | `/privacy` and `/terms` return 503 | App Review opens the policy URL. This one is loud, but it is loud *at the reviewer* |

---

## Not blocking launch

- **T-067 — Social login (Sign in with Apple + Google).** Pending, and needs
  credentials from the same two consoles as Phase 5, so it is convenient to
  collect them together. `SocialController` is a 501 stub; nothing ships broken
  if this never happens. Note that **Apple requires Sign in with Apple** if you
  ever offer another third-party sign-in, so Google-only is not an option.
- **T-113 — Age gate.** Implemented, awaiting merge. No deployment
  implications: it adds no configuration and no infrastructure. `LEGAL_MINIMUM_AGE`
  has a safe default of 13 and only needs setting if you want a different floor.

---

## Task status

Neither T-054 nor T-055 is done, and neither should be marked done until the
boxes above are ticked:

- **T-054** — code shipped (PR #194). Phases 2, 4, 5, 6 outstanding.
- **T-055** — deploy script and runbooks written (PR #185), **never run against
  real infrastructure**. Phases 1, 7 outstanding.
