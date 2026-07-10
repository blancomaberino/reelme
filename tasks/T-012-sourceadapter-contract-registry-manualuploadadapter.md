# T-012 — SourceAdapter contract + registry + ManualUploadAdapter

- **Phase:** M1 · **Estimate:** M · **Status:** see tasks.json
- **Depends on:** T-011, T-009
- **Target paths:** `apps/api/app/Adapters/`
- **Spec refs:** [04-analysis-pipeline.md#sourceadapter-contract](../04-analysis-pipeline.md), [01-architecture.md](../01-architecture.md) §2/§5

## Context

T-011 created `source_posts`/`media_assets` and their models; T-009 configured the `media` storage disk (R2/S3 with local fallback). This task defines the ingestion abstraction every platform plugs into: the `SourceAdapter` interface, its DTOs and exception taxonomy, an `AdapterRegistry` that resolves the right adapter chain from a URL, and the guaranteed terminal fallback, `ManualUploadAdapter` (ADR-011: ingestion can never dead-end). T-013/T-014/T-015 add real platform adapters against this contract; T-016's `FetchSourcePost` job consumes the registry. Application code lives in the separate app repo created by T-001 (under `apps/api/`), NOT this plans folder.

## Implementation steps

1. **Interface** at `apps/api/app/Adapters/SourceAdapter.php`. Namespace note: the pipeline spec sketch uses `App\Ingestion`, but the architecture doc (§2, §6) and this task's target path fix the canonical location as `App\Adapters` — use `App\Adapters`. Method signatures exactly per 04 §2:
   ```php
   namespace App\Adapters;

   interface SourceAdapter
   {
       /** Fast, offline check — can this adapter handle the canonical URL? */
       public function supports(string $canonicalUrl): bool;

       /** @throws FetchFailed (transient) | PostUnavailable (permanent) */
       public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData;

       /** Resolved, short-lived direct media URLs (or local temp paths for yt-dlp). */
       public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult;

       /** True if this adapter can only work with a linked platform account. */
       public function requiresAuth(): bool;
   }
   ```
2. **DTOs** (readonly classes in `app/Adapters/Data/`):
   - `SourcePostData`: `platform (App\Enums\Platform), external_id, url, caption, author_handle, author_display_name, posted_at (?CarbonImmutable), media (MediaDescriptor[]: type, url|null, width, height, duration), raw (array)` — matches 04 §2.
   - `MediaFetchResult`: list of `FetchedMedia { kind: App\Enums\MediaKind, url: ?string, localPath: ?string, mime: ?string }` (yt-dlp adapters return temp paths, HTTP adapters return short-lived URLs).
   - `LinkedAccount`: lightweight token carrier `{ platform, externalUserId, handle, accessToken }` — deliberately NOT the `PlatformAccount` model (that table arrives in T-015); T-015 will map model → DTO.
3. **Exceptions** in `app/Adapters/Exceptions/`: `FetchFailed` (transient — retry/advance chain; carries optional `retryAfter` seconds for 429/`Retry-After`), `PostUnavailable` (permanent — deleted/private-without-auth), `NeedsManualFallback` (chain exhausted — `FetchSourcePost` parks the share in `review` with `review_reason: fetch_failed`). Each carries a `failureCode(): string` mapping into the 04 §8 taxonomy (`fetch_unavailable`, `fetch_auth_required`) for `shares.failure_reason`.
4. **AdapterRegistry** (`app/Adapters/AdapterRegistry.php`):
   - Constructor takes an ordered adapter list per platform from `config/ingestion.php` (`'chains' => ['instagram' => [InstagramAdapter::class, ...], ..., 'fallback' => ManualUploadAdapter::class]`), resolved from the container.
   - `resolve(string $canonicalUrl): array` returns the priority-ordered chain of adapters whose `supports()` returns true, **always appending `ManualUploadAdapter` last** (04 §2: every chain terminates in ManualUpload).
   - `platformFor(string $canonicalUrl): ?Platform` — host/path pattern matching (instagram.com, x.com/twitter.com, tiktok.com/vm.tiktok.com, youtube.com/youtu.be). Unknown host → chain is just `[ManualUploadAdapter]`.
   - Register as a singleton in a new `IngestionServiceProvider`.
5. **ManualUploadAdapter** (`app/Adapters/ManualUploadAdapter.php`), passive terminal adapter per 04 §2 notes:
   - `supports()` → always `true`; `requiresAuth()` → `false`.
   - `fetchMetadata()`: if the source_post has **no user-supplied manual payload yet**, throw `NeedsManualFallback` — this is the signal that flips the share to `review` so the app prompts for caption + screen recording. If a manual payload exists (pasted caption, uploaded `screen_recording` media_asset on the `media` disk from T-009's presigned upload), return `SourcePostData` with `caption` = pasted text, `raw['source'] = 'manual'`, and the post's `fetch_status` destined for `manual` (enum `FetchStatus::Manual`).
   - `fetchMedia()`: returns the user-uploaded `screen_recording` asset as the original media (no download needed — already on the disk).
6. **Unit tests** (`apps/api/tests/Unit/Adapters/`) with fake URLs, no network:
   - Registry maps `https://www.instagram.com/reel/ABC/`, `https://x.com/u/status/1`, `https://vm.tiktok.com/xyz/`, `https://youtu.be/abc` to the right platform; `https://example.com/foo` → manual-only chain.
   - Chain always ends in `ManualUploadAdapter`.
   - `ManualUploadAdapter` throws `NeedsManualFallback` without payload and returns valid `SourcePostData` with one (use `Storage::fake('media')` and a `MediaAsset::factory()->state(['kind' => 'screen_recording'])`).
   - Exception taxonomy: each exception exposes the expected `failureCode()`.

## Acceptance criteria

- [ ] `App\Adapters\SourceAdapter` interface exists with exactly the four methods and signatures from 04 §2 (`supports`, `fetchMetadata`, `fetchMedia`, `requiresAuth`).
- [ ] `SourcePostData`, `MediaFetchResult`, `LinkedAccount` DTOs exist and are used in the signatures; `FetchFailed`, `PostUnavailable`, `NeedsManualFallback` exceptions exist with failure-code mapping.
- [ ] `AdapterRegistry` resolves an ordered adapter chain by URL, driven by `config/ingestion.php`, always terminating in `ManualUploadAdapter`; unknown hosts resolve to manual-only.
- [ ] `ManualUploadAdapter` accepts a user-pasted caption + uploaded screen recording and produces `SourcePostData` (`raw.source = manual`, fetch_status `manual`); with no payload it raises `NeedsManualFallback`.
- [ ] Unit tests with fake URLs cover registry resolution, chain termination, both ManualUploadAdapter modes, and exception codes — no live network anywhere.
- [ ] `pint --test` and `phpstan analyse` pass.

## Verification

```bash
cd apps/api
php artisan test --filter=Adapters
php artisan tinker --execute="
  \$r = app(\App\Adapters\AdapterRegistry::class);
  var_dump(\$r->platformFor('https://www.instagram.com/reel/DAbC123xyz/')?->value);
  var_dump(array_map(fn(\$a) => get_class(\$a), \$r->resolve('https://nonsense.example/x')));
"
# expect: "instagram"; chain for unknown host = [App\Adapters\ManualUploadAdapter]
vendor/bin/pint --test && vendor/bin/phpstan analyse
```

## Gotchas

- **Namespace collision risk**: 04 §2 shows `App\Ingestion` — do not use it; `App\Adapters` is canonical (architecture §6 file tree + tasks.json paths). Keep the method signatures identical regardless.
- Do NOT type-hint `PlatformAccount` (doesn't exist until T-015) — that's why `LinkedAccount` is a DTO. T-015 must not need to change this interface.
- `supports()` must be fast and offline (no HTTP) — it runs during registry resolution for every share.
- URL canonicalization (shortlink expansion, tracking-param strip) is `IngestShare`'s job (T-016), not the registry's; the registry assumes it receives a canonical URL but should still tolerate `www.`/mobile variants in host matching.
- Metadata and media may come from *different* adapters in a chain (04 §2 notes) — that's why `fetchMetadata` and `fetchMedia` are separate and `MediaFetchResult` stands alone. Don't merge them.
- ManualUploadAdapter never *initiates* anything: the state flip to `review` is done by the calling job (T-016) when it catches `NeedsManualFallback`. Keep the adapter side-effect free.

## Log
- **2026-07-09** — Done. **PR #7** (`feat/t012-source-adapters` → `feat/t011-core-migrations`, stacked). All gates green: `composer test` 86 passing / 212 assertions (23 adapter tests), Pint (112 files), PHPStan L6. Tinker verification matches brief (`instagram`; unknown host → `[ManualUploadAdapter]`).
- **Implementation notes**:
  - Canonical namespace `App\Adapters` (not the spec sketch's `App\Ingestion`). DTOs are `final readonly`. `LinkedAccount` is a DTO, not the T-015 model. Chains empty in `config/ingestion.php` until T-013/14/15.
  - Added `AdapterFailure` interface (all 3 exceptions implement it) so T-016 catches one type.
  - **/security-review — High (latent) fix**: `platformFor` originally used `str_contains($host,'instagram.com')`, which misclassifies `instagram.com.evil.com`/`notinstagram.com` as trusted platforms — an SSRF + token-leak surface T-013's adapter would inherit. Now **suffix-anchored** (`$host === $d || str_ends_with($host, '.'.$d)`), with 5 look-alike negative tests. **For T-016**: `source_post` is a shared reference entity (no user_id) — the calling job MUST authorize the user before invoking the chain; add a cross-user test there.
  - **/simplify**: unified ManualUploadAdapter's lookup (was two lookups by url vs platform+external_id) + one `screenRecording()` helper; deduped test setup. Kept `MediaFetchResult` wrapper and `MediaDescriptor.type` string (spec-named; `MediaKind` is spec-locked with no `image` case).
  - Added `@property` annotations to `SourcePost` so Larastan resolves enum casts.
