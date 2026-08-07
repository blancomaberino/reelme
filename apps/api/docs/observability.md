# Observability & the on-call runbook

What is instrumented, where to look when something breaks, and what to do about
each failure the pipeline can produce (T-052, 04 §8).

Written for the person who has just been paged and does not yet know which of
the seven pipeline stages is on fire.

## 1. Where to look, in order

| Question | Where | Notes |
|---|---|---|
| Is the pipeline working *right now*? | Filament `/admin` → Pipeline health | Ingested / published / failed / queue depth, last 24 h. **Queue depth with an old "oldest running stage" is the tell** — a rising depth with a *young* oldest stage is just traffic. |
| Which stage is slow or failing? | `/admin` → Stage timings, Failure mix | p50/p95 per stage and the most common failure codes. Both honour the dashboard window filter. |
| What is it costing? | `/admin` → Analysis cost | Spend, **remote fallback rate**, avg per run. Fallback sustained **above 30%** means local inference is failing or refusing — see §4. |
| What broke, with a stack? | Sentry | Every unhandled HTTP exception and every job that exhausts its retries, tagged `share_id`, `request_id`, `job`, `queue`. |
| Is work piling up? | Horizon `/horizon` + the long-wait alert | Thresholds per queue in `config/horizon.php`; alert routing in §3. |
| What happened to *this one share*? | Sentry search `share_id:<id>`, then `/admin` → Analysis Runs | The share id is on every capture from every stage, which is the point of tagging it. |

**Every one of these is admin-gated.** `/admin` and `/horizon` both require
`users.is_admin`; there is no read-only tier.

## 2. Error tracking (Sentry)

Two switches, and **both** are required — this is deliberate, so that setting a
DSN in an environment does not by itself start shipping events:

```
OBSERVABILITY_ERROR_REPORTER=sentry
SENTRY_LARAVEL_DSN=https://…@…ingest.sentry.io/…
SENTRY_RELEASE=<the commit being deployed>     # see below
SENTRY_ENVIRONMENT=production|staging          # defaults to APP_ENV
```

- **`OBSERVABILITY_ERROR_REPORTER` defaults to `null`** — nothing is sent. CI,
  tests, and any un-provisioned environment stay silent. An alerting system that
  pages during a test run is one somebody switches off.
- **`sentry` with no DSN falls back to `log`**, not to silence. A missing DSN in
  production is a typo, not a decision, and the failure mode of getting it wrong
  is *"we stopped hearing about errors and nobody noticed"* — indistinguishable
  from everything working.
- **Release tagging** comes from `SENTRY_RELEASE`, falling back to
  `FORGE_DEPLOY_COMMIT` then `GITHUB_SHA`. Without a release, every regression
  reads as "always been broken" and *"did the deploy cause this?"* — the first
  question anyone asks — has no answer. Set it in the deploy script:
  ```bash
  export SENTRY_RELEASE="$FORGE_DEPLOY_COMMIT"
  ```
  Do **not** read it from git at runtime: `exec()` on every boot is slow, and
  under `config:cache` it freezes whatever the build host happened to have.

### What is deliberately NOT sent

`send_default_pii` and `breadcrumbs.sql_bindings` are **hard-coded false** in
`config/sentry.php`, not env-backed. They would attach request bodies, cookies,
the authenticated user, and the email addresses and coordinates sitting in a
WHERE clause. T-050 built erasure guarantees over exactly that data, and a copy
inside a third-party tracker is outside every one of them — `DELETE /me` cannot
reach it. One env var flipped during an incident by somebody who wants more
context would permanently export user data, and nothing would fail to tell them.

The correlation this app actually needs is attached explicitly by
`SentryErrorReporter` as **tags** (`share_id`, `request_id`, `job`, `queue`,
`connection`) — searchable, groupable, and PII-free. A test pins both flags.

### Mobile

`EXPO_PUBLIC_SENTRY_DSN` activates `@sentry/react-native` (see
`src/lib/crash-reporting.ts`); unset, it is a pure no-op that never loads the
SDK. Native crash capture needs the native module, so **a Metro reload is not
enough** — the DSN only works in a client built after `npx expo prebuild --clean`.

Source-map upload additionally needs `SENTRY_ORG` + `SENTRY_PROJECT` (the config
plugin is only registered when both are set, so a build without Sentry
credentials still succeeds) and `SENTRY_AUTH_TOKEN` as an **EAS secret** — never
committed, never read from `app.config.ts`.

