# T-074 — yt-dlp video SourceAdapter: real frames (+ audio) for video posts

- **Phase:** M2 (analysis pipeline) · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-013 (media pipeline), T-057 (ingestion hardening)
- **Target paths:** `apps/api/app/Adapters/YtDlpAdapter.php` (new), `apps/api/app/Adapters/AdapterRegistry.php`, `apps/api/app/Jobs/DownloadMedia.php`, `apps/api/config/ingestion.php`, `apps/api/app/Providers/IngestionServiceProvider.php`, `scripts/dev.sh`
- **Spec refs:** `04-analysis-pipeline.md`

## Context

Requested 2026-07-16, **superseding closed PR #80**. The goal is the one we
discussed: use **yt-dlp as a backup to access the full content of a post**.

Empirically verified (latest yt-dlp nightly + a valid cookie, run live against
Instagram):

- **Image / carousel posts** → yt-dlp authenticates and enumerates the slides
  but then errors `No video formats found!` on every one, returning **zero image
  URLs**. yt-dlp's Instagram extractor only ever yields *video*. So a yt-dlp
  *image* resolver (what PR #80 added to the `image_resolvers` chain) **cannot
  work for Instagram** — that PR was closed as superseded/wrong-layer. Image
  posts are already fully covered by `InstagramApiResolver` (IG's web media API,
  reads every carousel slide — #82) with the oEmbed hero thumbnail as fallback.
- **Video reels** → yt-dlp downloads cleanly (`/reel/…` → mp4, full formats,
  duration/codec present). **This is where yt-dlp belongs.**

### The real gap (why this task)

Today a **video** post is effectively analysed **caption-only**: the media
**adapter** layer is scaffolded for a yt-dlp video fetcher but the fetcher was
never written —

- `OEmbedAdapter::fetchMedia()` is intentionally **empty** ("Real media needs
  yt-dlp (T-013+); the pipeline runs text-only on the caption").
- `FetchedMedia` documents "yt-dlp adapters return a `localPath`"; `SourceAdapter`
  notes "local temp paths for yt-dlp".
- `DownloadMedia` already handles a yt-dlp **local temp file** (uses it as-is,
  and only cleans its own temp — the adapter owns the yt-dlp file).

So `DownloadMedia::firstDownloadable()` iterates the registry, finds no adapter
that returns video, returns `null` → "nothing to download" → `PrepareMedia` has
no video original → it falls to the image resolvers. Net: a reel never yields
**real scene keyframes or audio** — only the caption (and maybe a hero frame).

### Already-available (don't re-build)

- **`DownloadMedia`** already stores a returned `FetchedMedia` as a `Video`
  media_asset and cleans temps correctly (incl. the yt-dlp-owns-its-file case).
- **`PrepareMedia`** already ffmpeg-extracts ≤12 scene keyframes + a poster from
  a `Video` original, idempotently. So once an adapter *produces* the video,
  frame extraction is solved.
- **yt-dlp binary + cookie plumbing** exist (`dev.sh` installs the binary; the
  image resolvers already read `INGESTION_IG_*` cookies). Reuse, don't duplicate.
- The **argument-injection / no-throw safety pattern** is already proven in
  `InstagramApiResolver` / the (closed) `YtDlpResolver`: array command (no shell),
  `^https?://` URL guard + `--` end-of-options, wrap `Process` in
  `try/catch(\Throwable)→[]`, `allow_redirects` off equivalents. Mirror it.

## Implementation

- **`YtDlpAdapter implements SourceAdapter`** — `fetchMedia()` runs
  `yt-dlp -o <temp> --no-playlist [--cookies …] -- <url>` (download best video),
  returns `FetchedMedia(localPath: <temp>, kind: Video)`. Returns an empty result
  (not throw) when: yt-dlp missing, non-zero exit, `No video formats found!`
  (image post — skip cleanly), timeout (`ProcessTimedOutException`), or disabled.
  Config-gated (`INGESTION_YTDLP_*`: enabled/bin/timeout/cookies_path), bound with
  primitive ctor args in `IngestionServiceProvider`.
- **Register** it in `AdapterRegistry` for the platforms yt-dlp supports
  (Instagram/TikTok/YouTube), ordered so a real video wins and the caption-only
  path remains the graceful fallback. Confirm `DownloadMedia` needs no change
  beyond the adapter returning a `localPath` (it already consumes that).
- **Prod:** bake the yt-dlp binary into the API image (dev.sh already installs it
  in the container). Note the IG ToS/cookie-expiry caveat (a 4xx = refresh cookie).
- **(stretch) audio → transcription.** Extract the reel's audio track (ffmpeg
  already available) and feed a transcript as an additional analysis input — a
  reel's spoken menu/venue names are often not in the caption. Can split to its
  own task if it grows.

## Acceptance criteria

- [ ] A `YtDlpAdapter` downloads a reel's video to a temp file and returns
      `FetchedMedia(localPath=…, kind=Video)`, registered in `AdapterRegistry`
      ahead of the caption-only fallback
- [ ] A **video** share now reaches **real ffmpeg keyframes** (not caption-only):
      `DownloadMedia` stores the Video asset, `PrepareMedia` extracts scene frames
- [ ] Safe invocation: array command (no shell), `^https?://` + `--` guard,
      `try/catch(\Throwable)→[]` (never throws), cookies via config; **image
      posts** (`No video formats found!`) and missing/auth-walled yt-dlp fall
      through cleanly to the existing path
- [ ] Config-gated (`INGESTION_YTDLP_*`), documented in `.env.example`; prod bakes
      the binary into the image
- [ ] Tests (`Process::fake`): reel→video localPath; image-post no-video→fall-
      through; timeout / exit≠0 → `[]`; disabled / no-cookies; never-throws; a
      pipeline test that a video share reaches keyframes
- [ ] (stretch) reel audio extracted + transcribed as an extra analysis input
- [ ] Gates green: API Pint / PHPStan L6 / Pest

## Progress

- **2026-07-16** — Opened, superseding closed PR #80 (which mis-scoped yt-dlp as
  an image resolver; verified yt-dlp cannot read IG images). Grounded against the
  current adapter scaffolding — the missing piece is the `YtDlpAdapter` video
  fetcher; frame extraction downstream is already built.
