# T-013 — Instagram adapter: oEmbed + yt-dlp fetcher for public posts

- **Phase:** M1 · **Estimate:** L · **Status:** see tasks.json
- **Depends on:** T-012
- **Target paths:** `apps/api/app/Adapters/InstagramAdapter.php`
- **Spec refs:** [04-analysis-pipeline.md#sourceadapter-contract](../04-analysis-pipeline.md), [01-architecture.md#per-platform-ingestion](../01-architecture.md), [07-risks-decisions.md](../07-risks-decisions.md)

## Context

T-012 shipped the `SourceAdapter` contract, `AdapterRegistry`, exception taxonomy, and `ManualUploadAdapter`. Instagram is the flagship platform for M1's exit criterion ("a public Instagram reel URL produces a confirmed place entry end-to-end"), and the highest ToS-risk one (07 R-01): oEmbed is official but caption/thumbnail-only; yt-dlp gets media but is a gray zone and must sit behind a kill switch. This task implements the public-post path; the Graph API strategy for private posts arrives with OAuth in T-015. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

## Implementation steps

1. **`InstagramAdapter`** at `apps/api/app/Adapters/InstagramAdapter.php` implementing `App\Adapters\SourceAdapter`. Internally it composes two strategies (small classes under `app/Adapters/Instagram/`): `InstagramOEmbedClient` and `YtDlpFetcher` (the latter platform-agnostic in `app/Adapters/Support/YtDlpFetcher.php` so T-014 reuses it).
2. **`supports()`**: regex on canonical URL — `instagram.com/(reel|p|tv)/{shortcode}` (shortcode `[A-Za-z0-9_-]+`). Extract `external_id` = shortcode. `requiresAuth()` → `false`.
3. **`fetchMetadata()` — fallback chain per 04 §2 table** (oEmbed → yt-dlp metadata → `NeedsManualFallback`):
   1. **oEmbed**: `GET https://graph.facebook.com/{version}/instagram_oembed?url={url}&access_token={app_token}` via Laravel `Http` client (config `services.instagram.oembed_token`; Meta app-token, requires oEmbed Read app review — note in `.env.example`). Success → `SourcePostData` with `caption` (from `title`), `author_handle`/`author_display_name` (`author_name`), thumbnail as a `MediaDescriptor(type: image)`, full response into `raw` (persisted later to `source_posts.oembed_json`). oEmbed never yields a video URL — that's fine, metadata and media may come from different adapters.
   2. **yt-dlp metadata** (only if `config('ingestion.ytdlp_enabled')`, env `INGEST_YTDLP_ENABLED`, default `false` — the per-platform kill switch from 01 §5): run `yt-dlp -J --no-download {url}` via `Illuminate\Support\Facades\Process` with 120 s timeout; parse JSON for `description` (caption), `uploader`, `timestamp`, formats.
   3. Both failed → throw `NeedsManualFallback`.
   - HTTP 404/410 or oEmbed error subcode meaning deleted/private → `PostUnavailable` (advance chain; if final, manual fallback). 429/5xx/timeouts → `FetchFailed` with `retryAfter` from `Retry-After` header when present.
4. **`fetchMedia()`**: yt-dlp download path — `yt-dlp -f "mp4/best" -o {tmp}/%(id)s.%(ext)s {url}` (temp dir via `TemporaryDirectory` or `storage_path('app/tmp')`), returning `MediaFetchResult` with `localPath` set and `kind: MediaKind::Video`. The actual move/stream to the R2 `media` disk happens in `DownloadMedia` (T-017) — the adapter only produces temp paths/URLs. yt-dlp disabled or fails → if oEmbed gave a thumbnail, return image-only result; else throw `NeedsManualFallback`. Never pass cookies (04 §2: no cookies unless linked account + consent — that's T-015).
5. **Registry wiring**: add `InstagramAdapter` to `config/ingestion.php` chain for `instagram` (before `ManualUploadAdapter`).
6. **Failure taxonomy mapping** (04 §8 → `shares.failure_reason`): document and test — `PostUnavailable` → `fetch_unavailable`; private-post detection → `fetch_auth_required`; exhausted chain → manual fallback path (share → `review`, not `failed`).
7. **Fixture-based tests, no live network in CI** (`tests/Feature/Adapters/InstagramAdapterTest.php` + `tests/Fixtures/instagram/`):
   - Save real-shaped JSON fixtures: `oembed_success.json`, `oembed_not_found.json`, `ytdlp_info.json`. Use `Http::fake(['graph.facebook.com/*' => Http::response(fixture(...))])`.
   - Fake the binary with `Process::fake(['yt-dlp*' => Process::result(output: fixture('ytdlp_info.json'))])` — Laravel's Process facade makes CI independent of a yt-dlp install.
   - Cases: happy path oEmbed (caption + author extracted); oEmbed 404 → yt-dlp metadata used; both fail → `NeedsManualFallback`; 429 with `Retry-After: 300` → `FetchFailed` with `retryAfter === 300`; flag off → yt-dlp never invoked (`Process::assertNothingRan()` for the yt-dlp pattern); `fetchMedia` returns a temp path from faked download.

## Acceptance criteria

- [ ] Public reel URL resolves to caption + author handle via oEmbed, and video media via yt-dlp (temp path handed to DownloadMedia for storage) — end-to-end covered by fixture tests.
- [ ] Fallback chain implemented exactly: oEmbed → yt-dlp fetcher → raise `NeedsManualFallback`; yt-dlp gated behind `INGEST_YTDLP_ENABLED` (default off).
- [ ] `supports()` matches `/reel/`, `/p/`, `/tv/` URLs and extracts the shortcode as `external_id`; non-Instagram URLs return false.
- [ ] All tests are fixture-based (`Http::fake` + `Process::fake`); zero live HTTP or binary execution in CI.
- [ ] Failure taxonomy mapped and asserted: deleted/private → `fetch_unavailable` / `fetch_auth_required`; transient errors are `FetchFailed` carrying `Retry-After`.
- [ ] Raw oEmbed payload is preserved on `SourcePostData->raw` for persistence into `source_posts.oembed_json`.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan test --filter=InstagramAdapter
vendor/bin/pint --test && vendor/bin/phpstan analyse
# Local-only (not CI) smoke with a real public reel, yt-dlp installed:
INGEST_YTDLP_ENABLED=true php artisan tinker --execute="
  \$a = app(\App\Adapters\InstagramAdapter::class);
  \$m = \$a->fetchMetadata('https://www.instagram.com/reel/DAbC123xyz/', null);
  echo \$m->author_handle, ' | ', mb_substr((string)\$m->caption, 0, 60);
"
```

Expected: test suite green with `Http::fake`/`Process::fake` assertions; tinker smoke prints author + caption when run with network + binary available.

## Gotchas

- **yt-dlp binary on CI**: never require it — `Process::fake` in every test. Production/staging install it via provisioning (T-055); make the binary path configurable (`config('ingestion.ytdlp_bin', 'yt-dlp')`).
- **Meta oEmbed needs an app access token and app review** for oEmbed Read; until granted, the endpoint 400s — which the chain must treat as `FetchFailed`, gracefully landing on yt-dlp or manual. Don't hard-fail boot when the token is missing.
- **oEmbed rate limits**: app-wide, per app token. The `RateLimited` job middleware (`instagram: 30/min`, 04 §1) lives on `FetchSourcePost` (T-016), but the adapter must still surface 429 + `Retry-After` as `FetchFailed(retryAfter)` so the job can `release()` correctly.
- **Do not buffer media in memory or store it in the adapter** — return temp paths/URLs only; streaming to R2 and the 500 MB/15 min caps are DownloadMedia's contract (T-017). Clean up temp files on exception (`finally`).
- oEmbed `title` is often truncated or missing for reels; treat missing caption as `null`, never invent one (golden rule of the extraction contract).
- yt-dlp runs in a queue worker with a 120 s process timeout (04 §2 notes) — set both `Process::timeout(120)` and remember the enclosing job timeout (T-016's `FetchSourcePost` = 120 s).
- Instagram URL variants: `instagram.com/reels/` (plural) redirects, `?igsh=` tracking params — canonicalization is IngestShare's job, but `supports()` should tolerate `reels` and trailing params.
- ToS posture (07 R-01): keep yt-dlp default-off, no login-walled scraping, and the whole chain must degrade to manual without erroring the share.
