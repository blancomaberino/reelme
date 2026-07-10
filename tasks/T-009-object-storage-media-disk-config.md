# T-009 — Object storage (S3/R2) + media disk config

- **Phase:** M0 · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-002
- **Target paths:** `apps/api/config/filesystems.php`
- **Spec refs:** [01-architecture.md#system-components](../01-architecture.md#system-components), [07-risks-decisions.md#media-retention](../07-risks-decisions.md#media-retention)

## Context
M1's media pipeline (T-012 ManualUploadAdapter, T-017 DownloadMedia/PrepareMedia) stores originals, keyframes, and thumbnails on Cloudflare R2 via the S3 driver (01-architecture: buckets `reelmap-staging` / `reelmap-prod`; presigned URLs for direct mobile uploads). ADR-010 mandates analyze-then-delete for originals (≤72 h) while keyframes/thumbnails persist. This task, in the app repo (not this plans folder), configures the disks, a local dev fallback, and proves signed-URL generation — so M1 jobs only ever talk to the `media` disk abstraction.

## Implementation steps
1. `composer require league/flysystem-aws-s3-v3` (resolve latest stable at install time; the AWS SDK it pulls speaks R2's S3 API).
2. In `config/filesystems.php` add two disks (both S3 driver, same credentials, different roots/visibility semantics per ADR-010):
   ```php
   'media' => [                       // derived, long-lived: keyframes, thumbnails, avatars
       'driver' => 's3',
       'key' => env('MEDIA_ACCESS_KEY_ID'), 'secret' => env('MEDIA_SECRET_ACCESS_KEY'),
       'region' => env('MEDIA_REGION', 'auto'),
       'bucket' => env('MEDIA_BUCKET'),
       'endpoint' => env('MEDIA_ENDPOINT'),           // https://<account>.r2.cloudflarestorage.com
       'use_path_style_endpoint' => true,
       'root' => 'derived',
       'throw' => true,
   ],
   'media_originals' => [             // transient originals + screen recordings, ≤72h (ADR-010)
       /* same, 'root' => 'originals' */
   ],
   ```
   Config key `media.disk` pattern: add `config/media.php` with `'disk' => env('MEDIA_DISK', 'media')`, `'originals_disk' => env('MEDIA_ORIGINALS_DISK', 'media_originals')` so all pipeline code resolves disks via config, never hardcoded names.
3. **Local fallback for dev**: when `MEDIA_DISK=local_media`, define `local_media` / `local_media_originals` disks on the `local` driver (`storage_path('app/media')` etc.) so developers need no R2 account. `.env.example` documents both modes; default is the local fallback. Optionally note MinIO under Sail as an S3-compatible alternative.
4. **Temporary (signed) URLs**: R2 supports S3 presigned GET/PUT.
   - Read: `Storage::disk(config('media.disk'))->temporaryUrl($path, now()->addMinutes(30))`.
   - Upload (for T-012/T-025 presigned mobile uploads): `temporaryUploadUrl($path, now()->addMinutes(15))`.
   - The `local` driver cannot presign; enable Laravel's local temporary-URL support (`'serve' => true` on the local disks, framework signed routes) or wrap in a small `MediaUrlService` that returns a signed local route in dev and a presigned URL on s3 — pipeline code calls the service, not Storage directly.
5. Path conventions (document in `config/media.php` comments; T-017 relies on them): originals `media/{share_id}/original/{sha256}.{ext}` on the originals disk; derived `media/{share_id}/frames/frame_{index}_{ms}.jpg`, `.../thumb.jpg`, `.../audio.wav` on the media disk.
6. **Lifecycle note** (explicit acceptance item): add `apps/api/docs/media-retention.md` (or a README section) stating: originals bucket/prefix holds fetched videos + user screen recordings for AI analysis only, hard-deleted ≤72 h after analysis by a scheduled command (ships M5, T-050 — reference ADR-010); derived keyframes/thumbnails are kept and always served via signed URLs (NFR-8); the app never streams stored originals — display is embed/link-out to the source post. Recommend configuring an R2 lifecycle rule on the `originals/` prefix (72 h expiry) as defense-in-depth.
7. Pest tests (no network — use `Storage::fake`):
   - `Storage::fake('media')` write/read round-trip with the path conventions.
   - Signed URL test: on the fake/local disk assert `MediaUrlService` returns a URL containing a signature/expiry; plus a unit test that the s3 branch calls `temporaryUrl` (mock `FilesystemAdapter`).
   - Config test: `media` and `media_originals` disks exist, s3 driver, `throw => true`.
8. Manual smoke against a real R2 bucket once (staging credentials): upload a file, fetch via presigned URL, PUT via `temporaryUploadUrl` with curl. Record the commands in the docs file.

## Acceptance criteria
- [ ] `media` and `media_originals` disks configured on the s3 driver with R2 endpoint envs (`MEDIA_ENDPOINT`, `MEDIA_BUCKET`, keys, `region=auto`), plus local-driver fallback disks selected via `MEDIA_DISK` for dev; all documented in `.env.example`.
- [ ] Pipeline-facing indirection exists (`config/media.php` + `MediaUrlService`); no code references disk names inline.
- [ ] Signed URL generation works and is tested: presigned GET (temporaryUrl) and presigned upload (temporaryUploadUrl) on s3; signed local route in dev; Pest tests green without network.
- [ ] Lifecycle note committed: originals (transient, ≤72 h, ADR-010 analyze-then-delete) vs derived keyframes/thumbnails (retained), including the R2 lifecycle-rule recommendation and the "serve via signed URLs / never stream originals" rule.
- [ ] Path conventions for originals/frames/thumbnail/audio documented for T-017.

## Verification
```bash
cd apps/api
composer test -- --filter=Media
# one-time real-R2 smoke (staging creds in .env):
php artisan tinker --execute="
  Storage::disk('media')->put('smoke/hello.txt','hi');
  echo Storage::disk('media')->temporaryUrl('smoke/hello.txt', now()->addMinutes(5));"
curl -s "<printed url>"        # → hi
```

## Gotchas
- R2 requires `region => 'auto'` (or `us-east-1`) and the account-scoped endpoint; without `use_path_style_endpoint => true` some SDK versions build wrong hosts.
- R2 does NOT support S3 object ACLs — never set `'visibility' => 'public'` on these disks (writes fail or warn); everything is private + signed URLs, which also matches NFR-8.
- `temporaryUrl` on the `local` driver throws unless local temporary URL serving is configured — hence the `MediaUrlService` seam; don't let M1 jobs branch on driver themselves.
- Keep originals and derived under distinct roots/prefixes from day one — the M5 deletion job and the R2 lifecycle rule key off the prefix; retrofitting paths after real data exists is painful.
- Secrets: R2 keys only in `.env`/secret manager (NFR-8), placeholders + comments in `.env.example`, never committed.
- `throw => true` makes Storage failures raise exceptions instead of returning false — the queue jobs' retry/failure taxonomy depends on exceptions, keep it on.

## Log
- **2026-07-09** — Done. **PR #5** (`feat/t009-media-storage` → `feat/t008-filament`, stacked; last of the M0 backend stack). `league/flysystem-aws-s3-v3 ^3.35`. All gates green: `composer test` 50 passing / 124 assertions (10 media tests), Pint (71 files), PHPStan L6.
- **Implementation notes**:
  - Disks: `media` + `media_originals` (s3/R2, private, throw, roots derived/originals); `local_media` + `local_media_originals` dev fallback. **Each served local disk needs a distinct `url`** or Laravel's serve-route registration collides at `/storage` (hit this — gave them `/media-derived` and `/media-originals`).
  - `MediaUrlService` seam: `temporaryUrl` uniform (local `serve=true` signs a route); `temporaryUploadUrl` branches R2 presign vs signed local route. `MediaPaths` static builders are the T-017 path contract.
  - Local upload route lives at **`/api/media/upload`** (routes/api.php gets the `/api` prefix), `signed` middleware, **registered only outside production**, and **restricted to the configured media disks** (not any local-driver disk).
  - **/security-review** (no Critical/High): traversal blocked by Flysystem normalizer, arbitrary-disk by signature (both verified in vendor). Applied Finding 1 (Medium): disk allowlist + non-prod route gate; + Low hardening (Content-Length cap, explicit traversal reject). **/simplify**: dev handler → 204, trimmed duplicated path docs.
  - **Not done** (needs real creds, per brief step 8): one-time smoke against a live R2 bucket — commands recorded in `docs/media-retention.md` for whoever has staging credentials.
