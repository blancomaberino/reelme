# Provisioning staging & production

> 📋 **Going to production?** Start from
> [`docs/deployment-to-prod.md`](../../../../docs/deployment-to-prod.md) — the single ordered checklist
> across the stores, the servers and the data. This document is the detail
> behind one of its phases.


What the Reelmap API needs to run, and the order to build it in (T-055,
01 §environments-deployment).

> **Status: written, not exercised.** No environment has been provisioned yet —
> every command below is derived from the repo (the compose stack, the queue
> config, the scheduler, `.env.example`) rather than from a run against real
> infrastructure. Treat the first pass as an exercise to be watched, and correct
> this file from what actually happens. A runbook that has never been followed
> is a hypothesis.

## 1. What has to exist

| Component | Why | Notes |
|---|---|---|
| PHP **8.4+** | Laravel 13 | Extensions: `pdo_pgsql`, `pgsql`, `redis`, `gd`, `intl`, `zip`, `bcmath` — the set CI installs |
| **Postgres 16+ with PostGIS** | The first migration runs `CREATE EXTENSION postgis` (plus `pg_trgm`, `unaccent`, `citext`) | A plain Postgres image fails on migration 1. Managed Postgres must have PostGIS available |
| **Redis** | Queue, cache, **and the scheduler's locks** | Not optional — see §4 |
| **Meilisearch** | Federated search (T-031) | The app falls back to the collection driver, so a missing Meilisearch degrades search rather than breaking the app |
| **ffmpeg** + **yt-dlp** | Media download and keyframe extraction, on the **worker** | Not app-server dependencies. A worker box without them fails every share with `ffmpeg_error` / `download_failed` |
| **Ollama host** | Local inference; the paid engine is the fallback | See §5 — the cost consequence of getting this wrong is silent |
| **Object storage** (Cloudflare R2 or S3) | Media, two private buckets | See §6 |

## 2. Order

1. Provision the server(s), database and Redis.
2. Create the database **and enable PostGIS** before the first deploy.
3. Set the environment (§3), then deploy — `scripts/deploy.sh`.
4. Enable the **queue worker** (Horizon) and the **scheduler**.
5. Verify §7 before pointing any client at it.

## 3. Environment

Start from `apps/api/.env.example`; it documents every key. The ones with a
**silent** failure mode, which is why they are listed separately:

```dotenv
APP_ENV=production
APP_DEBUG=false                  # a true here leaks stack traces to the API
APP_KEY=                         # php artisan key:generate --force, ONCE, then keep it

QUEUE_CONNECTION=redis           # `sync` would run the whole pipeline inside the request
CACHE_STORE=redis                # the scheduler's onOneServer locks live here (§4)
SESSION_DRIVER=redis

OBSERVABILITY_ERROR_REPORTER=sentry
SENTRY_LARAVEL_DSN=              # absent ⇒ falls back to `log`, NOT to silence
HORIZON_ALERT_EMAIL=             # absent ⇒ long-wait alerts go nowhere at all

OLLAMA_URL=                      # unreachable ⇒ every run silently uses the PAID engine
MEDIA_DISK=media                 # local disks are dev-only

DEPLOY_SECRET=                   # openssl rand -hex 16 — see below
```

**`DEPLOY_SECRET`** is the maintenance-mode bypass path used during a deploy
(`/<secret>`). `scripts/deploy.sh` passes it only when set and has **no
default**, because a shared default would be a guessable way past maintenance
mode into a half-migrated app on every single deploy. Generate a random one per
environment.

**`APP_KEY` is generated once and never rotated casually.** It encrypts
`two_factor_secret` and `two_factor_recovery_codes`; changing it locks every
2FA user out of their own account with no recovery path.

## 4. The scheduler and the queue

Both are required. The app is not a request/response service — most of what it
does happens in `routes/console.php` and Horizon.

