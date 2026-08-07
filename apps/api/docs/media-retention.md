# Media storage & retention

Media lives on two **private** disks (Cloudflare R2 via the S3 driver), resolved
through `config/media.php` + `App\Services\Media\MediaUrlService` — pipeline code
never names a disk or branches on driver.

| Disk | Purpose | Retention |
|------|---------|-----------|
| `media_originals` (root `originals/`) | Fetched source videos + user screen recordings, used **only** for AI analysis | **Transient — hard-deleted ≤ 72 h** after analysis (ADR-010) |
| `media` (root `derived/`) | Keyframes, thumbnails, avatars | **Retained** |

Dev uses `local_media` / `local_media_originals` (local driver) so no R2 account
is needed — selected via `MEDIA_DISK` (see `.env.example`).

## Rules

- **Everything is private and served via signed URLs** (NFR-8). R2 has no object
  ACLs — never set `visibility => public`. Read URLs come from
  `MediaUrlService::temporaryUrl()`, uploads from `temporaryUploadUrl()`.
- **The app never streams stored originals.** Display is embed / link-out to the
  original source post; originals exist only for the analysis pipeline.
- **Analyze-then-delete (ADR-010):** `reelmap:media:prune-originals` runs hourly
  and hard-deletes originals past their window (T-050). Derived
  keyframes/thumbnails persist.
- **Defense-in-depth:** configure an **R2 lifecycle rule** expiring the
  `originals/` prefix at 72 h, so nothing lingers even if the job fails.

## The retention commands (T-050)

| Command | Schedule | What it removes |
|---------|----------|-----------------|
| `reelmap:media:prune-originals` | hourly | `media_assets` of kind `video`/`audio`/`screen_recording` past their window — **object first, then the row** |
| `reelmap:sources:prune-payloads` | daily 04:10 | `source_posts.oembed_json` older than 90 days (NFR-11). The row and the transcript stay |
| `reelmap:gdpr:prune-exports` | daily 04:30 | data-export archives under `exports/` past `GDPR_EXPORT_RETENTION_DAYS` |

Tuning knobs (`config/media.php` → `retention`):

| Env | Default | Meaning |
|-----|---------|---------|
| `MEDIA_ORIGINAL_RETENTION_HOURS` | 72 | How long a **finished** share's original may live |
| `MEDIA_IN_FLIGHT_CEILING_HOURS` | 168 | Hard ceiling for a share still mid-pipeline |
| `MEDIA_OEMBED_RETENTION_DAYS` | 90 | Raw provider payload window |

Two clocks, not one. A share still `pending|fetching|analyzing|review` keeps its
original past the normal window so a retry has something to re-read — but only
until the ceiling, after which it goes anyway and a retry re-fetches (ADR-010
already requires a re-fetch to re-analyse). Without the ceiling, one permanently
wedged share pins a copy of somebody else's video forever, and "wedged" is
exactly the state nobody notices.

Both media commands take `--dry-run`. Deletion order is object-then-row on
purpose: a failed bucket call leaves the row for the next pass, whereas
deleting the row first would lose the only handle we have on the file.

> ⚠️ **The schedule only runs if `schedule:run` is on cron** (T-055). Without
> it every window above is infinite and the commands are documentation.

## Path conventions (`App\Services\Media\MediaPaths`)

```
originals disk : media/{share_id}/original/{sha256}.{ext}
media disk     : media/{share_id}/frames/frame_{index}_{ms}.jpg
                 media/{share_id}/thumb.jpg
                 media/{share_id}/audio.wav
```

Originals and derived live on **distinct disks/roots** so the deletion job and
the lifecycle rule key off the `originals` prefix — set from day one.

## One-time R2 smoke (staging credentials)

```bash
php artisan tinker --execute="
  Storage::disk('media')->put('smoke/hello.txt','hi');
  echo Storage::disk('media')->temporaryUrl('smoke/hello.txt', now()->addMinutes(5));"
curl -s "<printed url>"        # → hi

# presigned upload:
php artisan tinker --execute="
  echo json_encode(app(App\Services\Media\MediaUrlService::class)
    ->temporaryUploadUrl('smoke/put.txt', 'media'));"
curl -X PUT --data 'hi' "<printed url>"
```
