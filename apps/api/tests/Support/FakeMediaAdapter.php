<?php

namespace Tests\Support;

use App\Adapters\Data\FetchedMedia;
use App\Adapters\Data\LinkedAccount;
use App\Adapters\Data\MediaFetchResult;
use App\Adapters\Data\SourcePostData;
use App\Adapters\SourceAdapter;

/**
 * A SourceAdapter whose fetchMedia() returns a fixed set of media — lets
 * DownloadMedia tests drive the download path without a real platform adapter.
 */
class FakeMediaAdapter implements SourceAdapter
{
    /**
     * @param  list<FetchedMedia>  $media
     */
    public function __construct(private readonly array $media = []) {}

    public function supports(string $canonicalUrl): bool
    {
        return true;
    }

    public function fetchMetadata(string $canonicalUrl, ?LinkedAccount $account): SourcePostData
    {
        throw new \RuntimeException('not used in media tests');
    }

    public function fetchMedia(SourcePostData $post, ?LinkedAccount $account): MediaFetchResult
    {
        return new MediaFetchResult($this->media);
    }

    public function requiresAuth(): bool
    {
        return false;
    }
}
