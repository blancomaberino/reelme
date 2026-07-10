# T-017 — DownloadMedia + PrepareMedia jobs (ffmpeg keyframes, audio extract)

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-016, T-009
- **Target paths:** `apps/api/app/Jobs/DownloadMedia.php`, `apps/api/app/Jobs/PrepareMedia.php`, `apps/api/app/Services/Media/`
- **Spec refs:** [04-analysis-pipeline.md](../04-analysis-pipeline.md) §1 (stage table + PrepareMedia notes)

## Context

T-016 left `DownloadMedia` and `PrepareMedia` as pass-through stubs in the share chain; T-009 configured the `media` disk (R2/S3, local fallback). This task makes them real: pull the adapter-resolved media onto object storage, then derive the AI inputs — 16 kHz mono WAV audio, ≤12 scene-detected keyframes, and a thumbnail — as `media_assets` rows. TranscribeAudio (T-018) and ExtractPlaceData (T-021) consume these outputs. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

## Implementation steps

1. **`DownloadMedia`** (`apps/api/app/Jobs/DownloadMedia.php`; queue **ingest** — spec's `fetch` queue maps to `ingest`; tries 3, backoff `[60, 300, 900]`, timeout 600):
   - Status guard (`fetching`), idempotency: if a `media_assets` row `kind: video` (or `screen_recording` for manual shares) already exists for the source_post, return early.
   - Get `MediaFetchResult` from the first chain adapter that returns media (registry from T-012; metadata and media adapters may differ). For URL results: **stream** to R2 — `Http::sink($tmp)` or a guzzle stream copied via `Storage::disk('media')->writeStream()`, never `file_get_contents` (04: "streams, never buffers whole file in memory"). For yt-dlp `localPath` results: `writeStream` from the temp file, then delete it.
   - Enforce caps before/while writing: `Content-Length`/stream position > **500 MB** → abort with failure code `media_too_large`; ffprobe duration > **15 min** → same.
   - Compute **sha256** while streaming (`hash_init`/`hash_update` on chunks). If an asset with this sha256 already exists on the same source_post (unique `sha256`,`source_post_id`), skip the upload (dedup, re-run safe). Store path `media/{share_id}/original/{sha256}.mp4`.
   - Create `media_assets` row: `kind: video`, `disk: media` (config default `'s3'` per 02 §3.6), `mime`, `bytes`, `sha256`, plus `duration_ms`/`width`/`height` via ffprobe (`ffprobe -v quiet -print_format json -show_format -show_streams`).
2. **`MediaProcessor` service** (`apps/api/app/Services/Media/MediaProcessor.php`, plus `FfmpegRunner` wrapping `Illuminate\Support\Facades\Process` with the job timeout). ffmpeg command shapes per 04 §1 PrepareMedia notes:
   - Audio: `ffmpeg -i in.mp4 -vn -ac 1 -ar 16000 -c:a pcm_s16le audio.wav`. First probe for an audio stream; none → **no WAV asset**, write `no_audio: true` marker (e.g. on the video asset's metadata or by absence — document choice) so TranscribeAudio no-ops.
   - Keyframes: scene detection `-vf "select='gt(scene,0.3)',scale='min(1024,iw)':-2" -vsync vfr -q:v 3 frame_%02d.jpg` targeting ~1 frame / 2–3 s, **hard cap 12**; if scene detection yields <4 frames, fall back to uniform sampling `fps=1/(duration/8)`. JPEG q≈85, longest edge 1024 px. Capture each frame's timestamp (parse `showinfo` pts or compute from sampling rate); name `frame_{index}_{ms}.jpg` — the **index order is the `frame_refs` contract** for the extraction schema (0..11).
   - Thumbnail: sharpest of the first 3 keyframes by max Laplacian variance (imagick convolution or a tiny ffmpeg pass), resized to 640 px.
3. **`PrepareMedia`** (`apps/api/app/Jobs/PrepareMedia.php`; queue **media**; tries 2, backoff `[120, 600]`, timeout 600):
   - Status guard (`fetching`), idempotency: keyframe assets already exist → return early.
   - Download the original from R2 to a temp dir (workers are stateless), run `MediaProcessor`, then upload derivatives to `media/{share_id}/derived/…` and insert `media_assets` rows: one `audio` (WAV), N× `keyframe` (with `frame_at_ms`, `width`, `height`), one `thumbnail`. Every row gets its own `sha256` (dedup via the unique index — `insertOrIgnore`/upsert on conflict). Clean temp dir in `finally`.
   - ffmpeg non-zero exit → failure code `ffmpeg_error` (mapped to `shares.failure_reason` by the `failed()` hook). Horizon tags `share:{id}`, `stage:prepare_media`.
4. **Fixture video**: commit a tiny test clip `tests/Fixtures/media/sample.mp4` (~5 s, ~200 KB, with an audio track; generate once via `ffmpeg -f lavfi -i testsrc=duration=5:size=320x240:rate=10 -f lavfi -i sine=frequency=440:duration=5 -shortest sample.mp4`) and a silent variant `sample_noaudio.mp4`.
5. **Tests** (`tests/Feature/Jobs/`, group `ffmpeg` for the ones executing the real binary — CI installs ffmpeg via apt in the workflow from T-006):
   - `DownloadMedia`: `Storage::fake('media')` + `Http::fake` streaming a fixture → asset row with correct sha256/bytes/mime; re-run creates no duplicate (idempotent); oversize `Content-Length` → share `failed` with `media_too_large`; sha256 dedup skips re-upload.
   - `PrepareMedia` (real ffmpeg on fixture): produces 1 audio WAV (16 kHz mono — assert via ffprobe), ≥1 and ≤12 keyframes with monotonically increasing `frame_at_ms`, 1 thumbnail; silent fixture → no audio asset + no failure; re-run adds no duplicate rows; corrupt input (`echo garbage > bad.mp4`) → `ffmpeg_error`.

## Acceptance criteria

- [ ] ffmpeg scene-detection keyframes (hard cap 12, uniform-sampling fallback when <4 scenes), 640 px thumbnail, and 16 kHz mono PCM WAV are produced and stored as `media_assets` rows with correct `kind`, `frame_at_ms`, dimensions, and `sha256`.
- [ ] `DownloadMedia` streams to the `media` disk (no full-file buffering), enforces 500 MB / 15 min caps with `media_too_large`, and records ffprobe metadata on the video asset.
- [ ] sha256 dedup: identical media bytes never stored or row-duplicated twice for a source_post; unique(`sha256`,`source_post_id`) never violated on re-run.
- [ ] Both jobs are idempotent (early-return on existing output) and status-guarded; failures set `shares.failure_reason` to `media_too_large`/`ffmpeg_error` via the `failed()` hook.
- [ ] Jobs run on the correct queues (`ingest` for DownloadMedia, `media` for PrepareMedia) with Horizon tags per stage.
- [ ] Fixture-video tests pass, including the silent-video and corrupt-input paths; no live network in CI.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
which ffmpeg ffprobe   # required locally and in CI image
php artisan test --filter=DownloadMedia
php artisan test --filter=PrepareMedia
php artisan tinker --execute="
  \$sp = \App\Models\SourcePost::first();
  \$sp->mediaAssets()->get(['kind','frame_at_ms','bytes'])->each(fn(\$a) => print(\$a->kind->value.' @'.\$a->frame_at_ms.' '.\$a->bytes.PHP_EOL));
"
# expect after a pipeline run: video, audio, keyframe ×N (ascending frame_at_ms), thumbnail
```

## Gotchas

- **ffmpeg/ffprobe on CI**: install in the workflow (`sudo apt-get install -y ffmpeg`); make binary paths configurable (`config('media.ffmpeg_bin')`). Tag real-binary tests `->group('ffmpeg')` so they're skippable locally if absent — but they MUST run in CI.
- Scene-detect `select` can emit **zero** frames on static-shot videos — the <4 fallback is mandatory, not cosmetic. And it can emit a burst — enforce the 12-cap in PHP after extraction, not just via ffmpeg args.
- `frame_refs` in the extraction schema are indexes 0–11 in prompt order: keyframe **index ↔ frame_at_ms ordering must be stable** (sort by `frame_at_ms` before naming/inserting). Getting this wrong silently corrupts evidence links in T-021.
- R2/S3 `writeStream` needs the flysystem S3 v3 adapter (T-009); local fallback disk must behave identically in tests via `Storage::fake('media')`.
- Workers are stateless: never assume DownloadMedia's temp file survives to PrepareMedia — PrepareMedia re-downloads from the disk. Budget the 600 s timeout accordingly.
- WAV output is uncompressed (~2 MB/min at 16 kHz mono) — fine, but store under `derived/`, and remember originals are transient per ADR-010 (retention enforcement is T-050; just keep originals/derived in separate prefixes now).
- Manual-fallback shares have `kind: screen_recording` as the "original" — PrepareMedia must accept either `video` or `screen_recording` as input.
- `Process` output for ffmpeg goes to stderr even on success; check exit code, not output presence.