## 3. Horizon long-wait alerts

`config/horizon.php` has had per-queue `waits` thresholds since T-028, tuned so a
queue never alerts before its own jobs can finish (a media job legitimately runs
for minutes; alerting at the stock 60 s would fire on every normal run, and an
alert that always fires is one nobody reads).

Until T-052 those alerts **went nowhere** — Horizon routes nothing by default.
Route them:

```
HORIZON_ALERT_EMAIL=ops@…
HORIZON_ALERT_SLACK_WEBHOOK=https://hooks.slack.com/services/…
HORIZON_ALERT_SLACK_CHANNEL=#alerts
```

Leave unset in local and CI.

> A long wait is a **backlog**, not a failure. A job that exhausts its retries
> goes to Sentry with its `share_id` instead. Different questions ("is work
> piling up" vs "did this share break"), deliberately different destinations.

## 4. Failure taxonomy → remediation

These are the `shares.failure_code` values the pipeline actually writes; each has
user-facing copy in `apps/mobile/src/i18n/`. **A spike in one code is a much
better signal than a spike in the total** — the fix is completely different per
row.

| Code | Stage | What it means | What to do |
|---|---|---|---|
| `fetch_unavailable` | fetch | The source post would not open — deleted, private, or the platform is blocking us. | Single share: nothing, it is the user's link. **Spike:** the platform changed something. Check the fetcher against one known-good public URL before assuming an outage. |
| `fetch_auth_required` | fetch | The post needs a linked account to read. | Expected for private/close-friends posts; the app tells the user to link the account or add the place by hand. Only investigate if it spikes for *public* URLs — that means a platform auth-wall change. |
| `download_failed` / `ffmpeg_error` | download / prepare | The media could not be fetched or transcoded. | Check `ffmpeg`/`yt-dlp` are installed and on PATH on the **worker** host (they are not app-server deps). A step change right after a deploy usually means a missing binary on a new box. |
| `media_too_large` | prepare | Over the size ceiling. | Working as designed. Only act if the ceiling is wrong for real-world content. |
| `transcribe_error` | transcribe | Whisper/ASR failed. | Usually transient — the retry handles it. Sustained: check the transcription host's disk and memory. |
| `ollama_unreachable` | analyze | The local model host did not answer. | **The one that quietly costs money**: every one of these falls back to the paid engine, so watch it together with the fallback rate in §1. Check `OLLAMA_URL` reachability *from the worker*, not from your laptop. |
| `quota_exhausted` | analyze | The user's daily **AI budget** is used up and local could not serve. | Per-user, self-resolving at midnight UTC. A spike across many users means local inference is down (see the row above) — the budget is only consulted because local was unavailable. |
| `cost_cap_exceeded` | analyze | A single run would exceed the per-run cap. | Almost always an unusually long video. If it is common, the cap or the model choice is wrong, not the share. |
| `invalid_model_output` | analyze | The model returned something that did not match the extraction schema. | Concentrated in **one model** ⇒ that model is the problem: check the by-model breakdown, including its avg confidence. A cheap model that extracts badly is not cheap, it just moves the cost to the review queue. |
| `ambiguous_place` | resolve | Several candidate places, none decisive. | Not an error — it parks in review for a human. Only a problem if the review queue grows faster than it is worked. |
| `geocode_failed` | resolve | No coordinates for the extracted address. | Check the geocoder's quota and key first; ADR-099b (the query must include the street address) is the usual cause of a sudden rise. |
| `resolve_conflict` | resolve | Concurrent resolution of the same place. | Retried automatically. Sustained means contention worth investigating, not user error. |

**Anything not in this table** reaches the user as the `default` copy and should
be in Sentry with a stack trace. If it is not in Sentry, that gap is the bug —
fix the capture before diagnosing the symptom.

## 5. Correlation IDs

Every request carries `X-Request-Id` (echoed on responses **and on errors** —
T-092), it rides into the queue via `Context`, and it is a Sentry tag. So one
value connects: the client's failed request → the API log line → every pipeline
job it spawned → the Sentry event for whichever one broke.

When a user reports a problem, ask for the time and the share, not the error
text. `share_id` is on every capture from every stage and is far more useful.
