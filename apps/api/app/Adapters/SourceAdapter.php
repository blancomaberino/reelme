<?php

namespace App\Adapters;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\Exceptions\FetchFailed;
use App\Adapters\Exceptions\PostUnavailable;

/**
 * The ingestion abstraction every platform plugs into (04 §2). Metadata and
 * media are fetched separately because a chain may resolve them from different
 * adapters. Canonical namespace is App\Adapters (architecture §6), not the
 * spec sketch's App\Ingestion.
 */
interface SourceAdapter
{
    /** Fast, offline check — can this adapter handle the canonical URL? No HTTP. */
    public function supports(string $canonicalUrl): bool;

    /**
     * Caption, author, posted_at, media descriptors. MUST NOT download bytes.
     *
     * @throws FetchFailed transient — retry/advance the chain
     * @throws PostUnavailable permanent — deleted / private-without-auth
     */
    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData;

    /** Resolved, short-lived direct media URLs (or local temp paths for yt-dlp). */
    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult;

    /** True if this adapter can only work with a linked platform account. */
    public function requiresAuth(): bool;
}
