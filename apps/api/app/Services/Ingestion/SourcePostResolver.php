<?php

namespace App\Services\Ingestion;

use App\Adapters\AdapterRegistry;
use App\Enums\FetchStatus;
use App\Enums\Platform;
use App\Models\SourcePost;
use App\Services\Places\ExtractionCorrector;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resolves an incoming share to the `source_posts` row the pipeline will work
 * from (T-109), lifted out of `ShareController::store()` where it was ~70 lines
 * of domain logic reachable only through an HTTP request.
 *
 * Three branches, in priority order:
 *
 *  1. **Manual caption** — a pasted caption IS the content. Stored pre-fetched
 *     so `FetchSourcePost` no-ops and the pipeline extracts straight from the
 *     text. The fetch-free demo path.
 *  2. **URL** — canonicalized, gated on platform enablement, then
 *     `firstOrCreate` on `(platform, external_id)` so two people sharing the
 *     same post converge on one row.
 *  3. **Pure manual** — no URL and no caption yet; a placeholder the user will
 *     fill in from the review screen.
 *
 * It never creates the Share or touches dedup: the caller owns auth, the
 * one-share-per-(user, post) guard, the transaction and the response. Same
 * split as {@see ExtractionCorrector} (T-097).
 */
class SourcePostResolver
{
    /** `source_posts.url` is varchar(2048); anything longer is a clean 422. */
    private const MAX_URL_LENGTH = 2048;

    public function __construct(
        private readonly UrlCanonicalizer $canonicalizer,
        private readonly AdapterRegistry $registry,
    ) {}

    /**
     * @param  string|null  $url  a canonical-able URL, or null for a manual share
     * @param  string|null  $hint  client-supplied platform hint (`source_hint`)
     * @param  string|null  $caption  pasted caption; takes priority over `$url`
     *
     * @throws ValidationException on a disabled platform or an over-long URL
     */
    public function resolve(?string $url, ?string $hint, ?string $caption = null): ResolvedSource
    {
        $hintPlatform = $hint !== null ? Platform::tryFrom($hint) : null;

        if ($caption !== null) {
            return $this->fromCaption($url, $caption, $hintPlatform);
        }

        if ($url !== null) {
            return $this->fromUrl($url, $hintPlatform);
        }

        return $this->pureManual($hintPlatform);
    }

    /**
     * Branch 1 — a pasted caption is the content.
     *
     * Each submission mints a fresh `external_id`, so the (user, source_post)
     * dedup guard deliberately cannot fire: resubmitting a caption creates a new
     * run and pin. Acceptable because `ResolvePlace` still dedups the resulting
     * *place* by geo + name.
     */
    private function fromCaption(?string $url, string $caption, ?Platform $hintPlatform): ResolvedSource
    {
        $externalId = 'manual-'.Str::ulid();

        $post = SourcePost::forceCreate([
            'platform' => ($hintPlatform ?? Platform::Instagram)->value,
            'external_id' => $externalId,
            // Any URL is kept only as a reference — the caption is what gets read.
            'url' => $url !== null ? mb_substr($url, 0, self::MAX_URL_LENGTH) : "manual://{$externalId}",
            'caption' => $caption,
            'fetch_status' => FetchStatus::Fetched->value,
            'fetched_at' => now(),
        ]);

        return new ResolvedSource($post, $hintPlatform);
    }

    /** Branch 2 — a real post URL. */
    private function fromUrl(string $url, ?Platform $hintPlatform): ResolvedSource
    {
        $canonical = $this->canonicalizer->canonicalize($url);

        // `url` is validated max:2048 to match source_posts.url, but a URL pulled
        // out of `shared_text` (max:5000) or a shortlink expansion can exceed
        // that — reject cleanly instead of letting Postgres 22001 become a 500.
        //
        // Deliberately still `abort()`, not ValidationException: the two render
        // differently (`http_error` with empty details vs `validation_failed`
        // carrying `details.url`), and this is a no-behavior-change refactor.
        // The ValidationException shape is the better one and every other 422 in
        // the API uses it — recorded as a follow-up in ADR-109 rather than
        // smuggled in here.
        abort_if(mb_strlen($canonical->url) > self::MAX_URL_LENGTH, 422, 'The resolved URL is too long.');

        // Launch gate (T-014): reject a share from a recognised but DISABLED
        // source with a clear message rather than silently parking it for manual
        // upload. Same switch the adapter chain reads — flip
        // ingestion.platforms.<p>.enabled to open a source.
        if ($canonical->platform !== null && ! $this->registry->platformEnabled($canonical->platform)) {
            throw ValidationException::withMessages([
                'url' => "Sharing from {$canonical->platform->label()} isn't available yet — only Instagram is supported right now.",
            ]);
        }

        // An unknown host has no platform, but source_posts.platform is NOT NULL
        // with four fixed values (02 §3.4). We store a placeholder that
        // FetchSourcePost ignores — it re-resolves adapters by URL — and report
        // the REAL (possibly null) platform to the client, so nothing downstream
        // treats the placeholder as fact. See ADR-109 for why this is not simply
        // fixed with a nullable column.
        $stored = $canonical->platform ?? $hintPlatform ?? Platform::Instagram;
        $externalId = $canonical->externalId ?? sha1($canonical->url);

        $post = SourcePost::firstOrCreate(
            ['platform' => $stored, 'external_id' => $externalId],
            ['url' => $canonical->url],
        );

        return new ResolvedSource($post, $canonical->platform);
    }

    /** Branch 3 — no URL and no caption; the user fills it in from review. */
    private function pureManual(?Platform $hintPlatform): ResolvedSource
    {
        $externalId = 'manual-'.Str::ulid();

        $post = SourcePost::forceCreate([
            'platform' => ($hintPlatform ?? Platform::Instagram)->value,
            'external_id' => $externalId,
            'url' => "manual://{$externalId}",
            'fetch_status' => FetchStatus::Manual->value,
        ]);

        return new ResolvedSource($post, null);
    }
}
