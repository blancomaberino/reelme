# Backup & restore

What to back up, how to restore it, and what a restore does **not** bring back
(T-055).

> **Status: written, not exercised.** T-055 asks for a restore that has been
> **tested once**, and that has not happened — no environment exists yet. Until
> somebody runs §4 against a real snapshot and records the result at the bottom
> of this file, this is a plan, not a procedure. An untested backup is a backup
> you do not have; that is not a figure of speech, it is the single most common
> way this goes wrong.

## 1. What is worth backing up

| Thing | Back up? | Why |
|---|---|---|
| **Postgres** | **Yes — this is the product** | Places, shares, users, the ledger. Everything else is either derivable or replaceable |
| **`APP_KEY`** and the `.env` | **Yes, separately** | See §2 |
| Object storage (`media`) | Yes | Derived keyframes/thumbnails. Regenerable in principle, but only from originals that were deliberately deleted (ADR-010) — so in practice, not |
| Object storage (`media_originals`) | **No** | Deliberately transient, hard-deleted within 72 h (ADR-010). Backing them up would defeat the retention promise the privacy policy makes |
| Redis | No | Queue state and cache. A lost queue means some in-flight shares need retrying; the scheduler's sweeps reconcile the rest |
| Meilisearch | No | A search index rebuilt from Postgres |

## 2. `APP_KEY` is not a config value, it is a key

It encrypts `two_factor_secret` and `two_factor_recovery_codes`. **A database
restored without the matching `APP_KEY` locks every 2FA user out of their own
account permanently** — the recovery codes are encrypted with the same key, so
there is no way back in through the front door.

Store it in a password manager or a secrets service, **not only** in the `.env`
on the server the backup is protecting you from losing.

## 3. Taking a backup

Managed Postgres (Forge, RDS, Neon) has automated snapshots — turn them on and
know the retention window. In addition, take a logical dump you can actually
inspect and restore selectively:

```bash
pg_dump --format=custom --no-owner --no-acl \
        --file="reelmap-$(date -u +%Y%m%dT%H%M%SZ).dump" \
        "$DATABASE_URL"
```

`--format=custom` because it supports `pg_restore --table` and parallel restore;
a plain SQL dump is all-or-nothing and slow to restore at any real size.

**Do not `pg_dump --schema-only` and call it a backup.** The PostGIS geometry
columns and the extensions are schema, but the app is the rows.

## 4. Restoring — the drill to actually run

Run this against a **scratch database**, not production. That is the point: the
purpose of a restore drill is to discover the surprises somewhere harmless.

```bash
# 1. A fresh database WITH PostGIS. This is the step that bites: a dump does not
#    reliably carry extensions, and a restore into a plain Postgres fails on the
#    first geometry column — or worse, appears to succeed with the spatial
#    indexes missing, and the map is simply slow forever.
createdb reelmap_restore_test
psql -d reelmap_restore_test -c 'CREATE EXTENSION IF NOT EXISTS postgis;'
psql -d reelmap_restore_test -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;'
psql -d reelmap_restore_test -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'
psql -d reelmap_restore_test -c 'CREATE EXTENSION IF NOT EXISTS citext;'

# 2. Restore.
pg_restore --no-owner --no-acl --jobs=4 \
           --dbname=reelmap_restore_test reelmap-<stamp>.dump

# 3. Verify the SPATIAL layer specifically, not just the row counts. A restore
#    that lost places_location_gist returns correct results and seq-scans
#    forever — see docs/load-testing.md for why that is invisible until it is
#    not.
psql -d reelmap_restore_test -c "select postgis_version();"
psql -d reelmap_restore_test -c "select indexname from pg_indexes where tablename='places';"
psql -d reelmap_restore_test -c "select count(*) from places where location is not null;"

# 4. Point the app at it, with the ORIGINAL APP_KEY, and check the app agrees.
DB_DATABASE=reelmap_restore_test php artisan about
DB_DATABASE=reelmap_restore_test php artisan migrate:status   # no pending migrations
DB_DATABASE=reelmap_restore_test php artisan reelmap:ledger:verify   # the books balance
```

Step 4's last line is the one worth having: `reelmap:ledger:verify` asserts the
double-entry ledger balances (T-044). A restore that silently truncated a table
shows up there rather than in a support ticket three weeks later.

## 5. What a restore does not bring back

- **Media originals**, by design (ADR-010). A share mid-pipeline at backup time
  cannot be re-analysed from the restore; it has to be re-shared.
- **In-flight queue jobs.** Anything that was running is gone. The hourly sweeps
  (`gdpr:sweep-deletions`, `reviews:publish-abandoned`, `redemptions:expire`)
  reconcile most of it; shares stuck in a non-terminal state need
  `ForceReprocessShare` from the admin panel.
- **Anything after the snapshot.** Know the RPO your snapshot schedule implies
  and write it down here once it exists.

## 6. The restore drill log

**Nothing here yet.** Record each drill — date, dump age, restore duration,
what broke:

| Date | Dump age | Restore time | Findings |
|---|---|---|---|
| _(not yet run — T-055 is not complete until this table has a row)_ | | | |