```crontab
* * * * * cd /home/forge/reelmap/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

**Without the scheduler, retention never runs.** `docs/media-retention.md` says
it out loud: the ADR-010 72-hour window is enforced by
`reelmap:media:prune-originals`, so a missing cron makes every retention window
effectively infinite — a compliance failure that produces no error anywhere.

The same cron carries the GDPR deletion sweep, which is the *fail-safe* behind
account erasure (the delayed job is the fast path, not the guarantee).

**Every scheduled command uses `onOneServer()`**, which takes a lock in the
**cache** store. On more than one app server with `CACHE_STORE=file`, each box
holds its own lock and every job runs once per server — the payout run being the
one where that is expensive.

Horizon runs as a daemon (`php artisan horizon`) under a process supervisor.
Its supervisors and queues are already defined in `config/horizon.php`; a
supervisor listed only under `defaults` and not under `environments` **never
runs** (there is a test pinning that pair — see the T-050 notes).

## 5. The Ollama host

`OLLAMA_URL` must be reachable **from the worker**, not from your laptop.

Getting this wrong does not fail — it falls back to the paid remote engine on
every single run. The symptom is a bill, and the place it shows up is the
**remote-fallback rate** on `/admin` (04 §8 treats a sustained rate above 30% as
a warning). Watch that number for the first day after any change here.

Verify from the worker itself:

```bash
curl -fsS "$OLLAMA_URL/api/tags" | head
```

## 6. Object storage

Two **private** buckets/prefixes, `media` and `media_originals`. R2 has no
object ACLs — never set `visibility => public`; everything is served through
signed URLs (NFR-8).

Set the lifecycle rule described in `docs/media-retention.md`: expire the
`originals/` prefix at **`MEDIA_IN_FLIGHT_CEILING_HOURS` (168 h)**, *not* at the
72 h retention window. A rule at 72 h deletes the originals of shares still
mid-pipeline, which is exactly the case the ceiling exists to protect; the
hourly command is what enforces 72 h for finished ones.

## 7. Post-provision verification

Run these **before** pointing a client at the environment. Each one fails
loudly; the point is that several of the failures above do not.

```bash
# The app boots and the database answers.
php artisan about
curl -fsS https://api.example.com/api/v1/health

# PostGIS is really there (a missing extension fails migration 1, but a restored
# database can be missing it while the migrations table says otherwise).
php artisan tinker --execute="dd(DB::select('select postgis_version()'));"

# The scheduler is registered and its next runs look sane.
php artisan schedule:list

# Horizon is up and its supervisors match the config.
php artisan horizon:status

# The queue actually drains — dispatch something trivial and watch Horizon.
php artisan tinker --execute="dispatch(fn () => logger('deploy smoke'));"

# Media round-trips to real storage.
php artisan tinker --execute="Storage::disk(config('media.disk'))->put('smoke.txt','ok'); dd(Storage::disk(config('media.disk'))->get('smoke.txt'));"

# Local inference is reachable FROM THIS BOX (see §5).
curl -fsS "$OLLAMA_URL/api/tags" >/dev/null && echo "ollama ok"
```

Then confirm the two things that are silent when broken:

- **Sentry**: throw once from tinker and check the event arrives, tagged with
  the release. `docs/observability.md` §2.
- **Retention**: `php artisan reelmap:media:prune-originals --help` runs, and
  `schedule:list` shows it hourly.

## 8. Rollback

`scripts/deploy.sh` lifts maintenance mode on any failure, so a failed deploy
leaves the **old** code serving. To go back deliberately:

```bash
git -C /home/forge/reelmap reset --hard <previous-sha>
cd apps/api && composer install --no-dev -o \
  && php artisan config:cache && php artisan horizon:terminate
```

**Migrations are not rolled back automatically and mostly should not be.**
`migrate:rollback` on a schema that has already taken writes loses data. Prefer
rolling the *code* back and writing a forward migration; the `down()` methods
exist for development, and at least one (T-085's Google-verified backfill) is
explicitly irreversible by design.

See [backup-restore.md](backup-restore.md) for the case where that is not enough.
