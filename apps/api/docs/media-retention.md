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
- **Analyze-then-delete (ADR-010):** a scheduled command hard-deletes originals
  ≤ 72 h after analysis (ships M5 / T-050). Derived keyframes/thumbnails persist.
- **Defense-in-depth:** configure an **R2 lifecycle rule** expiring the
  `originals/` prefix at 72 h, so nothing lingers even if the job fails.

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
