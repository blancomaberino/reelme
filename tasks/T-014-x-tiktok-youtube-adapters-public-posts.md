# T-014 — X, TikTok, YouTube adapters (public posts)

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-012
- **Target paths:** `apps/api/app/Adapters/`
- **Spec refs:** [01-architecture.md#per-platform-ingestion](../01-architecture.md), [04-analysis-pipeline.md#sourceadapter-contract](../04-analysis-pipeline.md)

## Context

With the `SourceAdapter` contract, registry, and `ManualUploadAdapter` in place (T-012) — and `YtDlpFetcher` reusable from T-013 if it landed first — this task adds the remaining three platform adapters so any public post URL from X, TikTok, or YouTube resolves caption/author/media. Each follows the same shape as Instagram: official oEmbed/API for metadata, yt-dlp for media, graceful `NeedsManualFallback` at chain end. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

If T-013 has not shipped yet, create `app/Adapters/Support/YtDlpFetcher.php` here (Process-based wrapper: `-J --no-download` for metadata, `-f "mp4/best" -o {tmp}/...` for download, 120 s timeout, gated on `INGEST_YTDLP_ENABLED`) and T-013 reuses it.

## Implementation steps

1. **`XAdapter`** (`app/Adapters/XAdapter.php`) — chain per 01 §5: oEmbed → yt-dlp → manual.
   - `supports()`: `x.com/{user}/status/{id}` and `twitter.com/...`; `external_id` = status id.
   - Metadata: `GET https://publish.x.com/oembed?url={url}&omit_script=1` (no auth for public posts). Caption = strip HTML from `html` blockquote text; `author_handle` from `author_url`, display name from `author_name`. Full payload → `raw`.
   - Media: yt-dlp (videos); no video found → return metadata-only result (image posts still analyzable via caption).
   - Note: the spec chain lists an X API v2 user-token step between oEmbed and yt-dlp — that is auth-dependent and deferred to T-015-style linking; leave a documented seam (strategy list) so it can slot in.
2. **`TikTokAdapter`** (`app/Adapters/TikTokAdapter.php`) — oEmbed → yt-dlp → manual.
   - `supports()`: `tiktok.com/@{user}/video/{id}`; also accept `vm.tiktok.com/{code}` and `tiktok.com/t/{code}` shortlinks (IngestShare expands them first, but tolerate both). `external_id` = numeric video id.
   - Metadata: `GET https://www.tiktok.com/oembed?url={url}` — `title` (caption), `author_unique_id`/`author_name`, `thumbnail_url`. No video file from oEmbed.
   - Media: yt-dlp download.
3. **`YouTubeAdapter`** (`app/Adapters/YouTubeAdapter.php`) — Data API/oEmbed → yt-dlp → manual (lowest ToS risk, official APIs preferred).
   - `supports()`: `youtube.com/watch?v=`, `youtu.be/{id}`, `youtube.com/shorts/{id}`; `external_id` = video id.
   - Metadata: if `services.youtube.api_key` set, `GET https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id={id}&key=...` (full description = caption, `channelTitle` = author, `publishedAt` = posted_at). Else fall back to `GET https://www.youtube.com/oembed?url={url}&format=json` (title/author only).
   - Media: yt-dlp (Shorts included).
4. **Shared behavior** (identical to T-013 conventions): 404/410/private → `PostUnavailable` (`fetch_unavailable`); 429/5xx/timeout → `FetchFailed` with `retryAfter` from `Retry-After`; all strategies exhausted → `NeedsManualFallback`; `requiresAuth()` → `false`; never invent captions — missing fields are `null`.
5. **Registry wiring**: add each adapter to its platform chain in `config/ingestion.php` (`x`, `tiktok`, `youtube`), each terminating in `ManualUploadAdapter`.
6. **Fixture tests per platform** (`tests/Feature/Adapters/{X,TikTok,YouTube}AdapterTest.php`, fixtures under `tests/Fixtures/{x,tiktok,youtube}/`), no live network:
   - `Http::fake` per host (`publish.x.com/*`, `www.tiktok.com/oembed*`, `googleapis.com/youtube/*`, `www.youtube.com/oembed*`); `Process::fake` for yt-dlp.
   - Per platform: happy metadata parse (caption + handle + external_id assertions); metadata endpoint down → yt-dlp metadata; everything down → `NeedsManualFallback`; `supports()` URL matrix (positive + negative, shortlink forms); YouTube: API-key path vs oEmbed path both covered.

## Acceptance criteria

- [ ] `XAdapter`, `TikTokAdapter`, `YouTubeAdapter` each implement `App\Adapters\SourceAdapter` and resolve caption, author handle/display name, and media descriptors for public post URLs via oEmbed (or YouTube Data API v3) with yt-dlp for media.
- [ ] Every adapter degrades gracefully: exhausted chain raises `NeedsManualFallback` (never an unhandled exception, never a dead-ended share).
- [ ] `supports()` correctly matches each platform's URL shapes (including `twitter.com`, `youtu.be`, `/shorts/`, `vm.tiktok.com`) and rejects other platforms' URLs.
- [ ] Registry chains for `x`, `tiktok`, `youtube` configured in `config/ingestion.php`, each ending in `ManualUploadAdapter`.
- [ ] Fixture tests per platform pass with `Http::fake` + `Process::fake`; CI makes zero network calls and needs no yt-dlp binary.
- [ ] Transient vs permanent failures map to `FetchFailed` / `PostUnavailable` with the 04 §8 failure codes, asserted in tests.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan test --filter=XAdapter
php artisan test --filter=TikTokAdapter
php artisan test --filter=YouTubeAdapter
php artisan tinker --execute="
  \$r = app(\App\Adapters\AdapterRegistry::class);
  foreach (['https://x.com/food/status/17890','https://www.tiktok.com/@chef/video/7300000000000000000','https://youtube.com/shorts/dQw4w9WgXcQ'] as \$u) {
    echo \$u, ' => ', \$r->platformFor(\$u)?->value, PHP_EOL;
  }
"
# expect: x, tiktok, youtube
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Gotchas

- **X oEmbed** returns the tweet text wrapped in HTML (`<blockquote>…<p>text</p>`); strip tags carefully and decode entities — don't persist HTML into `source_posts.caption`. publish.x.com is unauthenticated but unstable (07 R-01: "unofficial endpoints unstable") — treat any non-200 as `FetchFailed`, not a crash.
- **TikTok oEmbed `title` is the caption but often empty** for some videos; null is correct. Shortlink hosts (`vm.tiktok.com`) 301-redirect — `supports()` must match them even though IngestShare normally expands first.
- **YouTube Data API quota** is generous but keyed: missing `YOUTUBE_API_KEY` must silently use oEmbed, not error. Descriptions can be huge — no truncation (DB `caption` is `text`).
- **yt-dlp on CI**: always `Process::fake`; binary only on servers (T-055). One shared `YtDlpFetcher` — don't fork per-platform copies; coordinate with T-013 on who creates it.
- Keep per-platform kill switches: reuse the pattern `config('ingestion.platforms.{x}.enabled')` so any platform can be force-downgraded to manual-only without deploy (01 §5 operational rules).
- Posted-at parsing differs (X oEmbed has none; TikTok none; YouTube ISO 8601) — `posted_at` is nullable, leave null when absent.
- oEmbed responses belong in `raw` so `FetchSourcePost` can persist them to `source_posts.oembed_json` — don't discard.

## Log

- 2026-07-20 (PR #115, branch `feat/T-014-x-tiktok-youtube-adapters`): implemented dedicated `XAdapter`, `TikTokAdapter`, `YouTubeAdapter` + shared `App\Adapters\Support\FetchesOEmbed` trait (HTTP client, §8 failure mapping, suffix-anchored `hostMatches`). Wired the `x`/`tiktok`/`youtube` chains in `config/ingestion.php` (each `[Adapter, YtDlpAdapter]` → manual fallback); added `services.youtube.api_key`. X media via yt-dlp (added `x.com`/`twitter.com` to `YtDlpAdapter::SUPPORTED_HOSTS`).
  - **Kill switches** (`ingestion.platforms.{x,tiktok,youtube}.enabled`) enforced in `AdapterRegistry::resolve()` — a disabled platform skips the WHOLE chain (metadata + yt-dlp) → manual-only; unconfigured platforms default enabled (instagram gets one for free). This altitude fix came out of `/simplify`: the first cut gated each adapter's `supports()`, which left yt-dlp still scraping a "disabled" platform.
  - Instagram still uses the generic `OEmbedAdapter`. **Deferred follow-up:** narrow/rename `OEmbedAdapter` → `InstagramAdapter` and fold it onto `FetchesOEmbed` (dead youtube/tiktok PROVIDERS entries; ~30 duplicated lines). Out of T-014 scope.
  - Tests: per-platform fixture tests (`Http::fake`, no network/binary) — supports() matrix, happy parse, Data-API vs oEmbed, failure taxonomy, connection errors; registry tests for the kill switch. 100% coverage on the new adapters + trait. `/coderabbit` pass clean (grounding, `/simplify` applied, `/security-review` no findings). Pint/PHPStan green; Pest 792 passed.
  - Also flipped **T-013 → done** in tasks.json (it shipped earlier via PRs #78/#82/#83; status had never been updated). T-014 stays `in_progress` until PR #115 merges.
