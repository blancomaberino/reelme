# T-126 — Close the cheap latent risks; write down the ones we are accepting

- **Phase:** M5 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** nothing
- **Target paths:** `apps/api/app/Jobs/DownloadMedia.php`,
  `apps/api/database/seeders/AdminUserSeeder.php`, `.github/workflows/ci.yml`,
  and ADR entries in the plan's `07-risks-decisions.md`

The tail of the 2026-08-19 security review: **SEC-14**, **SEC-15**, and the
"also noted, not ranked" list. Filed as one small task so none of it is silently
lost — **not** because these are equivalent to the findings above.

## Context — stated honestly

Inflating these would be the easy mistake, and the review was careful not to.

### SEC-14 — DownloadMedia has no SSRF guard (LOW, CWE-918). **Latent.**

`DownloadMedia.php:189` fetches a remote URL with no host vetting and follows
redirects, while every other outbound fetch in the codebase does the opposite —
`RemoteFileFetcher.php:31-33` vets the host and refuses redirects, with a comment
explaining why. Its docblock asserts vetting happens upstream, which the reviewer
could not find.

**But**: all three `FetchedMedia` producers were enumerated —
`InstagramGraphAdapter` (a Meta-CDN `media_url`), `ManualUploadAdapter` (our own
signed disk URL) and `YtDlpAdapter` (returns `localPath`, short-circuiting the
fetch) — and **no attacker-reachable producer exists today**. The byte cap is
correctly enforced on bytes received.

So this is defence in depth against a *future* producer, it is two lines, the
guard already exists — and it is **not an open hole**. Do not describe it as one.

### SEC-15 — Expo push-token reassignment (LOW, CWE-639). **A trade-off.**

`DeviceController.php:34` keys `Device::updateOrCreate` on the token alone, so a
user who learns another's push token re-points the row: the victim silently stops
receiving their own notifications and starts receiving the attacker's. **The
docblock documents this as intentional** (shared-device handling). It deserves an
explicit decision with the consequence written next to it. Changing the behaviour
is out of scope here — if the decision goes the other way, that is a new task.

### Also noted, not ranked

- **Filament is a single flat `is_admin` role.** The `can*` methods that exist are
  workflow constraints (`PlaceResource::canCreate(): false`), not separation of
  duties, so any admin can promote any account via the `is_admin` toggle
  (`UserForm.php:59`) on a panel that also holds takedowns and payouts. Correct
  for a one-operator product; a real problem the day there is a second admin.
- **`AdminUserSeeder.php:18` guards only `production`**, so a *staging* seed would
  create `admin@reelmap.test/password`. It is never wired into `DatabaseSeeder`
  and needs an explicit `--class`, so it is a documented manual step — and a
  one-word fix.
- **CI actions ride floating tags** (`ci.yml:54,129`); impact bounded by
  `permissions: contents: read` and `persist-credentials: false`.
- **`PATCH /me` sets `birthdate` with no age check** — harmless today because
  `age_verified_at` is the authoritative flag and is deliberately excluded from
  `$fillable` (T-113).
- **No `trustProxies()` anywhere.** Correct for the nginx-on-same-host Forge
  topology in the runbook. The day a CDN or load balancer is added, **every**
  IP-keyed limiter — including auth at 5/min — collapses into one global bucket.
  This is the one that most needs a written trigger.

## Implementation

Fix the three that are one line each: the SSRF guard
(`PublicUrlGuard::assertPublic($media->url, ['https'])` +
`'allow_redirects' => false`), the seeder environment, the action pins.

Record the rest as ADRs. **An ADR with a trigger condition is a decision; an ADR
without one is a note nobody will act on.**

## Acceptance criteria

- [ ] `DownloadMedia` vets the URL with `PublicUrlGuard` and refuses redirects —
      asserted with a fetch that 302s to `169.254.169.254` and one to an RFC1918
      address
- [ ] The byte cap and every existing `DownloadMedia` behaviour still pass — a
      guard added, not a rewrite
- [ ] `AdminUserSeeder` refuses to run anywhere but `local`
- [ ] CI actions are pinned to commit SHAs
- [ ] Four ADRs recorded, each with the condition that would reopen it:
      push-token reassignment, the flat Filament admin role, `PATCH /me`
      birthdate vs `age_verified_at`, and the absent `trustProxies()`

## Gotchas

- Do not let the SSRF fix grow into a review of the whole media pipeline. The
  producers were already enumerated; re-deriving that is the expensive part and
  it is done.
- The trustProxies ADR should name the *observable symptom* (auth throttling one
  global bucket), not just the config. That is what someone will recognise later.
