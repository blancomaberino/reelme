<?php

namespace Tests\Support;

use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\SourceAdapter;
use Throwable;

/**
 * A SourceAdapter whose fetchMetadata() always throws a preconfigured exception —
 * lets FetchSourcePost tests exercise the chain's failure branches (rate-limit
 * back-off / advance-to-next-adapter) without a real platform adapter. Bind an
 * instance into the container so the registry resolves this configured throw.
 */
class ThrowingAdapter implements SourceAdapter
{
    public function __construct(private readonly Throwable $error) {}

    public function supports(string $canonicalUrl): bool
    {
        return true;
    }

    public function requiresAuth(): bool
    {
        return false;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        throw $this->error;
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        throw $this->error;
    }
}
